<?php
// The injury waiver (view + sign) now lives in the React SPA
// (app.php#/waiver/N), backed by api/v1/parent/waiver.php. Stub keeps the old
// URL: same role gate and ownership check as the PHP page had.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/family.php';
require_role('parent', 'instructor', 'admin');

$student_id = get_int('student_id') ?: post_int('student_id');
if (!$student_id || !family_can_access((int)current_user_id(), $student_id)) {
    header('Location: index.php'); exit;
}

header('Location: app.php#/waiver/' . $student_id);
exit;
