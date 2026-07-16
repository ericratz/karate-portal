<?php
// /api/v1/instructor/belt_tests.php — the All Belt Tests page.
// GET  ?student_id&result&year → filtered test list + filter option sources.
// POST {action:"delete", id}   → removes a test (audited), same as the old
//                                htmx delete on instructor/belt_tests_all.php.

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('instructor', 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input = api_read_json();
    if (api_str($input, 'action') !== 'delete') {
        api_error('Unknown action', 400);
    }
    $del_id = api_int($input, 'id');
    if ($del_id <= 0) {
        api_error('Invalid id', 422);
    }
    db()->prepare('DELETE FROM belt_tests WHERE id=?')->execute([$del_id]);
    audit('delete_belt_test', 'belt_test', $del_id);
    api_respond(['deleted' => true]);
}

api_require_method('GET');

$f_student = get_int('student_id');
$f_result  = in_array(get_str('result'), ['pending', 'pass', 'fail'], true) ? get_str('result') : '';
$f_year    = get_int('year');

$where  = ['1=1'];
$params = [];
if ($f_student)      { $where[] = 'bt.student_id = ?';     $params[] = $f_student; }
if ($f_result !== '') { $where[] = 'bt.result = ?';          $params[] = $f_result; }
if ($f_year)         { $where[] = 'YEAR(bt.test_date) = ?'; $params[] = $f_year; }

// Years available for the dropdown — actual test years plus the current year
$years = db()->query('SELECT DISTINCT YEAR(test_date) AS y FROM belt_tests ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
$years = array_map('intval', $years);
if (!in_array((int)date('Y'), $years, true)) {
    array_unshift($years, (int)date('Y'));
}

$stmt = db()->prepare(
    'SELECT bt.id, bt.test_date, bt.result, bt.score, bt.fee_paid, bt.belt_awarded, bt.notes,
            s.id AS student_id, s.first_name, s.last_name,
            r.kyu_dan, r.name AS rank_name
     FROM belt_tests bt
     JOIN students s ON s.id = bt.student_id
     JOIN ranks r    ON r.id = bt.rank_testing_for
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY bt.test_date DESC, s.first_name, s.last_name'
);
$stmt->execute($params);

$students = db()->query(
    'SELECT id, first_name, last_name FROM students ORDER BY first_name, last_name'
)->fetchAll();

api_respond([
    'tests' => array_map(fn($t) => [
        'id'           => (int)$t['id'],
        'test_date'    => $t['test_date'],
        'result'       => (string)$t['result'],
        'score'        => $t['score'] !== null ? (int)$t['score'] : null,
        'fee_paid'     => (bool)$t['fee_paid'],
        'belt_awarded' => (bool)$t['belt_awarded'],
        'notes'        => $t['notes'] ?? null,
        'student_id'   => (int)$t['student_id'],
        'student'      => $t['first_name'] . ' ' . $t['last_name'],
        'kyu_dan'      => (string)$t['kyu_dan'],
        'rank_name'    => (string)$t['rank_name'],
    ], $stmt->fetchAll()),
    'years'    => $years,
    'students' => array_map(fn($s) => [
        'id'   => (int)$s['id'],
        'name' => $s['first_name'] . ' ' . $s['last_name'],
    ], $students),
    'is_admin' => ($_SESSION['role'] ?? '') === 'admin',
]);
