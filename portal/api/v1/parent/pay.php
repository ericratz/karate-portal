<?php
// GET /api/v1/parent/pay.php — everything the Pay page needs up front:
// the family list, fee table, per-student paid state (tuition this month,
// paid months, registration, active auto-pay), month picker options, and the
// public PayPal client id. Mirrors what parent/pay.php computed server-side.
// Order create/capture stay on the existing api/paypal_create.php and
// api/paypal_capture.php (already JSON + X-CSRF-Token).

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/family.php';

api_require_method('GET');
api_require_role('parent', 'student', 'guest', 'instructor', 'admin');

$user_id = (int)current_user_id();
$own     = family_own_student($user_id);

$family = [];
$own_id = 0;

if ($own !== null) {
    $own_id   = (int)$own['id'];
    $family[] = ['id' => $own_id, 'name' => $own['first_name'] . ' ' . $own['last_name']];

    $ch_stmt = db()->prepare(
        'SELECT s.id, s.first_name, s.last_name
         FROM student_guardians sg
         JOIN students s ON s.id = sg.child_student_id
         WHERE sg.parent_student_id = ?
         ORDER BY s.first_name, s.last_name'
    );
    $ch_stmt->execute([$own_id]);
    foreach ($ch_stmt->fetchAll() as $c) {
        $family[] = ['id' => (int)$c['id'], 'name' => $c['first_name'] . ' ' . $c['last_name']];
    }
}

$family_ids = array_column($family, 'id');

$tuition_paid_ids       = [];
$paid_months_by_student = [];
$reg_paid_ids           = [];
$autopay_active_ids     = [];

if (!empty($family_ids)) {
    $placeholders = implode(',', array_fill(0, count($family_ids), '?'));

    // Tuition paid this month
    $tp = db()->prepare(
        "SELECT student_id FROM payments
         WHERE student_id IN ($placeholders)
           AND payment_type = 'monthly_tuition'
           AND MONTH(payment_date) = MONTH(NOW())
           AND YEAR(payment_date)  = YEAR(NOW())"
    );
    $tp->execute($family_ids);
    $tuition_paid_ids = array_map('intval', $tp->fetchAll(PDO::FETCH_COLUMN));

    // All paid tuition months per student (for the month-picker notice)
    $pm = db()->prepare(
        "SELECT student_id, COALESCE(DATE_FORMAT(month_covered, '%Y-%m-01'), DATE_FORMAT(payment_date, '%Y-%m-01')) AS paid_month
         FROM payments WHERE student_id IN ($placeholders) AND payment_type = 'monthly_tuition'"
    );
    $pm->execute($family_ids);
    foreach ($pm->fetchAll() as $row) {
        $paid_months_by_student[(int)$row['student_id']][] = $row['paid_month'];
    }
    foreach ($family_ids as $fid) {
        $paid_months_by_student[$fid] = array_values(array_unique($paid_months_by_student[$fid] ?? []));
    }

    // Registration ever paid
    $rp = db()->prepare(
        "SELECT DISTINCT student_id FROM payments
         WHERE student_id IN ($placeholders) AND payment_type = 'registration'"
    );
    $rp->execute($family_ids);
    $reg_paid_ids = array_map('intval', $rp->fetchAll(PDO::FETCH_COLUMN));

    // Active auto-pay subscriptions
    $sub = db()->prepare(
        "SELECT student_id FROM subscriptions
         WHERE student_id IN ($placeholders) AND status = 'active'"
    );
    $sub->execute($family_ids);
    $autopay_active_ids = array_map('intval', $sub->fetchAll(PDO::FETCH_COLUMN));
}

// Month picker options (previous month + current + next 3) — computed server
// side so the SPA and the server always agree on what "this month" means.
$month_options = [];
for ($i = -1; $i <= 3; $i++) {
    $ts = mktime(0, 0, 0, (int)date('n') + $i, 1);
    $month_options[] = [
        'value' => date('Y-m-01', $ts),
        'label' => date('F Y', $ts),
    ];
}

api_respond([
    'family'                 => $family,
    'own_id'                 => $own_id,
    'fees'                   => [
        'monthly_tuition' => ['label' => 'Monthly Tuition',  'amount' => MONTHLY_FEE],
        'registration'    => ['label' => 'Registration Fee', 'amount' => REG_FEE],
        'belt_test'       => ['label' => 'Belt Test Fee',    'amount' => TEST_FEE],
        'slc_training'    => ['label' => 'SLC Training',     'amount' => SLC_FEE],
        'seminar'         => ['label' => 'Seminar',          'amount' => SEMINAR_FEE],
    ],
    'monthly_fee'            => MONTHLY_FEE,
    'tuition_paid_ids'       => $tuition_paid_ids,
    'paid_months_by_student' => (object)$paid_months_by_student,
    'reg_paid_ids'           => $reg_paid_ids,
    'autopay_active_ids'     => $autopay_active_ids,
    'month_options'          => $month_options,
    'current_month_value'    => date('Y-m-01'),
    'next_month_value'       => date('Y-m-01', mktime(0, 0, 0, (int)date('n') + 1, 1)),
    'paypal_client_id'       => PAYPAL_CLIENT_ID,
]);
