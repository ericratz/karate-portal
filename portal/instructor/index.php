<?php
// The instructor dashboard now lives in the React SPA (app.php#/instructor).
// Stub keeps the old URL working with the same role gate.

require_once __DIR__ . '/../includes/auth.php';
require_role('instructor', 'admin');

header('Location: app.php#/instructor');
exit;
