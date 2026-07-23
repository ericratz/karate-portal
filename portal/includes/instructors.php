<?php
// Class-session instructor helpers — who taught a given class. The taught-by
// set is a many-to-many in class_session_instructors (see karate_schema.sql);
// these functions are the single source of truth for who *counts* as an
// instructor and are shared by the take-attendance and classes-list endpoints.
// Kept in one place (like includes/family.php) so the "instructor/admin only"
// rule and the picker default live together and can be unit-tested directly
// (see InstructorSessionTest).

require_once __DIR__ . '/db.php';

/**
 * Selectable instructors: active admin accounts plus instructor-type users,
 * admins first then by display name.
 * @return list<array{id:int,name:string}>
 */
function instructor_users(): array {
    $rows = db()->query(
        "SELECT u.id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS name
         FROM users u
         LEFT JOIN students s ON s.user_id = u.id
         WHERE u.active = 1 AND (u.is_admin = 1 OR s.student_type = 'instructor')
         GROUP BY u.id
         ORDER BY u.is_admin DESC, name"
    )->fetchAll();
    return array_values(array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $rows));
}

/**
 * Reduce a caller-supplied id list to the ones that belong to a real, active
 * instructor/admin account — so a tampered request can't attach arbitrary
 * users to a session. De-duplicates and coerces to ints.
 * @param array<array-key,mixed> $ids
 * @return list<int>
 */
function filter_instructor_ids(array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT u.id FROM users u
         LEFT JOIN students s ON s.user_id = u.id
         WHERE u.id IN ($ph) AND u.active = 1
           AND (u.is_admin = 1 OR s.student_type = 'instructor')"
    );
    $stmt->execute($ids);
    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

/**
 * The default taught-by set for a not-yet-recorded class: the primary admin
 * (lowest-id active admin — the founder account, usually Noji), or [] if there
 * is somehow no active admin.
 * @return list<int>
 */
function default_instructor_ids(): array {
    $id = db()->query('SELECT id FROM users WHERE is_admin = 1 AND active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    return $id === false ? [] : [(int)$id];
}

/**
 * The recorded taught-by set for a session, ascending by user id.
 * @return list<int>
 */
function session_instructor_ids(int $session_id): array {
    $stmt = db()->prepare('SELECT user_id FROM class_session_instructors WHERE session_id = ? ORDER BY user_id');
    $stmt->execute([$session_id]);
    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

/**
 * Replace a session's taught-by set with the given ids (which the caller has
 * already validated via filter_instructor_ids). A full rewrite, like the
 * attendance rows.
 * @param list<int> $ids
 */
function set_session_instructors(int $session_id, array $ids): void {
    db()->prepare('DELETE FROM class_session_instructors WHERE session_id = ?')->execute([$session_id]);
    if (!$ids) {
        return;
    }
    $ins = db()->prepare('INSERT INTO class_session_instructors (session_id, user_id) VALUES (?,?)');
    foreach ($ids as $uid) {
        $ins->execute([$session_id, (int)$uid]);
    }
}
