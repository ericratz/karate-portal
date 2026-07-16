<?php
// /api/v1/instructor/attendance.php — the Take Attendance page.
//
// GET  ?date=YYYY-MM-DD             → every student with a present flag for
//                                     that date's session (plus class type).
// POST {action:"save", date, class_type, present_ids: [...]}
//      → upserts the session and rewrites its attendance rows; deletes the
//        session when nobody is present (empty classes aren't kept).
// POST {action:"delete_session", date} → removes the session + its records.
//
// Mirrors instructor/attendance.php exactly, including audit entries.

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('instructor', 'admin');

/**
 * Validate a YYYY-MM-DD string and rebuild it from integer parts — the
 * round-trip strips request taint so the value can be echoed/stored safely.
 */
function clean_date(string $d): ?string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return null;
    return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input  = api_read_json();
    $action = api_str($input, 'action', 'save');
    $date   = clean_date(api_str($input, 'date'));

    if ($date === null) {
        api_error('Invalid date.', 422);
    }

    if ($action === 'delete_session') {
        $sid_q = db()->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
        $sid_q->execute([$date]);
        $del_sid = $sid_q->fetchColumn();
        if ($del_sid) {
            db()->prepare('DELETE FROM attendance WHERE session_id = ?')->execute([$del_sid]);
            db()->prepare('DELETE FROM class_sessions WHERE id = ?')->execute([$del_sid]);
            audit('delete_session', 'session', (int)$del_sid, "date=$date");
        }
        api_respond(['deleted' => (bool)$del_sid]);
    }

    if ($action === 'save') {
        $class_type = api_str($input, 'class_type', 'class');
        if (!in_array($class_type, ['class', 'seminar', 'private'], true)) {
            $class_type = 'class';
        }
        $present_raw = $input['present_ids'] ?? [];
        $present_ids = array_map('intval', is_array($present_raw) ? $present_raw : []);

        $db = db();
        $db->prepare(
            'INSERT INTO class_sessions (session_date, class_type, instructor_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE instructor_id = VALUES(instructor_id), class_type = VALUES(class_type)'
        )->execute([$date, $class_type, current_user_id()]);

        $sid_q = $db->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
        $sid_q->execute([$date]);
        $session_id = $sid_q->fetchColumn();

        $db->prepare('DELETE FROM attendance WHERE session_id = ?')->execute([$session_id]);
        $ins = $db->prepare(
            'INSERT INTO attendance (student_id, session_id, present, recorded_by) VALUES (?,?,1,?)'
        );
        foreach ($present_ids as $sid) {
            $ins->execute([$sid, $session_id, current_user_id()]);
        }

        if (empty($present_ids)) {
            // No one present — an empty class isn't worth keeping a record of
            $db->prepare('DELETE FROM class_sessions WHERE id = ?')->execute([$session_id]);
            api_respond(['saved' => 0, 'removed' => true]);
        }

        api_respond(['saved' => count($present_ids), 'removed' => false]);
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');

$date = clean_date(get_str('date', date('Y-m-d'))) ?? date('Y-m-d');

$session = db()->prepare('SELECT id, class_type FROM class_sessions WHERE session_date = ?');
$session->execute([$date]);
$session_row = $session->fetch();
$session_id  = $session_row ? $session_row['id'] : false;

$stmt = db()->prepare(
    'SELECT s.id, s.first_name, s.last_name, s.student_type, s.injury_waiver,
            COALESCE(a.present, 0) AS present,
            (SELECT MAX(cs2.session_date)
             FROM attendance a2
             JOIN class_sessions cs2 ON cs2.id = a2.session_id
             WHERE a2.student_id = s.id AND a2.present = 1) AS last_attended
     FROM students s
     LEFT JOIN attendance a ON a.session_id = ? AND a.student_id = s.id AND a.present = 1
     ORDER BY s.first_name, s.last_name'
);
$stmt->execute([$session_id]);

api_respond([
    'date'           => $date,
    'session_exists' => (bool)$session_row,
    'class_type'     => $session_row['class_type'] ?? 'class',
    'students'       => array_map(fn($s) => [
        'id'            => (int)$s['id'],
        'first_name'    => (string)$s['first_name'],
        'last_name'     => (string)$s['last_name'],
        'student_type'  => (string)$s['student_type'],
        'injury_waiver' => (bool)$s['injury_waiver'],
        'present'       => (bool)$s['present'],
        'last_attended' => $s['last_attended'] ?? null,
    ], $stmt->fetchAll()),
]);
