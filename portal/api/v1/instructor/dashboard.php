<?php
// GET /api/v1/instructor/dashboard.php — instructor landing page context:
// recent classes, recent belt tests, and the instructor's own student id +
// whether they have linked children (drives the profile/pay buttons).

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/family.php';
require_once __DIR__ . '/../../../includes/auto_inactive.php';

api_require_method('GET');
api_require_role('instructor', 'admin');
apply_auto_inactive();

$recent_sessions = db()->query(
    'SELECT id, session_date, class_type FROM class_sessions ORDER BY session_date DESC LIMIT 10'
)->fetchAll();

// Fetch 11 to detect overflow (the page shows a "view all" affordance)
$tests = db()->query(
    'SELECT bt.id, bt.test_date, bt.result,
            s.id AS student_id, s.first_name, s.last_name,
            r.kyu_dan
     FROM belt_tests bt
     JOIN students s ON s.id = bt.student_id
     JOIN ranks r    ON r.id = bt.rank_testing_for
     ORDER BY bt.test_date DESC, s.last_name
     LIMIT 11'
)->fetchAll();
$has_more_tests = count($tests) === 11;
if ($has_more_tests) array_pop($tests);

$own          = family_own_student((int)current_user_id());
$own_id       = $own !== null ? (int)$own['id'] : 0;
$has_children = $own_id > 0 && count(family_child_ids($own_id)) > 0;

api_respond([
    'recent_sessions' => array_map(fn($s) => [
        'id'           => (int)$s['id'],
        'session_date' => $s['session_date'],
        'class_type'   => (string)$s['class_type'],
    ], $recent_sessions),
    'recent_belt_tests' => array_map(fn($t) => [
        'id'         => (int)$t['id'],
        'test_date'  => $t['test_date'],
        'result'     => (string)$t['result'],
        'student_id' => (int)$t['student_id'],
        'student'    => $t['first_name'] . ' ' . $t['last_name'],
        'kyu_dan'    => (string)$t['kyu_dan'],
    ], $tests),
    'has_more_tests' => $has_more_tests,
    'own_student_id' => $own_id,
    'has_children'   => $has_children,
]);
