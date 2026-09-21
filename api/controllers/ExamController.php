<?php
class ExamController
{
    /** GET /exams?class_id= */
    public function index(): void
    {
        Auth::required();
        $cid  = Request::query('class_id');
        $sql  = "SELECT e.*, CONCAT(c.name,' - ',c.section) AS class_name
                   FROM exams e JOIN classes c ON c.id = e.class_id";
        $args = [];
        if ($cid) { $sql .= ' WHERE e.class_id = ?'; $args[] = (int) $cid; }
        $sql .= ' ORDER BY e.exam_date DESC';
        Response::ok(Database::all($sql, $args));
    }

    /** POST /exams */
    public function store(): void
    {
        Auth::can('admin', 'teacher');
        $d = Request::body();
        (new Validator($d))->check([
            'name'      => 'required|max:80',
            'class_id'  => 'required|numeric',
            'exam_date' => 'date',
        ])->stopOnFail();

        $id = Database::insert('exams', [
            'name'      => $d['name'],
            'session'   => $d['session'] ?? Config::setting('session', date('Y')),
            'class_id'  => (int) $d['class_id'],
            'exam_date' => $d['exam_date'] ?? null,
        ]);
        Response::ok(['id' => $id], 'Exam created');
    }

    /** GET /exams/{id}/marks - marks entry grid */
    public function marksSheet(string $id): void
    {
        Auth::required();
        $examId = (int) $id;
        $exam = Database::one("SELECT e.*, CONCAT(c.name,' - ',c.section) AS class_name FROM exams e JOIN classes c ON c.id = e.class_id WHERE e.id = ?", [$examId]);
        if (!$exam) Response::error('Exam not found', 404);

        $subjects = Database::all('SELECT * FROM subjects WHERE class_id = ? ORDER BY id', [(int) $exam['class_id']]);
        $students = Database::all(
            'SELECT id, roll_no, admission_no, CONCAT(first_name," ",COALESCE(last_name,"")) AS name
               FROM students WHERE class_id = ? AND status = "active"
              ORDER BY CAST(roll_no AS UNSIGNED), first_name', [(int) $exam['class_id']]
        );
        $marks = Database::all('SELECT student_id, subject_id, marks_obtained FROM marks WHERE exam_id = ?', [$examId]);

        $map = [];
        foreach ($marks as $m) {
            $map[$m['student_id']][$m['subject_id']] = (float) $m['marks_obtained'];
        }
        foreach ($students as &$s) {
            $s['marks'] = $map[$s['id']] ?? new stdClass();
        }

        Response::ok(['exam' => $exam, 'subjects' => $subjects, 'students' => $students]);
    }

    /** POST /exams/{id}/marks  body: { rows: [{student_id, marks: {subject_id: value}}] } */
    public function saveMarks(string $id): void
    {
        $user   = Auth::can('admin', 'teacher');
        $examId = (int) $id;
        $rows   = Request::input('rows', []);

        if (!is_array($rows) || !$rows) {
            Response::error('No marks to save', 422);
        }

        $maxMap = [];
        foreach (Database::all('SELECT s.id, s.max_marks FROM subjects s JOIN exams e ON e.class_id = s.class_id WHERE e.id = ?', [$examId]) as $r) {
            $maxMap[(int) $r['id']] = (float) $r['max_marks'];
        }

        $pdo = Database::conn();
        $pdo->beginTransaction();
        $saved = 0;

        try {
            $st = $pdo->prepare(
                'INSERT INTO marks (exam_id, student_id, subject_id, marks_obtained, entered_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE marks_obtained = VALUES(marks_obtained), entered_by = VALUES(entered_by)'
            );
            foreach ($rows as $row) {
                $sid = (int) ($row['student_id'] ?? 0);
                foreach ((array) ($row['marks'] ?? []) as $subjectId => $value) {
                    if ($value === '' || $value === null) continue;
                    $subjectId = (int) $subjectId;
                    $value     = (float) $value;
                    $max       = $maxMap[$subjectId] ?? 100;
                    if ($value < 0 || $value > $max) {
                        $pdo->rollBack();
                        Response::error("Marks must be between 0 and $max", 422);
                    }
                    $st->execute([$examId, $sid, $subjectId, $value, $user['id']]);
                    $saved++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::error('Could not save marks: ' . $e->getMessage(), 500);
        }

        Auth::log('marks_entry', "Exam #$examId ($saved entries)");
        Response::ok(['saved' => $saved], "$saved marks saved");
    }

    /** GET /report-card/{studentId}?exam_id= */
    public function reportCard(string $studentId): void
    {
        Auth::required();
        $sid    = (int) $studentId;
        $examId = (int) Request::query('exam_id');

        $student = Database::one(
            "SELECT s.*, CONCAT(c.name,' - ',c.section) AS class_name
               FROM students s LEFT JOIN classes c ON c.id = s.class_id WHERE s.id = ?", [$sid]
        );
        if (!$student) Response::error('Student not found', 404);

        $exam = Database::one('SELECT * FROM exams WHERE id = ?', [$examId]);
        if (!$exam) Response::error('Exam not found', 404);

        $subjects = Database::all(
            'SELECT sub.id, sub.name, sub.code, sub.max_marks, sub.pass_marks,
                    COALESCE(m.marks_obtained, 0) AS obtained,
                    (m.id IS NOT NULL) AS entered
               FROM subjects sub
               LEFT JOIN marks m ON m.subject_id = sub.id AND m.student_id = ? AND m.exam_id = ?
              WHERE sub.class_id = ? ORDER BY sub.id',
            [$sid, $examId, (int) $exam['class_id']]
        );

        $totalMax = 0; $totalObt = 0; $failed = 0;
        foreach ($subjects as &$s) {
            $s['obtained']  = (float) $s['obtained'];
            $s['max_marks'] = (int) $s['max_marks'];
            $s['grade']     = self::grade($s['max_marks'] ? $s['obtained'] * 100 / $s['max_marks'] : 0);
            $s['result']    = $s['obtained'] >= $s['pass_marks'] ? 'Pass' : 'Fail';
            if ($s['result'] === 'Fail') $failed++;
            $totalMax += $s['max_marks'];
            $totalObt += $s['obtained'];
        }

        $percentage = $totalMax ? round($totalObt * 100 / $totalMax, 2) : 0;

        // Class rank
        $ranking = Database::all(
            'SELECT m.student_id, SUM(m.marks_obtained) AS total
               FROM marks m WHERE m.exam_id = ? GROUP BY m.student_id ORDER BY total DESC', [$examId]
        );
        $rank = 0;
        foreach ($ranking as $i => $r) {
            if ((int) $r['student_id'] === $sid) { $rank = $i + 1; break; }
        }

        $att = Database::one(
            'SELECT COUNT(*) AS total, SUM(status IN ("present","late")) AS present
               FROM attendance WHERE student_id = ?', [$sid]
        );

        Response::ok([
            'school'     => ['name' => Config::setting('school_name'), 'address' => Config::setting('school_address')],
            'student'    => $student,
            'exam'       => $exam,
            'subjects'   => $subjects,
            'summary'    => [
                'total_max'  => $totalMax,
                'obtained'   => $totalObt,
                'percentage' => $percentage,
                'grade'      => self::grade($percentage),
                'result'     => $failed === 0 ? 'PASS' : 'FAIL',
                'rank'       => $rank,
                'out_of'     => count($ranking),
                'attendance' => $att['total'] ? round($att['present'] * 100 / $att['total'], 1) : 0,
            ],
        ]);
    }

    /** POST /exams/{id}/publish - result SMS to all parents */
    public function publish(string $id): void
    {
        Auth::can('admin');
        $examId = (int) $id;
        $exam   = Database::one('SELECT * FROM exams WHERE id = ?', [$examId]);
        if (!$exam) Response::error('Exam not found', 404);

        $totalMax = (float) Database::scalar('SELECT COALESCE(SUM(max_marks),0) FROM subjects WHERE class_id = ?', [(int) $exam['class_id']]);
        // Pass/fail report card ki tarah subject level par tay hota hai,
        // sirf overall percentage par nahi — warna dono jagah alag natija aata hai.
        $rows = Database::all(
            "SELECT m.student_id,
                    SUM(m.marks_obtained) AS obtained,
                    SUM(m.marks_obtained < sub.pass_marks) AS failed_subjects,
                    s.guardian_phone, s.father_name,
                    CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) AS name
               FROM marks m
               JOIN students s   ON s.id = m.student_id
               JOIN subjects sub ON sub.id = m.subject_id
              WHERE m.exam_id = ?
              GROUP BY m.student_id, s.guardian_phone, s.father_name, s.first_name, s.last_name", [$examId]
        );

        $sent = 0;
        foreach ($rows as $r) {
            $pct = $totalMax ? round((float) $r['obtained'] * 100 / $totalMax, 2) : 0;
            $res = Sms::sendTemplate('result', $r['guardian_phone'], [
                'name'       => $r['name'],
                'father'     => $r['father_name'],
                'exam'       => $exam['name'],
                'percentage' => $pct,
                'grade'      => self::grade($pct),
                'result'     => ((int) $r['failed_subjects'] === 0) ? 'PASS' : 'FAIL',
            ], (int) $r['student_id']);
            if ($res['status'] === 'sent') $sent++;
        }

        Database::update('exams', $examId, ['published' => 1]);
        Auth::log('exam_publish', "Exam #$examId, $sent SMS");
        Response::ok(['sms_sent' => $sent], "Result published. $sent parents notified by SMS.");
    }

    public static function grade(float $pct): string
    {
        return match (true) {
            $pct >= 91 => 'A1',
            $pct >= 81 => 'A2',
            $pct >= 71 => 'B1',
            $pct >= 61 => 'B2',
            $pct >= 51 => 'C1',
            $pct >= 41 => 'C2',
            $pct >= 33 => 'D',
            default    => 'E',
        };
    }
}
