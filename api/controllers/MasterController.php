<?php
/** Classes, subjects, fee heads aur settings ke liye master data. */
class MasterController
{
    public function classes(): void
    {
        Auth::required();
        Response::ok(Database::all(
            'SELECT c.*, CONCAT(c.name," - ",c.section) AS label, u.name AS teacher_name,
                    (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = "active") AS student_count
               FROM classes c LEFT JOIN users u ON u.id = c.teacher_id ORDER BY c.name, c.section'
        ));
    }

    public function storeClass(): void
    {
        Auth::can('admin');
        $d = Request::body();
        (new Validator($d))->check([
            'name'    => 'required|max:40',
            'section' => 'required|max:10',
        ])->stopOnFail();

        $id = Database::insert('classes', [
            'name'       => $d['name'],
            'section'    => $d['section'],
            'session'    => $d['session'] ?? Config::setting('session', date('Y')),
            'teacher_id' => !empty($d['teacher_id']) ? (int) $d['teacher_id'] : null,
        ]);
        Response::ok(['id' => $id], 'Class added');
    }

    public function subjects(): void
    {
        Auth::required();
        $cid = Request::query('class_id');
        $sql = 'SELECT * FROM subjects';
        $p   = [];
        if ($cid) { $sql .= ' WHERE class_id = ?'; $p[] = (int) $cid; }
        Response::ok(Database::all($sql . ' ORDER BY class_id, id', $p));
    }

    public function storeSubject(): void
    {
        Auth::can('admin');
        $d = Request::body();
        (new Validator($d))->check([
            'class_id'  => 'required|numeric',
            'name'      => 'required|max:60',
            'max_marks' => 'numeric',
        ])->stopOnFail();

        $id = Database::insert('subjects', [
            'class_id'   => (int) $d['class_id'],
            'name'       => $d['name'],
            'code'       => $d['code'] ?? null,
            'max_marks'  => (int) ($d['max_marks'] ?? 100),
            'pass_marks' => (int) ($d['pass_marks'] ?? 33),
        ]);
        Response::ok(['id' => $id], 'Subject added');
    }

    public function feeHeads(): void
    {
        Auth::required();
        Response::ok(Database::all(
            'SELECT f.*, CONCAT(c.name," - ",c.section) AS class_name
               FROM fee_heads f LEFT JOIN classes c ON c.id = f.class_id ORDER BY f.id'
        ));
    }

    public function storeFeeHead(): void
    {
        Auth::can('admin', 'accountant');
        $d = Request::body();
        (new Validator($d))->check([
            'title'  => 'required|max:80',
            'amount' => 'required|numeric',
        ])->stopOnFail();

        $id = Database::insert('fee_heads', [
            'class_id'  => !empty($d['class_id']) ? (int) $d['class_id'] : null,
            'title'     => $d['title'],
            'amount'    => (float) $d['amount'],
            'frequency' => $d['frequency'] ?? 'monthly',
        ]);
        Response::ok(['id' => $id], 'Fee head added');
    }

    public function users(): void
    {
        Auth::can('admin');
        Response::ok(Database::all('SELECT id, name, email, phone, role, status, last_login_at FROM users ORDER BY id'));
    }

    public function settings(): void
    {
        Auth::required();
        $rows = Database::all('SELECT skey, svalue FROM settings');
        Response::ok(array_column($rows, 'svalue', 'skey'));
    }
}
