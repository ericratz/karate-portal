<?php
// Legacy standalone activity-log page — superseded first by the combined
// logs.php and now by the React SPA logs route.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

header('Location: app.php#/admin/logs?tab=activity');
exit;
