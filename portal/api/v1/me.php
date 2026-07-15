<?php
// GET /api/v1/me.php — SPA session bootstrap.
// Returns the logged-in identity plus the CSRF token the client must echo
// back in X-CSRF-Token on every mutating request.

require_once __DIR__ . '/../../includes/api.php';

api_require_method('GET');
if (empty($_SESSION['user_id'])) {
    api_error('Not logged in', 401);
}

api_respond([
    'user_id'    => current_user_id(),
    'username'   => (string)($_SESSION['username'] ?? ''),
    'role'       => (string)($_SESSION['role'] ?? ''),
    'csrf_token' => csrf_token(),
]);
