<?php
// The combined log viewer now lives in the React SPA (app.php#/admin/logs).
// Stub keeps the old URL and its tab/filter parameters working — fixed
// vocabulary only, so no user-supplied string reaches the Location header.
// The ?download_backup=1 export this page used to host is db_backup.php.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

if (($_GET['download_backup'] ?? '') === '1') {
    header('Location: db_backup.php');
    exit;
}

$qs = [];

$vocab = [
    'tab'       => ['activity', 'error', 'mail'],
    'timeframe' => ['day', 'week', 'month', 'year', 'all'],
    'level'     => ['debug', 'info', 'warning', 'error', 'critical'],
    'channel'   => ['auth', 'checkin', 'cron', 'csp', 'email', 'payment', 'php', 'security', 'system'],
    'status'    => ['sent', 'failed'],
];
foreach ($vocab as $param => $allowed) {
    $val = get_str($param);
    $key = array_search($val, $allowed, true);
    if ($key !== false) $qs[] = $param . '=' . $allowed[$key];
}

header('Location: app.php#/admin/logs' . ($qs ? '?' . implode('&', $qs) : ''));
exit;
