<?php
// GET /api/v1/parent/student.php?student_id=N — one family member's full
// dashboard data: profile, rank, attendance summary + chart, recent activity.
// Mirrors the per-tab queries of parent/index.php.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/family.php';
require_once __DIR__ . '/../../../includes/belt_helpers.php';

api_require_method('GET');
api_require_role('parent', 'student', 'guest', 'instructor', 'admin');

$user_id    = (int)current_user_id();
$student_id = get_int('student_id');

if (!family_can_access($user_id, $student_id)) {
    api_error('Student not linked to your account', 403);
}

$stmt = db()->prepare('SELECT * FROM students WHERE id = ?');
$stmt->execute([$student_id]);
$student = $stmt->fetch();
if ($student === false) {
    api_error('Student not found', 404);
}

// Current rank + next rank
$rank_q = db()->prepare(
    'SELECT r.name, r.kyu_dan, sr.rank_id FROM student_ranks sr
     JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ? ORDER BY r.rank_order DESC LIMIT 1'
);
$rank_q->execute([$student_id]);
$rank = $rank_q->fetch();

$next_rank = belt_next_rank(
    is_array($rank) ? ($rank['kyu_dan'] ?? null) : null,
    $student['date_of_birth'] ?? null
);

// All-time attendance summary
$as_q = db()->prepare(
    'SELECT COUNT(*) AS total, COALESCE(SUM(a.present), 0) AS attended
     FROM class_sessions cs
     JOIN attendance a ON a.session_id = cs.id AND a.student_id = ?'
);
$as_q->execute([$student_id]);
$att_summary = $as_q->fetch();

// Recent activity (10 each)
$att_q = db()->prepare(
    'SELECT cs.session_date, cs.class_type FROM attendance a
     JOIN class_sessions cs ON cs.id = a.session_id
     WHERE a.student_id = ? AND a.present = 1
     ORDER BY cs.session_date DESC LIMIT 10'
);
$att_q->execute([$student_id]);

$pay_q = db()->prepare(
    'SELECT payment_date, payment_type, payment_method, amount, month_covered
     FROM payments WHERE student_id = ?
     ORDER BY payment_date DESC LIMIT 10'
);
$pay_q->execute([$student_id]);

$bt_q = db()->prepare(
    'SELECT bt.test_date, r.kyu_dan, bt.result, bt.score, bt.fee_paid, bt.belt_awarded
     FROM belt_tests bt JOIN ranks r ON r.id = bt.rank_testing_for
     WHERE bt.student_id = ? ORDER BY bt.test_date DESC LIMIT 10'
);
$bt_q->execute([$student_id]);

$rh_q = db()->prepare(
    'SELECT sr.rank_id, r.kyu_dan, sr.achieved_date
     FROM student_ranks sr JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ? ORDER BY r.rank_order DESC'
);
$rh_q->execute([$student_id]);

$wv_q = db()->prepare(
    'SELECT waiver_type FROM payment_waivers WHERE student_id = ? ORDER BY granted_date DESC'
);
$wv_q->execute([$student_id]);

$sub_q = db()->prepare(
    "SELECT id FROM subscriptions WHERE student_id = ? AND status = 'active' LIMIT 1"
);
$sub_q->execute([$student_id]);

// Monthly attendance chart — last 12 months, with rank-achievement markers
$ac_q = db()->prepare(
    "SELECT DATE_FORMAT(cs.session_date, '%Y-%m') AS month, COUNT(*) AS count
     FROM attendance a
     JOIN class_sessions cs ON cs.id = a.session_id
     WHERE a.student_id = ? AND a.present = 1
       AND cs.session_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
     GROUP BY month
     ORDER BY month ASC"
);
$ac_q->execute([$student_id]);
$att_by_month = $ac_q->fetchAll(PDO::FETCH_KEY_PAIR);

$rm_q = db()->prepare(
    "SELECT DATE_FORMAT(sr.achieved_date, '%Y-%m') AS month, r.name AS rank_name
     FROM student_ranks sr
     JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ?
       AND sr.achieved_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
     ORDER BY sr.achieved_date"
);
$rm_q->execute([$student_id]);
$rank_months = [];
foreach ($rm_q->fetchAll() as $row) {
    $rank_months[(string)$row['month']][] = (string)$row['rank_name'];
}

$chart = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime("-$i months");
    $key = date('Y-m', $ts);
    $chart[] = [
        'month' => $key,
        'label' => date('M Y', $ts),
        'count' => (int)($att_by_month[$key] ?? 0),
        'ranks' => $rank_months[$key] ?? null,
    ];
}

api_respond([
    'student'          => family_student_profile($student),
    'rank'             => is_array($rank)
        ? ['name' => (string)$rank['name'], 'kyu_dan' => (string)$rank['kyu_dan'], 'rank_id' => (int)$rank['rank_id']]
        : null,
    'next_rank'        => $next_rank,
    'att_summary'      => [
        'attended' => (int)($att_summary['attended'] ?? 0),
        'total'    => (int)($att_summary['total'] ?? 0),
    ],
    'recent_attendance' => $att_q->fetchAll(),
    'recent_payments'   => $pay_q->fetchAll(),
    'recent_belt_tests' => $bt_q->fetchAll(),
    'rank_history'      => $rh_q->fetchAll(),
    'active_waivers'    => array_map(
        static fn(array $w): string => (string)$w['waiver_type'],
        $wv_q->fetchAll()
    ),
    'has_autopay'       => (bool)$sub_q->fetchColumn(),
    'attendance_chart'  => $chart,
]);
