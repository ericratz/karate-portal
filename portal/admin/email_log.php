<?php
// Legacy standalone email-log page — superseded first by the combined
// logs.php and now by the React SPA logs route.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$qs = ['tab=mail'];
$status = get_str('status');
$key = array_search($status, ['sent', 'failed'], true);
if ($key !== false) $qs[] = 'status=' . ['sent', 'failed'][$key];

header('Location: app.php#/admin/logs?' . implode('&', $qs));
exit;
