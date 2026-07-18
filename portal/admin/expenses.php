<?php
// Expenses now live in the React SPA (app.php#/admin/expenses). Stub keeps
// the old URL and its filter parameters working — int cast + fixed vocabulary
// only, so no user-supplied string reaches the Location header.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$qs = [];

$types = ['rent', 'equipment', 'utilities', 'supplies', 'other'];
$type  = get_str('type');
$key   = array_search($type, $types, true);
if ($key !== false) $qs[] = 'type=' . $types[$key];

$year = get_int('year');
if ($year) $qs[] = 'year=' . $year;

header('Location: app.php#/admin/expenses' . ($qs ? '?' . implode('&', $qs) : ''));
exit;
