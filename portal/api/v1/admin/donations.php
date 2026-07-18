<?php
// /api/v1/admin/donations.php — donations list + record/delete.
// GET: filtered list (from/to/method/year), year options, shown total, and
//      the student picker — same WHERE clauses as the old admin/donations.php.
// POST {action:"record"|"delete", ...}: same validation/link rules and audits.

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('admin');

$VALID_METHODS = ['paypal', 'cash', 'check', 'mail'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input  = api_read_json();
    $action = api_str($input, 'action');

    if ($action === 'delete') {
        $del_id = api_int($input, 'id');
        db()->prepare('DELETE FROM donations WHERE id=?')->execute([$del_id]);
        audit('delete_donation', 'donation', $del_id);
        api_respond(['deleted' => true]);
    }

    if ($action === 'record') {
        $amount  = (float)api_str($input, 'amount', '0');
        $method  = api_str($input, 'payment_method');
        $donor   = trim(api_str($input, 'donor_name'));
        $notes   = trim(api_str($input, 'notes'));
        $date    = api_str($input, 'payment_date', date('Y-m-d'));
        $don_sid = api_int($input, 'student_id');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        // Optional student link — must be a real student id
        if ($don_sid) {
            $chk = db()->prepare('SELECT COUNT(*) FROM students WHERE id = ?');
            $chk->execute([$don_sid]);
            if (!$chk->fetchColumn()) $don_sid = 0;
        }

        if ($amount <= 0 || !in_array($method, $VALID_METHODS, true)) {
            api_error('Amount and payment method are required.', 422);
        }
        // Linked donations take their name from the student record;
        // free-typed names are stored as unlinked (anonymous) donor_name.
        db()->prepare(
            'INSERT INTO donations (student_id, amount, payment_method, donor_name, notes, payment_date, recorded_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $don_sid ?: null,
            $amount, $method,
            ($don_sid || $donor === '') ? null : $donor,
            $notes ?: null,
            $date,
            current_user_id(),
        ]);
        audit('record_donation', 'donation', (int)db()->lastInsertId(), "amount=$amount");
        api_respond(['recorded' => true]);
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');

$f_from   = get_str('from');
$f_to     = get_str('to');
$f_method = get_str('method');
$f_year   = get_int('year');

$where  = ['1=1'];
$params = [];
if ($f_from)   { $where[] = 'payment_date >= ?';       $params[] = $f_from; }
if ($f_to)     { $where[] = 'payment_date <= ?';       $params[] = $f_to; }
if ($f_method) { $where[] = 'payment_method = ?';      $params[] = $f_method; }
if ($f_year)   { $where[] = 'YEAR(payment_date) = ?';  $params[] = $f_year; }

$donation_years = db()->query('SELECT DISTINCT YEAR(payment_date) AS y FROM donations ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
$donation_years = array_map('intval', $donation_years);
if (!in_array((int)date('Y'), $donation_years, true)) {
    array_unshift($donation_years, (int)date('Y'));
}

$stmt = db()->prepare(
    'SELECT d.*, u.username AS recorded_by_name,
            s.first_name AS student_first, s.last_name AS student_last
     FROM donations d
     LEFT JOIN users u ON u.id = d.recorded_by
     LEFT JOIN students s ON s.id = d.student_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY d.payment_date DESC, d.created_at DESC'
);
$stmt->execute($params);
$donations = $stmt->fetchAll();

$students = db()->query(
    'SELECT id, first_name, last_name FROM students ORDER BY first_name, last_name'
)->fetchAll();

api_respond([
    'donations' => array_map(fn($d) => [
        'id'               => (int)$d['id'],
        'payment_date'     => (string)$d['payment_date'],
        'student_id'       => $d['student_id'] !== null ? (int)$d['student_id'] : null,
        'donor_name'       => $d['donor_name'] ?? null,
        'student_name'     => $d['student_id'] !== null ? trim($d['student_first'] . ' ' . $d['student_last']) : null,
        'payment_method'   => (string)$d['payment_method'],
        'notes'            => $d['notes'] ?? null,
        'recorded_by_name' => $d['recorded_by_name'] ?? null,
        'amount'           => (float)$d['amount'],
    ], $donations),
    'total_shown' => (float)array_sum(array_column($donations, 'amount')),
    'years'       => $donation_years,
    'students'    => array_map(fn($s) => [
        'id'         => (int)$s['id'],
        'first_name' => (string)$s['first_name'],
        'last_name'  => (string)$s['last_name'],
    ], $students),
]);
