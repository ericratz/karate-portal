<?php
// Legacy standalone error-log page — superseded first by the combined
// logs.php and now by the React SPA logs route. Forwards its level/channel
// filters from a fixed vocabulary.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$qs = ['tab=error'];

$vocab = [
    'level'   => ['debug', 'info', 'warning', 'error', 'critical'],
    'channel' => ['auth', 'checkin', 'cron', 'csp', 'email', 'payment', 'php', 'security', 'system'],
];
foreach ($vocab as $param => $allowed) {
    $val = get_str($param);
    $key = array_search($val, $allowed, true);
    if ($key !== false) $qs[] = $param . '=' . $allowed[$key];
}

header('Location: app.php#/admin/logs?' . implode('&', $qs));
exit;
