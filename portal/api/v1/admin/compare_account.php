<?php
// /api/v1/admin/compare_account.php — the Compare & Link page's data.
// GET ?user_id=N[&student_id=M][&link_request_id=K]: the login account, its
//     existing link, the optional link request, the selected student record
//     (with rank / last-attended / linked-user), and the full student picker.
// POST {action:"link", user_id, student_id, link_request_id} |
//      {action:"dismiss", link_request_id} — same semantics/audits as the
//      old admin/compare_account.php.

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input  = api_read_json();
    $action = api_str($input, 'action');

    if ($action === 'link') {
        $uid  = api_int($input, 'user_id');
        $sid  = api_int($input, 'student_id');
        $lrid = api_int($input, 'link_request_id');
        if (!$uid || !$sid) {
            api_error('Invalid user or student selection.', 422);
        }
        db()->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$uid]);
        db()->prepare('UPDATE students SET user_id = ? WHERE id = ?')->execute([$uid, $sid]);
        audit('link_account', 'user', $uid, "student_id=$sid");
        if ($lrid) {
            try {
                db()->prepare(
                    'UPDATE link_requests SET resolved=1, resolved_at=NOW(), resolved_by=? WHERE id=?'
                )->execute([current_user_id(), $lrid]);
            } catch (Exception $e) {}
        }
        api_respond(['linked' => true]);
    }

    if ($action === 'dismiss') {
        $lrid = api_int($input, 'link_request_id');
        if ($lrid) {
            try {
                db()->prepare(
                    'UPDATE link_requests SET resolved=1, resolved_at=NOW(), resolved_by=? WHERE id=?'
                )->execute([current_user_id(), $lrid]);
            } catch (Exception $e) {}
        }
        api_respond(['dismissed' => true]);
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');

$user_id         = get_int('user_id');
$student_id      = get_int('student_id');
$link_request_id = get_int('link_request_id');

if (!$user_id) api_error('Missing user id', 422);

$u_stmt = db()->prepare('SELECT u.* FROM users u WHERE u.id = ?');
$u_stmt->execute([$user_id]);
$user = $u_stmt->fetch();
if (!$user) api_error('User not found', 404);

$existing_link_stmt = db()->prepare('SELECT id, first_name, last_name FROM students WHERE user_id = ?');
$existing_link_stmt->execute([$user_id]);
$existing_link = $existing_link_stmt->fetch() ?: null;

$link_req = null;
if ($link_request_id) {
    try {
        $lr_stmt = db()->prepare('SELECT * FROM link_requests WHERE id = ? AND user_id = ?');
        $lr_stmt->execute([$link_request_id, $user_id]);
        $link_req = $lr_stmt->fetch() ?: null;
    } catch (Exception $e) {}
}

$student = null;
$student_linked_user = null;
if ($student_id) {
    $s_stmt = db()->prepare(
        'SELECT s.*,
                (SELECT r.kyu_dan FROM student_ranks sr
                 JOIN ranks r ON r.id = sr.rank_id
                 WHERE sr.student_id = s.id
                 ORDER BY r.rank_order DESC LIMIT 1) AS current_rank,
                (SELECT MAX(cs.session_date)
                 FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id
                 WHERE a.student_id = s.id AND a.present = 1) AS last_attended
         FROM students s WHERE s.id = ?'
    );
    $s_stmt->execute([$student_id]);
    $student = $s_stmt->fetch() ?: null;

    if ($student && $student['user_id'] !== null) {
        $sl_stmt = db()->prepare('SELECT id, username FROM users WHERE id = ?');
        $sl_stmt->execute([(int)$student['user_id']]);
        $student_linked_user = $sl_stmt->fetch() ?: null;
    }
}

$all_students = db()->query(
    'SELECT id, first_name, last_name, student_type
     FROM students
     ORDER BY first_name, last_name'
)->fetchAll();

api_respond([
    'user' => [
        'id'            => (int)$user['id'],
        'username'      => (string)$user['username'],
        'first_name'    => $user['first_name'] ?? null,
        'last_name'     => $user['last_name'] ?? null,
        'email'         => $user['email'] ?? null,
        'date_of_birth' => $user['date_of_birth'] ?? null,
        'is_admin'      => (bool)$user['is_admin'],
        'active'        => (bool)$user['active'],
    ],
    'existing_link' => $existing_link !== null ? [
        'id'   => (int)$existing_link['id'],
        'name' => trim($existing_link['first_name'] . ' ' . $existing_link['last_name']),
    ] : null,
    'link_request' => $link_req !== null ? [
        'id'           => (int)$link_req['id'],
        'request_type' => (string)$link_req['request_type'],
        'notes'        => $link_req['notes'] ?? null,
        'created_at'   => (string)$link_req['created_at'],
    ] : null,
    'student' => $student !== null ? [
        'id'                => (int)$student['id'],
        'first_name'        => (string)$student['first_name'],
        'last_name'         => (string)$student['last_name'],
        'email'             => $student['email'] ?? null,
        'date_of_birth'     => $student['date_of_birth'] ?? null,
        'student_type'      => (string)$student['student_type'],
        'current_rank'      => $student['current_rank'] ?? null,
        'last_attended'     => $student['last_attended'] ?? null,
        'registration_date' => $student['registration_date'] ?? null,
        'injury_waiver'     => (bool)$student['injury_waiver'],
        'linked_user'       => $student_linked_user !== null ? [
            'id'       => (int)$student_linked_user['id'],
            'username' => (string)$student_linked_user['username'],
        ] : null,
    ] : null,
    'students' => array_map(fn($s) => [
        'id'           => (int)$s['id'],
        'first_name'   => (string)$s['first_name'],
        'last_name'    => (string)$s['last_name'],
        'student_type' => (string)$s['student_type'],
    ], $all_students),
]);
