<?php
// The bulk-email page now lives in the React SPA (app.php#/admin/email).

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

header('Location: app.php#/admin/email');
exit;
