<?php
// GET /api/v1/admin/users.php — every login account with its linked roster
// entry (if any), for the Users page. Same query as the old admin/users.php.
// Read-only: the mutations (toggle active, reset password, link/unlink) live
// on user_profile.php, which is still server-rendered.

require_once __DIR__ . '/../../../includes/api.php';

api_require_method('GET');
api_require_role('admin');
header('Cache-Control: no-store');

$users = db()->query(
    'SELECT u.id, u.username, u.first_name AS u_first, u.last_name AS u_last,
            u.email, u.is_admin, u.active, u.last_login,
            s.first_name, s.last_name, s.id AS student_id, s.student_type
     FROM users u
     LEFT JOIN students s ON s.user_id = u.id
     ORDER BY u.is_admin DESC, u.username'
)->fetchAll();

api_respond([
    'users' => array_map(fn($u) => [
        'id'           => (int)$u['id'],
        'username'     => (string)$u['username'],
        'active'       => (bool)$u['active'],
        'last_login'   => $u['last_login'] ?? null,
        'student_id'   => $u['student_id'] !== null ? (int)$u['student_id'] : null,
        'student_name' => $u['student_id'] !== null ? trim($u['first_name'] . ' ' . $u['last_name']) : null,
        // Same display-role derivation as the old page
        'role'         => $u['is_admin'] ? 'admin' : (string)($u['student_type'] ?? 'student'),
    ], $users),
    'current_user_id' => current_user_id(),
]);
