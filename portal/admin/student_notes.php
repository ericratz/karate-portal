<?php
// Class Notes now live in the React SPA (app.php#/admin/notes). Stub keeps
// the old URL and its ?student_id= parameter working.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$student_id = get_int('student_id');

header('Location: app.php#/admin/notes' . ($student_id ? '?student_id=' . $student_id : ''));
exit;
