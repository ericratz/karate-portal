<?php
// The admin dashboard now lives in the React SPA (app.php#/admin). Stub keeps
// the old URL (and the ?linked=1 flash the linking flows redirect back with)
// working. The dashboard's side effects (auto-inactive pass, log retention)
// run in api/v1/admin/dashboard.php on every load, as before.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$route = 'app.php#/admin';
if (isset($_GET['linked'])) $route .= '?linked=1';

header('Location: ' . $route);
exit;
