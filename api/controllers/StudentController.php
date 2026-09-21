<?php
class StudentController
{
    /** GET /students?search=&class_id=&status=&page=&per_page= */
    public function index(): void
    {
        Auth::required();

        $where  = ['1=1'];
        $params = [];

        if ($s = Request::query('search')) {
            $where[] = '(s.first_name LIKE :s OR s.last_name LIKE :s OR s.admission_no LIKE :s OR s.guardian_phone LIKE :s OR s.father_name LIKE :s)';
            $params[':s'] = '%' . $s . '%';
        }
        if ($c = Request::query('class_id')) {
            $where[] = 's.class_id = :c';
            $params[':c'] = (int) $c;
        }
        if ($st = Request::query('status')) {
            $where[] = 's.status = :st';
            $params[':st'] = $st;
        }

        $w        = implode(' AND ', $where);
        $page     = max(1, (int) Request::query('page', 1));
        $perPage  = min(100, max(5, (int) Request::query('per_page', 15)));
        $offset   = ($page - 1) * $perPage;

        $total = (int) Database::scalar("SELECT COUNT(*) FROM students s WHERE $w", $params);

        $rows = Database::all(
            "SELECT s.*, CONCAT(c.name,' - ',c.section) AS class_name
               FROM students s
               LEFT JOIN classes c ON c.id = s.class_id
              WHERE $w
              ORDER BY s.id DESC
              LIMIT $perPage OFFSET $offset",
            $params
        );

        Response::paginated($rows, $total, $page, $perPage);
    }

    /** GET /students/{id} - full profile with fees, attendance, results */
    public function show(string $id): void
    {
        Auth::required();
        $id = (int) $id;

        $student = Database::one(
            "SELECT s.*, CONCAT(c.name,' - ',c.section) AS class_name
               FROM students s LEFT JOIN classes c ON c.id = s.class_id
              WHERE s.id = ?", [$id]
        );
        if (!$student) {
            Response::error('Student not found', 404);
        }

        $fees = Database::one(
            'SELECT COALESCE(SUM(amount - discount),0) AS billed,
                    COALESCE(SUM(paid_amount),0)       AS paid
               FROM fee_invoices WHERE student_id = ? AND status <> "cancelled"', [$id]
        );

        $att = Database::one(
            'SELECT COUNT(*) AS total,
                    SUM(status = "present") AS present,
                    SUM(status = "absent")  AS absent,
                    SUM(status = "late")    AS late
               FROM attendance WHERE student_id = ?', [$id]
        );

        $exams = Database::all(
            'SELECT e.id, e.name, e.exam_date, e.published,
                    SUM(m.marks_obtained) AS obtained, SUM(sub.max_marks) AS total
               FROM marks m
               JOIN exams e   ON e.id = m.exam_id
               JOIN subjects sub ON sub.id = m.subject_id
              WHERE m.student_id = ?
              GROUP BY e.id, e.name, e.exam_date, e.published
              ORDER BY e.exam_date DESC', [$id]
        );

        Response::ok([
            'student'    => $student,
            'fees'       => [
                'billed'  => (float) $fees['billed'],
                'paid'    => (float) $fees['paid'],
                'balance' => (float) $fees['billed'] - (float) $fees['paid'],
            ],
            'attendance' => [
                'total'      => (int) $att['total'],
                'present'    => (int) $att['present'],
                'absent'     => (int) $att['absent'],
                'late'       => (int) $att['late'],
                'percentage' => $att['total'] > 0 ? round(($att['present'] + $att['late']) * 100 / $att['total'], 1) : 0,
            ],
            'exams'      => $exams,
            'recent_sms' => Database::all('SELECT * FROM sms_logs WHERE student_id = ? ORDER BY id DESC LIMIT 5', [$id]),
        ]);
    }

    /** POST /students - new admission */
    public function store(): void
    {
        Auth::can('admin', 'teacher');
        $d = Request::body();

        (new Validator($d))->check([
            'first_name'     => 'required|min:2|max:60',
            'last_name'      => 'max:60',
            'gender'         => 'required|in:male,female,other',
            'dob'            => 'date',
            'class_id'       => 'required|numeric',
            'father_name'    => 'required|max:100',
            'guardian_phone' => 'required|phone',
            'email'          => 'email',
            'admission_date' => 'required|date',
        ])->stopOnFail();

        $admissionNo = $this->nextAdmissionNo();

        $id = Database::insert('students', [
            'admission_no'   => $admissionNo,
            'roll_no'        => $d['roll_no'] ?? null,
            'first_name'     => $d['first_name'],
            'last_name'      => $d['last_name'] ?? null,
            'gender'         => $d['gender'],
            'dob'            => $d['dob'] ?? null,
            'class_id'       => (int) $d['class_id'],
            'father_name'    => $d['father_name'],
            'mother_name'    => $d['mother_name'] ?? null,
            'guardian_phone' => preg_replace('/\D/', '', $d['guardian_phone']),
            'email'          => $d['email'] ?? null,
            'address'        => $d['address'] ?? null,
            'category'       => $d['category'] ?? null,
            'admission_date' => $d['admission_date'],
            'status'         => 'active',
            'created_by'     => Auth::user()['id'],
        ]);

        $class = Database::one("SELECT CONCAT(name,' - ',section) AS n FROM classes WHERE id = ?", [(int) $d['class_id']]);

        // Admission confirmation SMS
        Sms::sendTemplate('admission', $d['guardian_phone'], [
            'name'         => trim($d['first_name'] . ' ' . ($d['last_name'] ?? '')),
            'father'       => $d['father_name'],
            'class'        => $class['n'] ?? '',
            'admission_no' => $admissionNo,
        ], $id);

        // Auto-generate first fee invoices from the class fee heads
        FeeController::generateForStudent($id, (int) $d['class_id']);

        Auth::log('admission', "Student #$id ($admissionNo)");
        Response::ok(['id' => $id, 'admission_no' => $admissionNo], 'Admission saved. Confirmation SMS sent.');
    }

    /** PUT /students/{id} */
    public function update(string $id): void
    {
        Auth::can('admin', 'teacher');
        $id = (int) $id;
        $d  = Request::body();

        if (!Database::one('SELECT id FROM students WHERE id = ?', [$id])) {
            Response::error('Student not found', 404);
        }

        (new Validator($d))->check([
            'first_name'     => 'required|min:2|max:60',
            'guardian_phone' => 'required|phone',
            'email'          => 'email',
            'status'         => 'in:active,left,alumni',
        ])->stopOnFail();

        $fields = ['roll_no','first_name','last_name','gender','dob','class_id','father_name',
                   'mother_name','guardian_phone','email','address','category','status'];
        $set = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $d)) {
                $set[$f] = $d[$f] === '' ? null : $d[$f];
            }
        }
        if ($set) {
            Database::update('students', $id, $set);
        }

        Auth::log('student_update', "Student #$id");
        Response::ok(null, 'Student details updated');
    }

    /** DELETE /students/{id} - soft delete (status = left) */
    public function destroy(string $id): void
    {
        Auth::can('admin');
        Database::update('students', (int) $id, ['status' => 'left']);
        Auth::log('student_archive', "Student #$id");
        Response::ok(null, 'Student moved to left/inactive');
    }

    private function nextAdmissionNo(): string
    {
        $year = date('Y');
        $last = Database::scalar(
            'SELECT admission_no FROM students WHERE admission_no LIKE ? ORDER BY id DESC LIMIT 1',
            ['ADM' . $year . '%']
        );
        $seq = $last ? ((int) substr($last, -3)) + 1 : 1;
        return 'ADM' . $year . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
