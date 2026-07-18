<?php
// /api/v1/admin/resolve_link.php — the "Needs Manual Linking" resolution page.
// GET ?lr_id=N: the unresolved needs_linking request (user + auto-created
//     duplicate record) plus the unlinked candidate student records.
// POST {lr_id, real_student_id}: link the user to the real record, delete the
//     auto-created guest duplicate, resolve the alert — same transaction and
//     audit as the old admin/resolve_link.php.

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('admin');

function load_link_request(int $lr_id): array {
    $lr_stmt = db()->prepare(
        'SELECT lr.id, lr.created_at, lr.student_id,
                u.id AS user_id, u.username, u.is_admin,
                u.first_name AS u_first, u.last_name AS u_last,
                u.email AS u_email, u.date_of_birth AS u_dob,
                s.id AS dup_id, s.first_name AS s_first, s.last_name AS s_last,
                s.date_of_birth AS s_dob, s.email AS s_email, s.student_type AS s_type
         FROM link_requests lr
         JOIN users u ON u.id = lr.user_id
         LEFT JOIN students s ON s.id = lr.student_id
         WHERE lr.id = ? AND lr.request_type = \'needs_linking\' AND lr.resolved = 0
         LIMIT 1'
    );
    $lr_stmt->execute([$lr_id]);
    $lr = $lr_stmt->fetch();
    if (!$lr) api_error('Link request not found or already resolved', 404);
    return $lr;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input = api_read_json();
    $lr_id = api_int($input, 'lr_id');
    if (!$lr_id) api_error('Missing link request id', 422);
    $lr = load_link_request($lr_id);

    $real_student_id = api_int($input, 'real_student_id');
    if (!$real_student_id) {
        api_error('Please select a student record to link to.', 422);
    }

    try {
        db()->beginTransaction();

        // Verify real student record exists and is still unlinked (or already linked to this user)
        $rs_stmt = db()->prepare(
            'SELECT id, student_type FROM students WHERE id = ? AND (user_id IS NULL OR user_id = ?)'
        );
        $rs_stmt->execute([$real_student_id, $lr['user_id']]);
        $real = $rs_stmt->fetch();
        if (!$real) {
            throw new Exception('That student record is no longer available — it may have already been linked.');
        }

        db()->prepare('UPDATE students SET user_id = ? WHERE id = ?')
             ->execute([$lr['user_id'], $real_student_id]);

        // Delete the auto-created duplicate guest record (if different from real)
        if ($lr['dup_id'] && (int)$lr['dup_id'] !== $real_student_id) {
            db()->prepare('DELETE FROM students WHERE id = ? AND student_type = \'guest\'')
                 ->execute([$lr['dup_id']]);
        }

        db()->prepare(
            'UPDATE link_requests SET resolved = 1, resolved_at = NOW(), resolved_by = ? WHERE id = ?'
        )->execute([current_user_id(), $lr_id]);

        db()->commit();
        audit('resolve_link', 'link_requests', $lr_id,
              "user={$lr['user_id']} linked_to=student:$real_student_id dup_deleted={$lr['dup_id']}");

        api_respond(['linked' => true]);
    } catch (Exception $e) {
        db()->rollBack();
        log_event('error', 'system', 'Link resolution failed', ['message' => $e->getMessage()]);
        api_error($e->getMessage(), 422);
    }
}

api_require_method('GET');

$lr_id = get_int('lr_id');
if (!$lr_id) api_error('Missing link request id', 422);
$lr = load_link_request($lr_id);

$candidates_stmt = db()->prepare(
    'SELECT s.id, s.first_name, s.last_name, s.date_of_birth, s.email, s.city_state_zip, s.student_type,
            (SELECT r.name FROM student_ranks sr JOIN ranks r ON r.id = sr.rank_id
             WHERE sr.student_id = s.id ORDER BY r.rank_order DESC LIMIT 1) AS rank_name
     FROM students s
     WHERE s.user_id IS NULL AND s.id != ?
     ORDER BY s.first_name, s.last_name'
);
$candidates_stmt->execute([$lr['dup_id'] ?? 0]);
$candidates = $candidates_stmt->fetchAll();

api_respond([
    'request' => [
        'id'         => (int)$lr['id'],
        'created_at' => (string)$lr['created_at'],
        'username'   => (string)$lr['username'],
        'user_name'  => trim(($lr['u_first'] ?? '') . ' ' . ($lr['u_last'] ?? '')),
        'user_email' => $lr['u_email'] ?? null,
        'user_dob'   => $lr['u_dob'] ?? null,
        'duplicate'  => $lr['dup_id'] !== null ? [
            'id'            => (int)$lr['dup_id'],
            'name'          => trim(($lr['s_first'] ?? '') . ' ' . ($lr['s_last'] ?? '')),
            'date_of_birth' => $lr['s_dob'] ?? null,
            'email'         => $lr['s_email'] ?? null,
            'student_type'  => $lr['s_type'] ?? null,
        ] : null,
    ],
    'candidates' => array_map(fn($c) => [
        'id'            => (int)$c['id'],
        'first_name'    => (string)$c['first_name'],
        'last_name'     => (string)$c['last_name'],
        'date_of_birth' => $c['date_of_birth'] ?? null,
        'email'         => $c['email'] ?? null,
        'student_type'  => (string)$c['student_type'],
        'rank_name'     => $c['rank_name'] ?? null,
    ], $candidates),
]);
