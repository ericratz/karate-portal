<?php
// Class-session instructor helpers — who taught a given class.
//
// An instructor is EITHER a roster person (a `students` row typed instructor/
// admin — most teach but have no login) OR an admin login with no roster record
// (e.g. Noji). So class_session_instructors carries a nullable student_id AND a
// nullable user_id; exactly one is set per row. Callers address instructors by
// a string "key": "s:<student_id>" for a roster person, "u:<user_id>" for an
// admin login. Kept in one place (like includes/family.php) and unit-tested
// (see InstructorSessionTest).

require_once __DIR__ . '/db.php';

/**
 * Selectable instructors: roster people typed instructor/admin, plus any admin
 * login that has no roster record (so the owner account still appears). Admin
 * logins first, then roster people by name.
 * @return list<array{key:string,name:string}>
 */
function instructor_options(): array {
    $admins = db()->query(
        "SELECT CONCAT('u:', u.id) AS k,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS name
         FROM users u
         LEFT JOIN students s ON s.user_id = u.id
         WHERE u.is_admin = 1 AND u.active = 1 AND s.id IS NULL
         ORDER BY name"
    )->fetchAll();
    $roster = db()->query(
        "SELECT CONCAT('s:', id) AS k,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), CONCAT('#', id)) AS name
         FROM students
         WHERE active = 1 AND student_type IN ('instructor','admin')
         ORDER BY name"
    )->fetchAll();
    return array_values(array_map(
        static fn($r) => ['key' => (string)$r['k'], 'name' => (string)$r['name']],
        array_merge($admins, $roster)
    ));
}

/** Split instructor keys into student ids and user ids. @param list<mixed> $keys @return array{s:list<int>,u:list<int>} */
function parse_instructor_keys(array $keys): array {
    $s = [];
    $u = [];
    foreach ($keys as $k) {
        if (!is_string($k) || !preg_match('/^([su]):(\d+)$/', $k, $m)) continue;
        if ($m[1] === 's') $s[] = (int)$m[2]; else $u[] = (int)$m[2];
    }
    return ['s' => array_values(array_unique($s)), 'u' => array_values(array_unique($u))];
}

/**
 * Reduce caller-supplied keys to the ones that name a real, active instructor —
 * a roster person typed instructor/admin, or an active admin login. Stops a
 * tampered request from attaching arbitrary people to a session.
 * @param array<array-key,mixed> $keys
 * @return list<string>
 */
function filter_instructor_keys(array $keys): array {
    $p    = parse_instructor_keys($keys);
    $keep = [];
    if ($p['s']) {
        $ph   = implode(',', array_fill(0, count($p['s']), '?'));
        $stmt = db()->prepare(
            "SELECT id FROM students WHERE id IN ($ph) AND active = 1 AND student_type IN ('instructor','admin')"
        );
        $stmt->execute($p['s']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $keep[] = 's:' . (int)$id;
    }
    if ($p['u']) {
        $ph   = implode(',', array_fill(0, count($p['u']), '?'));
        $stmt = db()->prepare("SELECT id FROM users WHERE id IN ($ph) AND active = 1 AND is_admin = 1");
        $stmt->execute($p['u']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $keep[] = 'u:' . (int)$id;
    }
    return $keep;
}

/**
 * Default taught-by set for a not-yet-recorded class: the primary admin (the
 * founder account, usually Noji) — as their roster key if they have one, else
 * their user key. [] if there is somehow no active admin.
 * @return list<string>
 */
function default_instructor_keys(): array {
    $row = db()->query(
        "SELECT u.id AS uid, s.id AS sid
         FROM users u LEFT JOIN students s ON s.user_id = u.id
         WHERE u.is_admin = 1 AND u.active = 1 ORDER BY u.id LIMIT 1"
    )->fetch();
    if ($row === false) return [];
    return [$row['sid'] !== null ? 's:' . (int)$row['sid'] : 'u:' . (int)$row['uid']];
}

/**
 * The recorded taught-by set for a session, as keys.
 * @return list<string>
 */
function session_instructor_keys(int $session_id): array {
    $stmt = db()->prepare('SELECT student_id, user_id FROM class_session_instructors WHERE session_id = ? ORDER BY id');
    $stmt->execute([$session_id]);
    $keys = [];
    foreach ($stmt->fetchAll() as $r) {
        $keys[] = $r['student_id'] !== null ? 's:' . (int)$r['student_id'] : 'u:' . (int)$r['user_id'];
    }
    return $keys;
}

/**
 * Replace a session's taught-by set with the given keys (already validated via
 * filter_instructor_keys). Full rewrite, like the attendance rows.
 * @param list<string> $keys
 */
function set_session_instructors(int $session_id, array $keys): void {
    db()->prepare('DELETE FROM class_session_instructors WHERE session_id = ?')->execute([$session_id]);
    if (!$keys) return;
    $ins = db()->prepare(
        'INSERT INTO class_session_instructors (session_id, student_id, user_id) VALUES (?,?,?)'
    );
    foreach ($keys as $k) {
        if (!preg_match('/^([su]):(\d+)$/', $k, $m)) continue;
        $ins->execute([$session_id, $m[1] === 's' ? (int)$m[2] : null, $m[1] === 'u' ? (int)$m[2] : null]);
    }
}
