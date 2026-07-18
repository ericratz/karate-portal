<?php
// /api/v1/admin/user_profile.php — one login account's detail page.
// GET ?id=N: account row + linked roster entry + the unlinked-students list
//            for the link picker.
// POST {action, id, ...}: update_account | reset_password | toggle_active |
//            unlink | link_student | delete_user — same validation, guards,
//            and audit entries as the old admin/user_profile.php. One fix
//            carried in deliberately: the old page's self-edit read
//            $user['is_admin'] before $user was loaded, silently clearing
//            your own admin flag on save; here the current DB value is kept.

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('admin');

function load_user(int $id): array {
    $stmt = db()->prepare(
        'SELECT u.*, s.id AS student_id,
                s.first_name AS student_first_name, s.last_name AS student_last_name,
                s.student_type
         FROM users u
         LEFT JOIN students s ON s.user_id = u.id
         WHERE u.id = ?'
    );
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) api_error('User not found', 404);
    return $user;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input  = api_read_json();
    $action = api_str($input, 'action');
    $id     = api_int($input, 'id');
    if (!$id) api_error('Missing user id', 422);
    $user = load_user($id);

    if ($action === 'update_account') {
        $username   = trim(api_str($input, 'username'));
        $email      = trim(api_str($input, 'email'));
        $first_name = trim(api_str($input, 'first_name'));
        $last_name  = trim(api_str($input, 'last_name'));
        $dob        = trim(api_str($input, 'date_of_birth'));
        // Never let an admin clear their own admin flag
        $is_admin = ($id !== current_user_id())
            ? (api_bool($input, 'is_admin') ? 1 : 0)
            : (int)$user['is_admin'];
        if (!$username) {
            api_error('Username is required.', 422);
        }
        $chk = db()->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $chk->execute([$username, $id]);
        if ($chk->fetch()) {
            api_error('That username is already taken.', 422);
        }
        // Account details live entirely on users — student record is managed separately via student_edit
        db()->prepare('UPDATE users SET username=?, email=?, is_admin=?, first_name=?, last_name=?, date_of_birth=? WHERE id=?')
             ->execute([$username, $email ?: null, $is_admin, $first_name ?: null, $last_name ?: null, $dob ?: null, $id]);
        audit('update_user', 'user', $id);
        api_respond(['saved' => true]);
    }

    if ($action === 'reset_password') {
        $pass = trim(api_str($input, 'new_password'));
        if (strlen($pass) < 8) {
            api_error('Password must be at least 8 characters.', 422);
        }
        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
             ->execute([password_hash($pass, PASSWORD_BCRYPT), $id]);
        audit('reset_password', 'user', $id);
        api_respond(['saved' => true]);
    }

    if ($action === 'toggle_active') {
        if ($id !== current_user_id()) {
            db()->prepare('UPDATE users SET active = IF(active=1,0,1) WHERE id=?')->execute([$id]);
            audit('toggle_user_active', 'user', $id);
        }
        api_respond(['saved' => true]);
    }

    if ($action === 'unlink') {
        db()->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$id]);
        audit('unlink_user', 'user', $id);
        api_respond(['saved' => true]);
    }

    if ($action === 'link_student') {
        $sid = api_int($input, 'student_id');
        if (!$sid) api_error('Missing student id', 422);
        db()->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$id]);
        db()->prepare('UPDATE students SET user_id = ? WHERE id = ?')->execute([$id, $sid]);
        audit('link_user', 'user', $id, "student_id=$sid");
        api_respond(['saved' => true]);
    }

    if ($action === 'delete_user') {
        if ($id === current_user_id()) {
            api_error('You cannot delete your own account.', 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $pdo->commit();
            audit('delete_user', 'user', $id);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            log_event('error', 'system', 'User delete failed', ['user_id' => $id, 'message' => $e->getMessage()]);
            api_error('Delete failed — no changes were made.', 500);
        }
        api_respond(['deleted' => true]);
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');

$id = get_int('id');
if (!$id) api_error('Missing user id', 422);
$user = load_user($id);

$unlinked = db()->query(
    'SELECT id, first_name, last_name, student_type
     FROM students
     WHERE user_id IS NULL
     ORDER BY first_name, last_name'
)->fetchAll();

api_respond([
    'user' => [
        'id'            => (int)$user['id'],
        'username'      => (string)$user['username'],
        'email'         => $user['email'] ?? null,
        'first_name'    => $user['first_name'] ?? null,
        'last_name'     => $user['last_name'] ?? null,
        'date_of_birth' => $user['date_of_birth'] ?? null,
        'is_admin'      => (bool)$user['is_admin'],
        'active'        => (bool)$user['active'],
        'created_at'    => (string)$user['created_at'],
        'last_login'    => $user['last_login'] ?? null,
        'student_id'    => $user['student_id'] !== null ? (int)$user['student_id'] : null,
        'student_name'  => $user['student_id'] !== null
                           ? trim($user['student_first_name'] . ' ' . $user['student_last_name']) : null,
        'student_type'  => $user['student_type'] ?? null,
    ],
    'unlinked' => array_map(fn($s) => [
        'id'           => (int)$s['id'],
        'first_name'   => (string)$s['first_name'],
        'last_name'    => (string)$s['last_name'],
        'student_type' => (string)$s['student_type'],
    ], $unlinked),
    'current_user_id' => current_user_id(),
]);
