<?php
// The instructor student profile now lives in the React SPA
// (app.php#/instructor/student/N). Stub keeps the old URL working: students
// (who could formerly view their own record here) go to their own dashboard,
// instructors/admins land on the SPA route, missing ids fall back to the
// instructor index — same outcomes as the old page's guards.

require_once __DIR__ . '/../includes/auth.php';
require_role('student', 'instructor', 'admin');

if (!has_role('instructor', 'admin')) {
    // Student role — their profile lives on their own SPA dashboard now
    header('Location: ../student/index.php');
    exit;
}

$id = get_int('id');
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

header('Location: app.php#/instructor/student/' . $id);
exit;
