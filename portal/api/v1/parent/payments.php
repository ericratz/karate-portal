<?php
// GET /api/v1/parent/payments.php?student_id=N[&year=YYYY] — payment history
// for one family member, attributed donations merged in as type 'donation'.
// Mirrors parent/payment_history.php.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/family.php';

api_require_method('GET');
api_require_role('parent', 'student', 'guest', 'instructor', 'admin');

$user_id    = (int)current_user_id();
$student_id = get_int('student_id');

if (!family_can_access($user_id, $student_id)) {
    api_error('Student not linked to your account', 403);
}

$stmt = db()->prepare('SELECT id, first_name, last_name FROM students WHERE id = ?');
$stmt->execute([$student_id]);
$student = $stmt->fetch();
if ($student === false) {
    api_error('Student not found', 404);
}

// Years with payments or attributed donations
$years_stmt = db()->prepare(
    'SELECT DISTINCT yr FROM (
        SELECT YEAR(payment_date) AS yr FROM payments  WHERE student_id = ?
        UNION
        SELECT YEAR(payment_date) AS yr FROM donations WHERE student_id = ?
     ) y ORDER BY yr DESC'
);
$years_stmt->execute([$student_id, $student_id]);
$years = array_map('intval', $years_stmt->fetchAll(PDO::FETCH_COLUMN));

$selected_year = in_array(get_int('year'), $years, true) ? get_int('year') : null;

$history_sql =
    'SELECT payment_date, payment_type, payment_method, amount, month_covered
     FROM payments WHERE student_id = ?
     UNION ALL
     SELECT payment_date, \'donation\', payment_method, amount, NULL
     FROM donations WHERE student_id = ?';
if ($selected_year !== null) {
    $payments_stmt = db()->prepare(
        "SELECT * FROM ($history_sql) h WHERE YEAR(payment_date) = ? ORDER BY payment_date DESC"
    );
    $payments_stmt->execute([$student_id, $student_id, $selected_year]);
} else {
    $payments_stmt = db()->prepare(
        "SELECT * FROM ($history_sql) h ORDER BY payment_date DESC"
    );
    $payments_stmt->execute([$student_id, $student_id]);
}

$payments = array_map(static function (array $p): array {
    return [
        'payment_date'   => (string)$p['payment_date'],
        'payment_type'   => (string)$p['payment_type'],
        'payment_method' => (string)$p['payment_method'],
        'amount'         => (float)$p['amount'],
        'month_covered'  => $p['month_covered'] ?? null,
    ];
}, $payments_stmt->fetchAll());

$tp = db()->prepare(
    'SELECT (SELECT COALESCE(SUM(amount), 0) FROM payments  WHERE student_id = ?)
          + (SELECT COALESCE(SUM(amount), 0) FROM donations WHERE student_id = ?)'
);
$tp->execute([$student_id, $student_id]);

api_respond([
    'student' => [
        'id'         => (int)$student['id'],
        'first_name' => (string)$student['first_name'],
        'last_name'  => (string)$student['last_name'],
    ],
    'years'          => $years,
    'year'           => $selected_year,
    'payments'       => $payments,
    'filtered_total' => round(array_sum(array_column($payments, 'amount')), 2),
    'total_paid'     => (float)$tp->fetchColumn(),
]);
