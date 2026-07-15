<?php
// GET /api/v1/parent/belt_tests.php?student_id=N — belt test history for one
// family member. Mirrors parent/belt_tests.php.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/family.php';

api_require_method('GET');
api_require_role('parent');

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

$tests_stmt = db()->prepare(
    'SELECT bt.test_date, r.name AS rank_name, r.kyu_dan, bt.result, bt.score, bt.fee_paid, bt.belt_awarded
     FROM belt_tests bt
     JOIN ranks r ON r.id = bt.rank_testing_for
     WHERE bt.student_id = ?
     ORDER BY bt.test_date DESC'
);
$tests_stmt->execute([$student_id]);

$tests = array_map(static function (array $t): array {
    return [
        'test_date'    => (string)$t['test_date'],
        'rank_name'    => (string)$t['rank_name'],
        'kyu_dan'      => (string)$t['kyu_dan'],
        'result'       => (string)$t['result'],
        'score'        => isset($t['score']) ? (int)$t['score'] : null,
        'fee_paid'     => (bool)$t['fee_paid'],
        'belt_awarded' => (bool)$t['belt_awarded'],
    ];
}, $tests_stmt->fetchAll());

api_respond([
    'student' => [
        'id'         => (int)$student['id'],
        'first_name' => (string)$student['first_name'],
        'last_name'  => (string)$student['last_name'],
    ],
    'tests'   => $tests,
    'passed'  => count(array_filter($tests, static fn(array $t): bool => $t['result'] === 'pass')),
    'pending' => count(array_filter($tests, static fn(array $t): bool => $t['result'] === 'pending')),
]);
