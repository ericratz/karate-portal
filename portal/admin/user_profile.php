<?php
// The user-account detail page now lives in the React SPA
// (app.php#/admin/user/N). Stub keeps the old ?id= URL working;
// without an id it falls back to the users list, like the old page.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$id = get_int('id');
if (!$id) {
    header('Location: users.php');
    exit;
}

header('Location: app.php#/admin/user/' . $id);
exit;
