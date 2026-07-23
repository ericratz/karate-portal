<?php
// /api/v1/admin/dashboard.php — everything the admin dashboard renders, in
// one GET: summary stats, unpaid-tuition and missing-waiver lists, the
// registration/linking alert queues, attendance + rent alert flags, recent
// payments, and the 12-month revenue/expense chart series. Same queries as
// the old admin/index.php, including its side effects (auto-inactive pass +
// log retention ran on every dashboard load).
// POST {action:"dismiss_alert", lr_id} resolves a link-request alert.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/auto_inactive.php';
require_once __DIR__ . '/../../../includes/log_retention.php';

api_require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input  = api_read_json();
    $action = api_str($input, 'action');

    if ($action === 'dismiss_alert') {
        $lr_id = api_int($input, 'lr_id');
        if ($lr_id) {
            db()->prepare(
                'UPDATE link_requests SET resolved = 1, resolved_at = NOW(), resolved_by = ? WHERE id = ?'
            )->execute([current_user_id(), $lr_id]);
            audit('dismiss_alert', 'link_requests', $lr_id);
        }
        api_respond(['dismissed' => true]);
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');
apply_auto_inactive();
apply_log_retention();

$stats = db()->query(
    'SELECT
        (SELECT COUNT(*) FROM students WHERE active = 1)                          AS active_students,
        (SELECT COUNT(*) FROM students WHERE active = 0)                          AS inactive_students,
        (SELECT COUNT(*) FROM users)                                              AS total_users,
        (SELECT COUNT(*) FROM class_sessions)                                     AS total_sessions,
        (SELECT COALESCE(SUM(amount),0) FROM payments WHERE YEAR(payment_date)=YEAR(NOW()))
      + (SELECT COALESCE(SUM(amount),0) FROM donations WHERE YEAR(payment_date)=YEAR(NOW())) AS revenue_ytd,
        (SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW()))
      + (SELECT COALESCE(SUM(amount),0) FROM donations WHERE MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())) AS revenue_month,
        (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_type=\'rent\' AND paid=1 AND YEAR(expense_date)=YEAR(NOW())) AS rent_ytd
    '
)->fetch();

// Students who haven't paid tuition this month (active, registered students only).
$unpaid = db()->query(
    'SELECT s.id, s.first_name, s.last_name
     FROM students s
     WHERE s.active = 1
       AND s.student_type IN (\'student\',\'parent\',\'instructor\')
       AND s.id NOT IN (
           SELECT student_id FROM payments
           WHERE payment_type = "monthly_tuition"
             AND MONTH(payment_date) = MONTH(NOW())
             AND YEAR(payment_date)  = YEAR(NOW())
       )
     ORDER BY s.first_name, s.last_name'
)->fetchAll();

$no_waiver = db()->query(
    'SELECT id, first_name, last_name FROM students WHERE active=1 AND injury_waiver=0 ORDER BY last_name'
)->fetchAll();

// Registration alert queues
$alerts_claimed = $alerts_new = $alerts_linking = [];
try {
    $base = 'SELECT lr.id, lr.created_at, lr.student_id,
                     u.id AS user_id, u.username, u.first_name AS u_first, u.last_name AS u_last,
                     s.first_name AS s_first, s.last_name AS s_last, s.student_type
              FROM link_requests lr
              JOIN users u ON u.id = lr.user_id
              LEFT JOIN students s ON s.id = lr.student_id
              WHERE lr.resolved = 0 AND lr.request_type = ?
              ORDER BY lr.created_at DESC';
    $stmt = db()->prepare($base);
    $stmt->execute(['claimed_existing']); $alerts_claimed = $stmt->fetchAll();
    $stmt->execute(['new_student']);      $alerts_new     = $stmt->fetchAll();
    $stmt->execute(['needs_linking']);    $alerts_linking = $stmt->fetchAll();
} catch (Exception $e) {}

// Legacy link requests (old-style notify flow — kept for backward compat)
$link_requests = [];
try {
    $link_requests = db()->query(
        'SELECT lr.id, lr.request_type, lr.notes, lr.created_at,
                u.id AS user_id, u.username, u.first_name, u.last_name, u.email
         FROM link_requests lr
         JOIN users u ON u.id = lr.user_id
         WHERE lr.resolved = 0
           AND lr.request_type IN (\'new_guest\',\'existing_student\',\'parent\')
         ORDER BY lr.created_at DESC'
    )->fetchAll();
} catch (Exception $e) {}

// Possible account links: unlinked users whose name or email matches an unlinked student
$possible_links = db()->query(
    'SELECT u.id AS user_id, u.username, u.first_name AS u_first, u.last_name AS u_last, u.email AS u_email,
            s.id AS student_id, s.first_name AS s_first, s.last_name AS s_last, s.email AS s_email
     FROM users u
     JOIN students s ON s.user_id IS NULL
         AND (
             (LOWER(u.first_name) = LOWER(s.first_name) AND LOWER(u.last_name) = LOWER(s.last_name))
             OR
             (u.email != \'\' AND u.email IS NOT NULL AND s.email != \'\' AND s.email IS NOT NULL
              AND LOWER(u.email) = LOWER(s.email))
         )
     WHERE NOT EXISTS (SELECT 1 FROM students s2 WHERE s2.user_id = u.id)
     ORDER BY u.first_name, u.last_name'
)->fetchAll();

// Attendance alert — was last Saturday's class recorded?
$check_saturday = (date('N') == 6)
    ? date('Y-m-d')
    : date('Y-m-d', (int) strtotime('last saturday'));
$att_stmt = db()->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
$att_stmt->execute([$check_saturday]);
$attendance_missing = !$att_stmt->fetch();
$days_since_saturday = (int)floor((time() - (int) strtotime($check_saturday)) / 86400);
$show_attendance_alert = $attendance_missing && $days_since_saturday <= 6;

// Rent reminder — show all month until recorded
$rent_stmt = db()->prepare(
    "SELECT COUNT(*) FROM expenses WHERE expense_type = 'rent' AND DATE_FORMAT(expense_date, '%Y-%m') = ?"
);
$rent_stmt->execute([date('Y-m')]);
$show_rent_alert = (int)$rent_stmt->fetchColumn() === 0;

// Recent payments (last 10, +1 to detect more)
$recent_payments = db()->query(
    'SELECT p.payment_date, p.amount, p.payment_type, p.payment_method,
            s.first_name, s.last_name
     FROM payments p JOIN students s ON s.id = p.student_id
     ORDER BY p.payment_date DESC LIMIT 11'
)->fetchAll();
$has_more_payments = count($recent_payments) === 11;
if ($has_more_payments) array_pop($recent_payments);

// Revenue chart — last 12 months
$rev_rows = db()->query(
    "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS month,
            payment_type,
            SUM(amount) AS total
     FROM payments
     WHERE payment_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 11 MONTH),'%Y-%m-01')
     GROUP BY month, payment_type
     ORDER BY month"
)->fetchAll();

$exp_rows = db()->query(
    "SELECT DATE_FORMAT(expense_date,'%Y-%m') AS month,
            SUM(amount) AS total
     FROM expenses
     WHERE expense_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 11 MONTH),'%Y-%m-01')
     GROUP BY month
     ORDER BY month"
)->fetchAll();

$exp_type_rows = db()->query(
    "SELECT DATE_FORMAT(expense_date,'%Y-%m') AS month,
            expense_type,
            SUM(amount) AS total
     FROM expenses
     WHERE expense_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 11 MONTH),'%Y-%m-01')
     GROUP BY month, expense_type
     ORDER BY month"
)->fetchAll();

$don_rows = db()->query(
    "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS month, SUM(amount) AS total
     FROM donations
     WHERE payment_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 11 MONTH),'%Y-%m-01')
     GROUP BY month
     ORDER BY month"
)->fetchAll();

$chart_months = [];
for ($i = 11; $i >= 0; $i--) {
    $chart_months[] = date('Y-m', (int) strtotime("-$i months"));
}

$named_types = ['monthly_tuition', 'registration', 'belt_test', 'slc_training', 'seminar'];
$exp_types   = ['rent', 'equipment', 'utilities', 'supplies', 'other'];
$chart_data  = array_fill_keys($named_types, []);
$chart_data['other']     = [];
$chart_data['donations'] = [];
$chart_data['revenue']   = [];
$chart_data['expenses']  = [];
$chart_data['exp_abs']   = [];
foreach ($exp_types as $t) $chart_data['exp_' . $t] = [];

$rev_map = [];
foreach ($rev_rows as $r) {
    $rev_map[$r['month']][$r['payment_type']] = (float)$r['total'];
}
$exp_map = [];
foreach ($exp_rows as $r) {
    $exp_map[$r['month']] = (float)$r['total'];
}
$exp_type_map = [];
foreach ($exp_type_rows as $r) {
    $exp_type_map[$r['month']][$r['expense_type']] = (float)$r['total'];
}
$don_map = [];
foreach ($don_rows as $r) {
    $don_map[$r['month']] = (float)$r['total'];
}

foreach ($chart_months as $m) {
    foreach ($named_types as $t) {
        $chart_data[$t][] = $rev_map[$m][$t] ?? 0;
    }
    $other = 0.0;
    foreach ($rev_map[$m] ?? [] as $type => $amt) {
        if (!in_array($type, $named_types)) $other += $amt;
    }
    $chart_data['other'][]     = $other;
    $chart_data['donations'][] = $don_map[$m] ?? 0;
    foreach ($exp_types as $t) {
        $chart_data['exp_' . $t][] = $exp_type_map[$m][$t] ?? 0;
    }

    $exp_total = $exp_map[$m] ?? 0;
    $chart_data['revenue'][]  = (float) array_sum($rev_map[$m] ?? []) + (float) ($don_map[$m] ?? 0);
    $chart_data['expenses'][] = -$exp_total;
    $chart_data['exp_abs'][]  = $exp_total;
}

$chart_labels = array_map(fn($m) => date('M Y', (int) strtotime($m . '-01')), $chart_months);

$alert_row = fn($a) => [
    'id'           => (int)$a['id'],
    'created_at'   => (string)$a['created_at'],
    'student_id'   => $a['student_id'] !== null ? (int)$a['student_id'] : null,
    'user_id'      => (int)$a['user_id'],
    'username'     => (string)$a['username'],
    'user_name'    => trim(($a['u_first'] ?? '') . ' ' . ($a['u_last'] ?? '')),
    'student_name' => $a['s_first'] !== null ? trim($a['s_first'] . ' ' . $a['s_last']) : null,
];

api_respond([
    'stats' => [
        'active_students'   => (int)$stats['active_students'],
        'inactive_students' => (int)$stats['inactive_students'],
        'revenue_month'     => (float)$stats['revenue_month'],
        'revenue_ytd'       => (float)$stats['revenue_ytd'],
        'rent_ytd'          => (float)$stats['rent_ytd'],
    ],
    'unpaid' => array_map(fn($s) => [
        'id'   => (int)$s['id'],
        'name' => trim($s['first_name'] . ' ' . $s['last_name']),
    ], $unpaid),
    'no_waiver' => array_map(fn($s) => [
        'id'   => (int)$s['id'],
        'name' => trim($s['first_name'] . ' ' . $s['last_name']),
    ], $no_waiver),
    'alerts_linking' => array_map($alert_row, $alerts_linking),
    'alerts_claimed' => array_map($alert_row, $alerts_claimed),
    'alerts_new'     => array_map($alert_row, $alerts_new),
    'link_requests'  => array_map(fn($lr) => [
        'id'           => (int)$lr['id'],
        'request_type' => (string)$lr['request_type'],
        'notes'        => $lr['notes'] ?? null,
        'created_at'   => (string)$lr['created_at'],
        'user_id'      => (int)$lr['user_id'],
        'username'     => (string)$lr['username'],
        'user_name'    => trim(($lr['first_name'] ?? '') . ' ' . ($lr['last_name'] ?? '')),
    ], $link_requests),
    'possible_links' => array_map(fn($m) => [
        'user_id'      => (int)$m['user_id'],
        'username'     => (string)$m['username'],
        'user_name'    => trim(($m['u_first'] ?? '') . ' ' . ($m['u_last'] ?? '')),
        'student_id'   => (int)$m['student_id'],
        'student_name' => trim($m['s_first'] . ' ' . $m['s_last']),
        'email_match'  => $m['u_email'] !== null && $m['s_email'] !== null
                          && $m['u_email'] !== '' && strtolower($m['u_email']) === strtolower($m['s_email']),
    ], $possible_links),
    'attendance_alert' => [
        'show' => $show_attendance_alert,
        'date' => $check_saturday,
    ],
    'rent_alert'        => $show_rent_alert,
    'recent_payments'   => array_map(fn($p) => [
        'payment_date' => (string)$p['payment_date'],
        'amount'       => (float)$p['amount'],
        'payment_type' => (string)$p['payment_type'],
        'name'         => trim($p['first_name'] . ' ' . $p['last_name']),
    ], $recent_payments),
    'has_more_payments' => $has_more_payments,
    'chart' => [
        'labels' => $chart_labels,
        'data'   => $chart_data,
    ],
]);
