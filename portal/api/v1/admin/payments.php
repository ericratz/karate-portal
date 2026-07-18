<?php
// /api/v1/admin/payments.php — the Payments page.
// GET (filters student_id/type/method/year): filtered payment rows, year
//     options, the student picker, and the per-type fee table the record
//     form's auto-amount uses.
// POST {action:"record"|"edit"|"delete", ...}: same semantics as the old
//     admin/payments.php — duplicate-tuition warning (never blocks), guest →
//     student auto-promotion on registration, revert-to-guest sync when the
//     last registration payment disappears, receipt email on record, audits.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/config.php';

api_require_role('admin');

$VALID_TYPES   = ['monthly_tuition', 'registration', 'belt_test', 'slc_training', 'seminar', 'other'];
$VALID_METHODS = ['paypal', 'cash', 'check', 'mail'];

// If no registration payment remains, revert student back to guest
function sync_registration_status(int $student_id): void {
    $stmt = db()->prepare("SELECT COUNT(*) FROM payments WHERE student_id=? AND payment_type='registration'");
    $stmt->execute([$student_id]);
    if (!(int)$stmt->fetchColumn()) {
        db()->prepare("UPDATE students SET student_type='guest' WHERE id=? AND student_type='student'")
             ->execute([$student_id]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input  = api_read_json();
    $action = api_str($input, 'action');

    if ($action === 'delete') {
        $del_id = api_int($input, 'id');
        // Fetch student_id before deleting so we can sync status after
        $del_row = db()->prepare('SELECT student_id FROM payments WHERE id=?');
        $del_row->execute([$del_id]);
        $del_sid = (int)$del_row->fetchColumn();
        db()->prepare('DELETE FROM payments WHERE id=?')->execute([$del_id]);
        audit('delete_payment', 'payment', $del_id);
        if ($del_sid) sync_registration_status($del_sid);
        api_respond(['deleted' => true]);
    }

    if ($action === 'edit') {
        $pid    = api_int($input, 'id');
        $amount = (float)api_str($input, 'amount', '0');
        $type   = api_str($input, 'payment_type');
        $method = api_str($input, 'payment_method');
        $date   = api_str($input, 'payment_date', date('Y-m-d'));
        $month  = api_str($input, 'month_covered');
        $txn    = trim(api_str($input, 'transaction_id'));
        $notes  = trim(api_str($input, 'notes'));
        if (!$pid || $amount <= 0 || !in_array($type, $VALID_TYPES, true) || !in_array($method, $VALID_METHODS, true)) {
            api_error('Please fill in all required fields with valid values.', 422);
        }
        db()->prepare(
            'UPDATE payments SET payment_date=?, payment_type=?, payment_method=?, amount=?,
             transaction_id=?, notes=?, month_covered=? WHERE id=?'
        )->execute([
            $date, $type, $method, $amount,
            $txn ?: null,
            $notes ?: null,
            ($type === 'monthly_tuition' && $month) ? $month . '-01' : null,
            $pid,
        ]);
        // Fetch student_id for status sync
        $sid_row = db()->prepare('SELECT student_id FROM payments WHERE id=?');
        $sid_row->execute([$pid]);
        $edit_sid = (int)$sid_row->fetchColumn();
        // Promote to student if type is now registration
        if ($type === 'registration' && $edit_sid) {
            db()->prepare("UPDATE students SET student_type='student' WHERE id=? AND student_type='guest'")->execute([$edit_sid]);
        }
        // Revert to guest if registration was removed
        if ($edit_sid) sync_registration_status($edit_sid);
        audit('edit_payment', 'payment', $pid);
        api_respond(['saved' => true]);
    }

    if ($action === 'record') {
        $sid    = api_int($input, 'student_id');
        $amount = (float)api_str($input, 'amount', '0');
        $type   = api_str($input, 'payment_type');
        $method = api_str($input, 'payment_method', 'paypal');
        $date   = api_str($input, 'payment_date', date('Y-m-d H:i:s'));
        $month  = api_str($input, 'month_covered') ?: null;
        $txn    = trim(api_str($input, 'transaction_id'));
        $notes  = trim(api_str($input, 'notes'));
        $payer_name = trim(api_str($input, 'payer_name'));
        $payer_note = trim(api_str($input, 'payer_note'));

        if (!$sid || $amount <= 0 || !in_array($type, $VALID_TYPES, true) || !in_array($method, $VALID_METHODS, true)) {
            api_error('Please fill in all required fields with valid values.', 422);
        }

        // Duplicate check (warning only, never blocks): tuition already
        // recorded for this student + month?
        $dup_count = 0;
        if ($type === 'monthly_tuition' && $month) {
            $dup_q = db()->prepare(
                "SELECT COUNT(*) FROM payments
                 WHERE student_id=? AND payment_type='monthly_tuition' AND month_covered=?"
            );
            $dup_q->execute([$sid, $month . '-01']);
            $dup_count = (int)$dup_q->fetchColumn();
        }

        db()->prepare(
            'INSERT INTO payments
             (student_id, amount, payment_type, payment_method, transaction_id,
              payment_date, month_covered, notes, payer_name, payer_note, recorded_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $sid, $amount, $type, $method,
            $txn ?: null,
            $date,
            ($type === 'monthly_tuition' && $month) ? $month . '-01' : null,
            $notes ?: null,
            $payer_name ?: null,
            $payer_note ?: null,
            current_user_id(),
        ]);

        // Auto-promote guest to student when registration fee is paid
        if ($type === 'registration') {
            db()->prepare("UPDATE students SET student_type='student' WHERE id=?")
                 ->execute([$sid]);
        }
        // Send payment receipt email to student
        $receipt_student = db()->prepare('SELECT first_name, last_name, email FROM students WHERE id = ?');
        $receipt_student->execute([$sid]);
        $rs = $receipt_student->fetch();
        if ($rs && !empty($rs['email'])) {
            $rs_name = trim($rs['first_name'] . ' ' . $rs['last_name']);
            send_payment_receipt(
                $rs['email'],
                $rs_name,
                [['type' => $type, 'amount' => $amount]],
                $amount,
                $method,
                $txn ?: null
            );
        }

        api_respond(['recorded' => true, 'dup_count' => $dup_count > 0 ? $dup_count + 1 : 0]);
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');

$f_student = get_int('student_id');
$f_type    = get_str('type');
$f_method  = get_str('method');
$f_year    = get_int('year');

$where  = ['1=1'];
$params = [];
if ($f_student) { $where[] = 'p.student_id = ?';         $params[] = $f_student; }
if ($f_type)    { $where[] = 'p.payment_type = ?';       $params[] = $f_type; }
if ($f_method)  { $where[] = 'p.payment_method = ?';     $params[] = $f_method; }
if ($f_year)    { $where[] = 'YEAR(p.payment_date) = ?'; $params[] = $f_year; }

$payment_years = db()->query('SELECT DISTINCT YEAR(payment_date) AS y FROM payments ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
$payment_years = array_map('intval', $payment_years);
if (!in_array((int)date('Y'), $payment_years, true)) {
    array_unshift($payment_years, (int)date('Y'));
}

$stmt = db()->prepare(
    'SELECT p.*, s.first_name, s.last_name, u.username AS recorded_by_name
     FROM payments p
     JOIN students s ON s.id = p.student_id
     LEFT JOIN users u ON u.id = p.recorded_by
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY p.payment_date DESC'
);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$all_students = db()->query(
    'SELECT id, first_name, last_name FROM students ORDER BY first_name, last_name'
)->fetchAll();

api_respond([
    'payments' => array_map(fn($p) => [
        'id'               => (int)$p['id'],
        'student_id'       => (int)$p['student_id'],
        'student_name'     => trim($p['first_name'] . ' ' . $p['last_name']),
        'payment_date'     => (string)$p['payment_date'],
        'payment_type'     => (string)$p['payment_type'],
        'payment_method'   => (string)$p['payment_method'],
        'amount'           => (float)$p['amount'],
        'transaction_id'   => $p['transaction_id'] ?? null,
        'notes'            => $p['notes'] ?? null,
        'month_covered'    => $p['month_covered'] ?? null,
        'payer_name'       => $p['payer_name'] ?? null,
        'payer_note'       => $p['payer_note'] ?? null,
        'recorded_by_name' => $p['recorded_by_name'] ?? null,
    ], $payments),
    'total_shown' => (float)array_sum(array_column($payments, 'amount')),
    'years'       => $payment_years,
    'students'    => array_map(fn($s) => [
        'id'         => (int)$s['id'],
        'first_name' => (string)$s['first_name'],
        'last_name'  => (string)$s['last_name'],
    ], $all_students),
    'fees' => [
        'monthly_tuition' => MONTHLY_FEE,
        'registration'    => REG_FEE,
        'belt_test'       => TEST_FEE,
        'slc_training'    => SLC_FEE,
        'seminar'         => SEMINAR_FEE,
    ],
]);
