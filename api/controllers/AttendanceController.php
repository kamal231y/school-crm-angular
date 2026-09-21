<?php
class AttendanceController
{
    /** GET /attendance/sheet?class_id=&date= - class ki daily sheet */
    public function sheet(): void
    {
        Auth::required();
        $classId = (int) Request::query('class_id');
        $date    = Request::query('date', date('Y-m-d'));

        if (!$classId) {
            Response::error('Select a class first', 422);
        }

        $rows = Database::all(
            'SELECT s.id AS student_id, s.admission_no, s.roll_no,
                    CONCAT(s.first_name," ",COALESCE(s.last_name,"")) AS name,
                    s.guardian_phone,
                    COALESCE(a.status,"present") AS status,
                    a.remarks, a.id AS attendance_id
               FROM students s
               LEFT JOIN attendance a ON a.student_id = s.id AND a.att_date = :d
              WHERE s.class_id = :c AND s.status = "active"
              ORDER BY CAST(s.roll_no AS UNSIGNED), s.first_name',
            [':d' => $date, ':c' => $classId]
        );

        Response::ok([
            'date'   => $date,
            'marked' => (bool) Database::scalar('SELECT COUNT(*) FROM attendance WHERE class_id = ? AND att_date = ?', [$classId, $date]),
            'rows'   => $rows,
        ]);
    }

    /**
     * POST /attendance
     * body: { class_id, date, notify_absent: true, rows: [{student_id, status, remarks}] }
     */
    public function store(): void
    {
        $user = Auth::can('admin', 'teacher');
        $d    = Request::body();

        $classId = (int) ($d['class_id'] ?? 0);
        $date    = $d['date'] ?? date('Y-m-d');
        $rows    = $d['rows'] ?? [];

        if (!$classId || !is_array($rows) || !$rows) {
            Response::error('Class and attendance rows are required', 422);
        }
        if (strtotime($date) > strtotime(date('Y-m-d'))) {
            Response::error('Attendance cannot be marked for a future date', 422);
        }

        $pdo = Database::conn();
        $pdo->beginTransaction();

        $saved = 0;
        $absentees = [];

        try {
            foreach ($rows as $r) {
                $sid    = (int) ($r['student_id'] ?? 0);
                $status = $r['status'] ?? 'present';
                if (!$sid || !in_array($status, ['present','absent','late','leave','holiday'], true)) {
                    continue;
                }

                $st = $pdo->prepare(
                    'INSERT INTO attendance (student_id, class_id, att_date, status, remarks, marked_by)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), remarks = VALUES(remarks), marked_by = VALUES(marked_by)'
                );
                $st->execute([$sid, $classId, $date, $status, $r['remarks'] ?? null, $user['id']]);
                $saved++;

                if ($status === 'absent') {
                    $absentees[] = $sid;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::error('Could not save attendance: ' . $e->getMessage(), 500);
        }

        // Absent parents ko SMS
        $smsCount = 0;
        if (!empty($d['notify_absent']) && $absentees) {
            $in = implode(',', array_fill(0, count($absentees), '?'));
            $list = Database::all(
                "SELECT s.id, s.guardian_phone, s.father_name,
                        CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) AS name,
                        CONCAT(c.name,' - ',c.section) AS class_name
                   FROM students s LEFT JOIN classes c ON c.id = s.class_id
                  WHERE s.id IN ($in)", $absentees
            );
            foreach ($list as $s) {
                $res = Sms::sendTemplate('absent', $s['guardian_phone'], [
                    'name'   => $s['name'],
                    'father' => $s['father_name'],
                    'class'  => $s['class_name'],
                    'date'   => date('d-m-Y', strtotime($date)),
                ], (int) $s['id']);
                if ($res['status'] === 'sent') {
                    $smsCount++;
                }
            }
        }

        Auth::log('attendance', "Class $classId on $date ($saved rows)");
        Response::ok(
            ['saved' => $saved, 'absent' => count($absentees), 'sms_sent' => $smsCount],
            "Attendance saved for $saved students" . ($smsCount ? ", $smsCount SMS sent" : '')
        );
    }

    /** GET /attendance/monthly?class_id=&month=YYYY-MM - register view */
    public function monthly(): void
    {
        Auth::required();
        $classId = (int) Request::query('class_id');
        $month   = Request::query('month', date('Y-m'));
        $from    = $month . '-01';
        $to      = date('Y-m-t', strtotime($from));

        if (!$classId) {
            Response::error('Select a class first', 422);
        }

        $students = Database::all(
            'SELECT id, roll_no, admission_no, CONCAT(first_name," ",COALESCE(last_name,"")) AS name
               FROM students WHERE class_id = ? AND status = "active"
              ORDER BY CAST(roll_no AS UNSIGNED), first_name', [$classId]
        );

        $marks = Database::all(
            'SELECT student_id, att_date, status FROM attendance
              WHERE class_id = ? AND att_date BETWEEN ? AND ?', [$classId, $from, $to]
        );

        $map = [];
        foreach ($marks as $m) {
            $map[$m['student_id']][(int) date('j', strtotime($m['att_date']))] = $m['status'];
        }

        foreach ($students as &$s) {
            $days    = $map[$s['id']] ?? [];
            $present = count(array_filter($days, fn($v) => $v === 'present' || $v === 'late'));
            $total   = count(array_filter($days, fn($v) => $v !== 'holiday'));
            $s['days']       = $days;
            $s['present']    = $present;
            $s['working']    = $total;
            $s['percentage'] = $total ? round($present * 100 / $total, 1) : 0;
        }

        Response::ok([
            'month'      => $month,
            'days_in_month' => (int) date('t', strtotime($from)),
            'students'   => $students,
        ]);
    }
}
