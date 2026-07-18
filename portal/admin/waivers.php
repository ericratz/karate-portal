<?php
// Exemptions now live in the React SPA (app.php#/admin/waivers). Stub keeps
// the old URL and its filter parameters working — int cast + fixed vocabulary
// only, so no user-supplied string reaches the Location header.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$qs = [];

$student_id = get_int('student_id');
if ($student_id) $qs[] = 'student_id=' . $student_id;

$types = ['monthly_tuition', 'registration', 'belt_test', 'slc_training', 'seminar', 'all'];
$type  = get_str('type');
$key   = array_search($type, $types, true);
if ($key !== false) $qs[] = 'type=' . $types[$key];

$year = get_int('year');
if ($year) $qs[] = 'year=' . $year;

header('Location: app.php#/admin/waivers' . ($qs ? '?' . implode('&', $qs) : ''));
exit;
