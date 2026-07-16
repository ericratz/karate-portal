<?php
// The Classes list now lives in the React SPA (app.php#/instructor/classes).
// Stub keeps the old URL and its ?type= / ?year= filters.

require_once __DIR__ . '/../includes/auth.php';
require_role('instructor', 'admin');

$params = [];

$types = ['class' => 'class', 'seminar' => 'seminar', 'private' => 'private'];
$type  = get_str('type');
if (isset($types[$type])) {
    $params[] = 'type=' . $types[$type];
}

$year = get_int('year');
if ($year > 0) {
    $params[] = 'year=' . $year;
}

$route = 'app.php#/instructor/classes';
if (!empty($params)) {
    $route .= '?' . implode('&', $params);
}

header('Location: ' . $route);
exit;
