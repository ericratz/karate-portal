<?php
// The student editor now lives in the React SPA (app.php#/admin/student-edit).
// Stub keeps the old URL working: with ?id=N it opens that student, without
// one it opens the new-student form. (The old ?ref= and ?prefill_* params
// were display-only / unused and are dropped.)

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$id = get_int('id');

header('Location: app.php#/admin/student-edit' . ($id ? '?id=' . $id : ''));
exit;
