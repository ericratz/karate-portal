<?php
// The roster now lives in the React SPA (app.php#/instructor/roster).
// Stub keeps the old URL and its ?sort= parameter working.

require_once __DIR__ . '/../includes/auth.php';
require_role('instructor', 'admin');

$route = 'app.php#/instructor/roster';
if (($_GET['sort'] ?? '') === 'last_name') {
    $route .= '?sort=last_name';
}

header('Location: ' . $route);
exit;
