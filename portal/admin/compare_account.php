<?php
// The Compare & Link page now lives in the React SPA (app.php#/admin/compare).
// Stub keeps the old URL working — int cast only, so no user-supplied string
// reaches the Location header. Without a user_id it falls back to the
// dashboard, like the old page.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$user_id = get_int('user_id');
if (!$user_id) {
    header('Location: index.php');
    exit;
}

$qs = ['user_id=' . $user_id];
$student_id = get_int('student_id');
if ($student_id) $qs[] = 'student_id=' . $student_id;
$link_request_id = get_int('link_request_id');
if ($link_request_id) $qs[] = 'link_request_id=' . $link_request_id;

header('Location: app.php#/admin/compare?' . implode('&', $qs));
exit;
