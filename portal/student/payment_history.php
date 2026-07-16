<?php
// Payment history now lives in the React SPA (app.php#/payments/N).
// Stub keeps the old URL: same login gate, no-record fallback to index.php.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/family.php';
require_login();

$own = family_own_student((int)current_user_id());
if ($own === null) {
    header('Location: index.php'); exit;
}

header('Location: app.php#/payments/' . (int)$own['id']);
exit;
