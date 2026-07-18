<?php
// Payments now live in the React SPA (app.php#/admin/payments). Stub keeps
// the old URL working, including the dashboard's ?action=add&student_id=N
// &type=… record-payment deep link — int cast + fixed vocabulary only, so no
// user-supplied string reaches the Location header.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$qs = [];

if (get_str('action') === 'add') $qs[] = 'action=add';

$student_id = get_int('student_id');
if ($student_id) $qs[] = 'student_id=' . $student_id;

$types = ['monthly_tuition', 'registration', 'belt_test', 'slc_training', 'seminar', 'other'];
$type  = get_str('type');
$key   = array_search($type, $types, true);
if ($key !== false) $qs[] = 'type=' . $types[$key];

$methods = ['paypal', 'cash', 'check', 'mail'];
$method  = get_str('method');
$key     = array_search($method, $methods, true);
if ($key !== false) $qs[] = 'method=' . $methods[$key];

$year = get_int('year');
if ($year) $qs[] = 'year=' . $year;

header('Location: app.php#/admin/payments' . ($qs ? '?' . implode('&', $qs) : ''));
exit;
