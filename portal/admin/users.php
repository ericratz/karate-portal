<?php
// The user accounts list now lives in the React SPA (app.php#/admin/users).
// The old page's POST handlers (toggle/unlink/reset/link) were dead code —
// their forms live on user_profile.php — so nothing else to keep here.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$route = 'app.php#/admin/users';
if (($_GET['msg'] ?? '') === 'deleted') $route .= '?msg=deleted';

header('Location: ' . $route);
exit;
