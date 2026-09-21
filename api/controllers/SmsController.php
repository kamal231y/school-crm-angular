<?php
class SmsController
{
    /** GET /sms/logs?student_id=&status= */
    public function logs(): void
    {
        Auth::required();
        $where = ['1=1']; $p = [];
        if ($sid = Request::query('student_id')) { $where[] = 'l.student_id = :sid'; $p[':sid'] = (int) $sid; }
        if ($st  = Request::query('status'))     { $where[] = 'l.status = :st';      $p[':st'] = $st; }
        $w = implode(' AND ', $where);

        $rows = Database::all(
            "SELECT l.*, CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) AS student_name, u.name AS sent_by_name
               FROM sms_logs l
               LEFT JOIN students s ON s.id = l.student_id
               LEFT JOIN users u ON u.id = l.sent_by
              WHERE $w ORDER BY l.id DESC LIMIT 200", $p
        );
        Response::ok($rows);
    }

    /** GET /sms/templates */
    public function templates(): void
    {
        Auth::required();
        Response::ok(Database::all('SELECT * FROM sms_templates ORDER BY id'));
    }

    /** PUT /sms/templates/{id} */
    public function updateTemplate(string $id): void
    {
        Auth::can('admin');
        $d = Request::body();
        (new Validator($d))->check(['body' => 'required|max:480'])->stopOnFail();
        Database::update('sms_templates', (int) $id, [
            'body'   => $d['body'],
            'active' => isset($d['active']) ? (int) (bool) $d['active'] : 1,
        ]);
        Response::ok(null, 'Template updated');
    }

    /**
     * POST /sms/send
     * body: { message, student_ids: [], class_id, audience: 'class'|'selected'|'defaulters' }
     */
    public function send(): void
    {
        Auth::can('admin', 'teacher', 'accountant');
        $d = Request::body();

        (new Validator($d))->check(['message' => 'required|min:5|max:480'])->stopOnFail();

        $audience = $d['audience'] ?? 'selected';
        $targets  = [];

        if ($audience === 'class' && !empty($d['class_id'])) {
            $targets = Database::all(
                'SELECT id, guardian_phone, first_name, last_name, father_name
                   FROM students WHERE class_id = ? AND status = "active"', [(int) $d['class_id']]
            );
        } elseif ($audience === 'defaulters') {
            $targets = Database::all(
                'SELECT s.id, s.guardian_phone, s.first_name, s.last_name, s.father_name
                   FROM students s JOIN fee_invoices i ON i.student_id = s.id
                  WHERE i.status IN ("unpaid","partial") AND i.due_date < CURDATE() AND s.status = "active"
                  GROUP BY s.id, s.guardian_phone, s.first_name, s.last_name, s.father_name'
            );
        } elseif (!empty($d['student_ids']) && is_array($d['student_ids'])) {
            $ids = array_map('intval', $d['student_ids']);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $targets = Database::all(
                "SELECT id, guardian_phone, first_name, last_name, father_name
                   FROM students WHERE id IN ($in)", $ids
            );
        }

        if (!$targets) {
            Response::error('No recipients matched. Pick a class or select students.', 422);
        }

        $sent = 0; $failed = 0;
        foreach ($targets as $t) {
            $msg = str_replace(
                ['{name}', '{father}'],
                [trim($t['first_name'] . ' ' . $t['last_name']), $t['father_name']],
                $d['message']
            );
            $res = Sms::send($t['guardian_phone'], $msg, 'manual', (int) $t['id']);
            $res['status'] === 'sent' ? $sent++ : $failed++;
        }

        Auth::log('sms_bulk', "$sent sent, $failed failed");
        Response::ok(['sent' => $sent, 'failed' => $failed], "$sent messages sent" . ($failed ? ", $failed failed" : ''));
    }
}
