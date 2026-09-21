<?php
class ReportController
{
    /** GET /dashboard */
    public function dashboard(): void
    {
        Auth::required();
        $today = date('Y-m-d');

        $students = Database::one(
            'SELECT COUNT(*) AS total,
                    SUM(status = "active") AS active,
                    SUM(gender = "male" AND status = "active") AS boys,
                    SUM(gender = "female" AND status = "active") AS girls,
                    SUM(admission_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS new_30d
               FROM students'
        );

        $att = Database::one(
            'SELECT COUNT(*) AS marked,
                    SUM(status IN ("present","late")) AS present,
                    SUM(status = "absent") AS absent
               FROM attendance WHERE att_date = ?', [$today]
        );

        $fees = Database::one(
            'SELECT COALESCE(SUM(amount - discount),0) AS billed,
                    COALESCE(SUM(paid_amount),0) AS collected
               FROM fee_invoices WHERE status <> "cancelled"'
        );

        $collectedToday = (float) Database::scalar('SELECT COALESCE(SUM(amount),0) FROM fee_payments WHERE paid_on = ?', [$today]);

        // GROUP BY me wahi expressions hone chahiye jo SELECT me hain,
        // warna MySQL 8 / MariaDB ka ONLY_FULL_GROUP_BY mode error deta hai.
        $trend = Database::all(
            'SELECT DATE_FORMAT(paid_on, "%b %Y") AS label,
                    DATE_FORMAT(paid_on, "%Y-%m") AS sort_key,
                    SUM(amount) AS amount
               FROM fee_payments
              WHERE paid_on >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
              GROUP BY DATE_FORMAT(paid_on, "%b %Y"), DATE_FORMAT(paid_on, "%Y-%m")
              ORDER BY sort_key'
        );

        $classwise = Database::all(
            'SELECT CONCAT(c.name," - ",c.section) AS class_name, COUNT(s.id) AS students
               FROM classes c LEFT JOIN students s ON s.class_id = c.id AND s.status = "active"
              GROUP BY c.id, c.name, c.section ORDER BY c.name'
        );

        Response::ok([
            'students'        => $students,
            'attendance'      => [
                'marked'     => (int) $att['marked'],
                'present'    => (int) $att['present'],
                'absent'     => (int) $att['absent'],
                'percentage' => $att['marked'] ? round($att['present'] * 100 / $att['marked'], 1) : 0,
            ],
            'fees'            => [
                'billed'    => (float) $fees['billed'],
                'collected' => (float) $fees['collected'],
                'pending'   => (float) $fees['billed'] - (float) $fees['collected'],
                'today'     => $collectedToday,
            ],
            'collection_trend' => $trend,
            'classwise'        => $classwise,
            'sms_this_month'   => (int) Database::scalar('SELECT COUNT(*) FROM sms_logs WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())'),
        ]);
    }

    /** GET /reports/students?class_id=&from=&to=&gender= - student register report */
    public function students(): void
    {
        Auth::required();
        $where = ['1=1']; $p = [];
        if ($c = Request::query('class_id')) { $where[] = 's.class_id = :c'; $p[':c'] = (int) $c; }
        if ($g = Request::query('gender'))   { $where[] = 's.gender = :g';   $p[':g'] = $g; }
        if ($f = Request::query('from'))     { $where[] = 's.admission_date >= :f'; $p[':f'] = $f; }
        if ($t = Request::query('to'))       { $where[] = 's.admission_date <= :t'; $p[':t'] = $t; }
        if ($st = Request::query('status'))  { $where[] = 's.status = :st';  $p[':st'] = $st; }
        $w = implode(' AND ', $where);

        $rows = Database::all(
            "SELECT s.id, s.admission_no, s.roll_no,
                    CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) AS name,
                    s.gender, s.dob, s.father_name, s.guardian_phone, s.admission_date, s.status,
                    CONCAT(c.name,' - ',c.section) AS class_name,
                    COALESCE(f.billed,0) - COALESCE(f.paid,0) AS fee_due,
                    COALESCE(a.pct, 0) AS attendance_pct
               FROM students s
               LEFT JOIN classes c ON c.id = s.class_id
               LEFT JOIN (SELECT student_id, SUM(amount - discount) billed, SUM(paid_amount) paid
                            FROM fee_invoices WHERE status <> 'cancelled' GROUP BY student_id) f ON f.student_id = s.id
               LEFT JOIN (SELECT student_id, ROUND(SUM(status IN ('present','late'))*100/COUNT(*),1) pct
                            FROM attendance GROUP BY student_id) a ON a.student_id = s.id
              WHERE $w ORDER BY c.name, CAST(s.roll_no AS UNSIGNED), s.first_name", $p
        );

        Response::ok([
            'rows'  => $rows,
            'count' => count($rows),
            'generated_at' => date('d-m-Y H:i'),
        ]);
    }

    /** GET /reports/attendance?class_id=&from=&to= */
    public function attendance(): void
    {
        Auth::required();
        $from = Request::query('from', date('Y-m-01'));
        $to   = Request::query('to', date('Y-m-d'));
        $cid  = Request::query('class_id');

        $sql = "SELECT s.id, s.admission_no, s.roll_no,
                       CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) AS name,
                       CONCAT(c.name,' - ',c.section) AS class_name,
                       COUNT(a.id) AS working_days,
                       SUM(a.status = 'present') AS present,
                       SUM(a.status = 'absent')  AS absent,
                       SUM(a.status = 'late')    AS late,
                       SUM(a.status = 'leave')   AS on_leave,
                       ROUND(SUM(a.status IN ('present','late')) * 100 / NULLIF(COUNT(a.id),0), 1) AS percentage
                  FROM students s
                  LEFT JOIN classes c ON c.id = s.class_id
                  LEFT JOIN attendance a ON a.student_id = s.id AND a.att_date BETWEEN :f AND :t
                 WHERE s.status = 'active'";
        $p = [':f' => $from, ':t' => $to];
        if ($cid) { $sql .= ' AND s.class_id = :c'; $p[':c'] = (int) $cid; }
        $sql .= ' GROUP BY s.id, s.admission_no, s.roll_no, s.first_name, s.last_name,
                           c.name, c.section
                  ORDER BY percentage ASC';

        Response::ok(['from' => $from, 'to' => $to, 'rows' => Database::all($sql, $p)]);
    }

    /** GET /reports/fees?from=&to=&mode= - collection report */
    public function fees(): void
    {
        Auth::required();
        $from = Request::query('from', date('Y-m-01'));
        $to   = Request::query('to', date('Y-m-d'));

        $rows = Database::all(
            "SELECT p.receipt_no, p.amount, p.mode, p.paid_on, p.txn_ref,
                    i.title, i.invoice_no,
                    CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) AS student_name,
                    s.admission_no, CONCAT(c.name,' - ',c.section) AS class_name,
                    u.name AS received_by_name
               FROM fee_payments p
               JOIN fee_invoices i ON i.id = p.invoice_id
               JOIN students s ON s.id = p.student_id
               LEFT JOIN classes c ON c.id = s.class_id
               LEFT JOIN users u ON u.id = p.received_by
              WHERE p.paid_on BETWEEN ? AND ?
              ORDER BY p.paid_on DESC, p.id DESC", [$from, $to]
        );

        $byMode = Database::all(
            'SELECT mode, COUNT(*) AS count, SUM(amount) AS total
               FROM fee_payments WHERE paid_on BETWEEN ? AND ? GROUP BY mode', [$from, $to]
        );

        Response::ok([
            'from' => $from, 'to' => $to,
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'amount')),
            'by_mode' => $byMode,
        ]);
    }
}
