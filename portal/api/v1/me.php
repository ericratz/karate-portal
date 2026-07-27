<?php
// GET /api/v1/me.php — SPA session bootstrap.
// Returns the logged-in identity plus the CSRF token the client must echo
// back in X-CSRF-Token on every mutating request.

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/family.php';

api_require_method('GET');
if (empty($_SESSION['user_id'])) {
    api_error('Not logged in', 401);
}

// The navbar needs these on every page, not just the dashboard: an instructor
// who also has a roster record gets "My Profile" / "Make a Payment" entries,
// and where those point depends on whether they have linked children. Fetching
// them here keeps Layout on the session it already has rather than making the
// chrome depend on a per-page endpoint.
$own          = family_own_student((int)current_user_id());
$own_id       = $own !== null ? (int)$own['id'] : 0;
$has_children = $own_id > 0 && count(family_child_ids($own_id)) > 0;

api_respond([
    'user_id'    => current_user_id(),
    'username'   => (string)($_SESSION['username'] ?? ''),
    'role'       => (string)($_SESSION['role'] ?? ''),
    'csrf_token' => csrf_token(),
    'own_student_id' => $own_id,
    'has_children'   => $has_children,
    // What the PHP side is running. The bundle compares it against the version
    // compiled into itself to catch a half-uploaded deploy — see version.php.
    'app_version' => APP_VERSION,
]);
