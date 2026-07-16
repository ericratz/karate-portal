<?php
// Take Attendance now lives in the React SPA (app.php#/instructor/attendance).
// Stub keeps the old URL and its ?date=, ?sort=, and ?highlight= parameters
// (all validated/whitelisted before entering the Location header).

require_once __DIR__ . '/../includes/auth.php';
require_role('instructor', 'admin');

$params = [];

// Rebuild the date from integer parts — keeps request taint out of the header
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', get_str('date'), $m)) {
    $params[] = sprintf('date=%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
}

$sorts = ['last_name' => 'last_name', 'last_attended' => 'last_attended'];
$sort  = get_str('sort');
if (isset($sorts[$sort])) {
    $params[] = 'sort=' . $sorts[$sort];
}

$highlight = get_int('highlight');
if ($highlight > 0) {
    $params[] = 'highlight=' . $highlight;
}

$route = 'app.php#/instructor/attendance';
if (!empty($params)) {
    $route .= '?' . implode('&', $params);
}

header('Location: ' . $route);
exit;
