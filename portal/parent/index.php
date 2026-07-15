<?php
// The tabbed family dashboard now lives in the React SPA (app.php — source in
// frontend/). This stub keeps the old URL working: same role gate, same
// ?student_id= deep link, then hands off to the SPA route.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/family.php';
require_role('parent', 'instructor', 'admin');

$student_id = get_int('student_id');
if ($student_id && family_can_access((int)current_user_id(), $student_id)) {
    header('Location: app.php#/student/' . $student_id);
} else {
    // No/invalid/unlinked id — the SPA falls back to the default tab
    header('Location: app.php');
}
exit;
