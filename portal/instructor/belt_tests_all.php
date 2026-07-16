<?php
// All Belt Tests now lives in the React SPA (app.php#/instructor/belt-tests).
// Stub keeps the old URL and its ?student_id= / ?result= / ?year= filters.

require_once __DIR__ . '/../includes/auth.php';
require_role('instructor', 'admin');

$params = [];

$student_id = get_int('student_id');
if ($student_id > 0) {
    $params[] = 'student_id=' . $student_id;
}

$results = ['pending' => 'pending', 'pass' => 'pass', 'fail' => 'fail'];
$result  = get_str('result');
if (isset($results[$result])) {
    $params[] = 'result=' . $results[$result];
}

$year = get_int('year');
if ($year > 0) {
    $params[] = 'year=' . $year;
}

$route = 'app.php#/instructor/belt-tests';
if (!empty($params)) {
    $route .= '?' . implode('&', $params);
}

header('Location: ' . $route);
exit;
