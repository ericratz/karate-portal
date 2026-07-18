<?php
// The Resolve Linking page now lives in the React SPA
// (app.php#/admin/resolve-link). Stub keeps the old ?lr_id= URL working;
// without one it falls back to the dashboard, like the old page.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$lr_id = get_int('lr_id');
if (!$lr_id) {
    header('Location: ./');
    exit;
}

header('Location: app.php#/admin/resolve-link?lr_id=' . $lr_id);
exit;
