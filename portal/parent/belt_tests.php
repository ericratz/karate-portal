<?php
// Belt test history now lives in the React SPA (app.php#/belt-tests/N).
// Stub keeps the old URL: same parent-only gate and ownership check, invalid
// ids bounce to the dashboard exactly as the PHP page did.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/family.php';
require_role('parent');

$student_id = get_int('student_id');
if (!$student_id || !family_can_access((int)current_user_id(), $student_id)) {
    header('Location: index.php'); exit;
}

header('Location: app.php#/belt-tests/' . $student_id);
exit;
