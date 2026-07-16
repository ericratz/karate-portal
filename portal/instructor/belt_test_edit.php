<?php
// The grading chart now lives in the React SPA
// (app.php#/instructor/belt-test-edit). Stub keeps the old URL and its
// ?id= / ?ref_pid= / ?student_id= parameters. Same guard as the old page:
// instructors cannot open existing tests (the full chart is admin-only).

require_once __DIR__ . '/../includes/auth.php';
require_role('instructor', 'admin');

$test_id = get_int('id');
if ($test_id && !has_role('admin')) {
    header('Location: belt_tests_all.php');
    exit;
}

$params = [];
if ($test_id > 0) {
    $params[] = 'id=' . $test_id;
}
$ref_pid = get_int('ref_pid');
if ($ref_pid > 0) {
    $params[] = 'ref_pid=' . $ref_pid;
}
$student_id = get_int('student_id');
if ($student_id > 0) {
    $params[] = 'student_id=' . $student_id;
}

$route = 'app.php#/instructor/belt-test-edit';
if (!empty($params)) {
    $route .= '?' . implode('&', $params);
}

header('Location: ' . $route);
exit;
