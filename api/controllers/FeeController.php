<?php
class FeeController
{
    /** GET /fees/invoices?student_id=&status=&class_id= */
    public function invoices(): void
    {
        Auth::required();

        $where = ['1=1']; $params = [];
        if ($sid = Request::query('student_id')) { $where[] = 'i.student_id = :sid'; $params[':sid'] = (int) $sid; }
        if ($st  = Request::query('status'))     { $where[] = 'i.status = :st';      $params[':st']  = $st; }
        if ($cid = Request::query('class_id'))   { $where[] = 's.class_id = :cid';   $params[':cid'] = (int) $cid; }
        $w = implode(' AND ', $where);

        $rows = Database::all(
            "SELECT i.*, CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) AS student_name,
                    s.admission_no, s.guardian_phone, CONCAT(c.name,' - ',c.section) AS class_name,
                    (i.amount - i.discount - i.paid_amount) AS balance
               FROM fee_invoices i
               JOIN students s ON s.id = i.student_id
               LEFT JOIN classes c ON c.id = s.class_id
              WHERE $w
              ORDER BY i.due_date DESC, i.id DESC LIMIT 300", $params
        );

        $summary = Database::one(
            "SELECT COALESCE(SUM(i.amount - i.discount),0) AS billed,
                    COALESCE(SUM(i.paid_amount),0) AS collected
               FROM fee_invoices i JOIN students s ON s.id = i.student_id
              WHERE $w AND i.status <> 'cancelled'", $params
        );
        $summary['pending'] = (float) $summary['billed'] - (float) $summary['collected'];

        Response::ok(['invoices' => $rows, 'summary' => $summary]);
    }

    /** POST /fees/invoices - manual invoice */
    public function createInvoice(): void
    {
        Auth::can('admin', 'accountant');
        $d = Request::body();

        (new Validator($d))->check([
            'student_id' => 'required|numeric',
            'title'      => 'required|max:100',
            'amount'     => 'required|numeric',
            'due_date'   => 'required|date',
        ])->stopOnFail();

        $id = Database::insert('fee_invoices', [
            'invoice_no' => self::nextNo('fee_invoices', 'invoice_no', 'INV'),
            'student_id' => (int) $d['student_id'],
            'title'      => $d['title'],
            'period'     => $d['period'] ?? date('M-Y'),
            'amount'     => (float) $d['amount'],
            'discount'   => (float) ($d['discount'] ?? 0),
            'due_date'   => $d['due_date'],
            'status'     => 'unpaid',
            'created_by' => Auth::user()['id'],
        ]);

        Auth::log('invoice_create', "Invoice #$id");
        Response::ok(['id' => $id], 'Invoice created');
    }

    /**
     * POST /fees/payments
     * body: { invoice_id, amount, mode, txn_ref, paid_on, send_sms }
     */
    public function pay(): void
    {
        Auth::can('admin', 'accountant');
        $d = Request::body();

        (new Validator($d))->check([
            'invoice_id' => 'required|numeric',
            'amount'     => 'required|numeric',
            'mode'       => 'required|in:cash,upi,cheque,card,netbanking',
            'paid_on'    => 'required|date',
        ])->stopOnFail();

        $inv = Database::one('SELECT * FROM fee_invoices WHERE id = ?', [(int) $d['invoice_id']]);
        if (!$inv)                        Response::error('Invoice not found', 404);
        if ($inv['status'] === 'paid')    Response::error('This invoice is already paid in full', 422);
        if ($inv['status'] === 'cancelled') Response::error('This invoice is cancelled', 422);

        $balance = (float) $inv['amount'] - (float) $inv['discount'] - (float) $inv['paid_amount'];
        $amount  = round((float) $d['amount'], 2);

        if ($amount <= 0)        Response::error('Amount must be greater than zero', 422);
        if ($amount > $balance)  Response::error('Amount exceeds the pending balance of ' . number_format($balance, 2), 422);

        $receiptNo = self::nextNo('fee_payments', 'receipt_no', 'RCP');
        $pdo = Database::conn();
        $pdo->beginTransaction();

        try {
            $payId = Database::insert('fee_payments', [
                'receipt_no'  => $receiptNo,
                'invoice_id'  => (int) $inv['id'],
                'student_id'  => (int) $inv['student_id'],
                'amount'      => $amount,
                'mode'        => $d['mode'],
                'txn_ref'     => $d['txn_ref'] ?? null,
                'paid_on'     => $d['paid_on'],
                'note'        => $d['note'] ?? null,
                'received_by' => Auth::user()['id'],
            ]);

            $newPaid = (float) $inv['paid_amount'] + $amount;
            $status  = $newPaid >= ((float) $inv['amount'] - (float) $inv['discount']) ? 'paid' : 'partial';
            Database::update('fee_invoices', (int) $inv['id'], ['paid_amount' => $newPaid, 'status' => $status]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::error('Payment could not be saved: ' . $e->getMessage(), 500);
        }

        $smsSent = false;
        if (!empty($d['send_sms'])) {
            $s = Database::one('SELECT * FROM students WHERE id = ?', [(int) $inv['student_id']]);
            $res = Sms::sendTemplate('fee_receipt', $s['guardian_phone'], [
                'name'       => trim($s['first_name'] . ' ' . $s['last_name']),
                'father'     => $s['father_name'],
                'amount'     => number_format($amount, 2),
                'title'      => $inv['title'],
                'receipt_no' => $receiptNo,
            ], (int) $s['id']);
            $smsSent = $res['status'] === 'sent';
        }

        Auth::log('fee_payment', "Receipt $receiptNo, Rs $amount");
        Response::ok(
            ['payment_id' => $payId, 'receipt_no' => $receiptNo, 'status' => $status, 'sms_sent' => $smsSent],
            "Payment recorded. Receipt $receiptNo"
        );
    }

    /** GET /fees/receipt/{id} - printable receipt data */
    public function receipt(string $id): void
    {
        Auth::required();
        $row = Database::one(
            "SELECT p.*, i.invoice_no, i.title, i.period, i.amount AS invoice_amount, i.discount,
                    i.paid_amount, i.due_date, i.status AS invoice_status,
                    CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) AS student_name,
                    s.admission_no, s.father_name, s.guardian_phone,
                    CONCAT(c.name,' - ',c.section) AS class_name, u.name AS received_by_name
               FROM fee_payments p
               JOIN fee_invoices i ON i.id = p.invoice_id
               JOIN students s ON s.id = p.student_id
               LEFT JOIN classes c ON c.id = s.class_id
               LEFT JOIN users u ON u.id = p.received_by
              WHERE p.id = ?", [(int) $id]
        );
        if (!$row) Response::error('Receipt not found', 404);

        $row['school_name']    = Config::setting('school_name');
        $row['school_address'] = Config::setting('school_address');
        Response::ok($row);
    }

    /** GET /fees/defaulters */
    public function defaulters(): void
    {
        Auth::required();
        $rows = Database::all(
            "SELECT s.id, s.admission_no, CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) AS student_name,
                    s.father_name, s.guardian_phone, CONCAT(c.name,' - ',c.section) AS class_name,
                    SUM(i.amount - i.discount - i.paid_amount) AS due_amount,
                    MIN(i.due_date) AS oldest_due, COUNT(*) AS invoice_count
               FROM fee_invoices i
               JOIN students s ON s.id = i.student_id
               LEFT JOIN classes c ON c.id = s.class_id
              WHERE i.status IN ('unpaid','partial') AND i.due_date < CURDATE() AND s.status = 'active'
              GROUP BY s.id, s.admission_no, s.first_name, s.last_name,
                       s.father_name, s.guardian_phone, c.name, c.section
              ORDER BY due_amount DESC"
        );
        Response::ok($rows);
    }

    /** Admission ke waqt class fee heads se pehla invoice set banata hai. */
    public static function generateForStudent(int $studentId, int $classId): int
    {
        $heads = Database::all(
            'SELECT * FROM fee_heads WHERE class_id = ? OR class_id IS NULL', [$classId]
        );
        $count = 0;
        foreach ($heads as $h) {
            Database::insert('fee_invoices', [
                'invoice_no'  => self::nextNo('fee_invoices', 'invoice_no', 'INV'),
                'student_id'  => $studentId,
                'fee_head_id' => (int) $h['id'],
                'title'       => $h['title'],
                'period'      => date('M-Y'),
                'amount'      => (float) $h['amount'],
                'due_date'    => date('Y-m-10', strtotime('+1 month')),
                'status'      => 'unpaid',
                'created_by'  => Auth::user()['id'] ?? null,
            ]);
            $count++;
        }
        return $count;
    }

    private static function nextNo(string $table, string $col, string $prefix): string
    {
        $ym   = date('ym');
        $last = Database::scalar("SELECT $col FROM $table WHERE $col LIKE ? ORDER BY id DESC LIMIT 1", ["$prefix$ym%"]);
        $seq  = $last ? ((int) substr($last, -4)) + 1 : 1;
        return $prefix . $ym . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
