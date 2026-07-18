<?php
// Donations now live in the React SPA (app.php#/admin/donations). Stub keeps
// the old URL and its filter parameters working — int cast + fixed vocabulary
// only, so no user-supplied string reaches the Location header.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$qs = [];

$methods = ['paypal', 'cash', 'check', 'mail'];
$method  = get_str('method');
$key     = array_search($method, $methods, true);
if ($key !== false) $qs[] = 'method=' . $methods[$key];

$year = get_int('year');
if ($year) $qs[] = 'year=' . $year;

header('Location: app.php#/admin/donations' . ($qs ? '?' . implode('&', $qs) : ''));
exit;
