<?php
// GET /api/v1/instructor/sessions.php — the Classes list: every session with
// its present-count and attendee names (for the expandable row), filterable
// by ?type= and ?year=. Mirrors instructor/attendance_sessions.php.

require_once __DIR__ . '/../../../includes/api.php';

api_require_method('GET');
api_require_role('instructor', 'admin');

$valid_types = ['class', 'seminar', 'private'];
$type_filter = in_array(get_str('type'), $valid_types, true) ? get_str('type') : null;
$year_filter = get_int('year');

$where  = [];
$params = [];
if ($type_filter !== null) {
    $where[]  = 'cs.class_type = ?';
    $params[] = $type_filter;
}
if ($year_filter) {
    $where[]  = 'YEAR(cs.session_date) = ?';
    $params[] = $year_filter;
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Years available for the dropdown — actual class years plus the current year
$years = db()->query('SELECT DISTINCT YEAR(session_date) AS y FROM class_sessions ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
$years = array_map('intval', $years);
if (!in_array((int)date('Y'), $years, true)) {
    array_unshift($years, (int)date('Y'));
}

$stmt = db()->prepare(
    "SELECT cs.id, cs.session_date, cs.class_type,
            SUM(CASE WHEN a.present=1 THEN 1 ELSE 0 END) AS present_count
     FROM class_sessions cs
     LEFT JOIN attendance a ON a.session_id = cs.id
     $where_sql
     GROUP BY cs.id, cs.session_date, cs.class_type
     ORDER BY cs.session_date DESC"
);
$stmt->execute($params);
$sessions = $stmt->fetchAll();

$att_by_session = [];
if (!empty($sessions)) {
    $ids          = array_column($sessions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $att = db()->prepare(
        "SELECT a.session_id, s.first_name, s.last_name
         FROM attendance a
         JOIN students s ON s.id = a.student_id
         WHERE a.session_id IN ($placeholders) AND a.present = 1
         ORDER BY s.first_name, s.last_name"
    );
    $att->execute($ids);
    foreach ($att->fetchAll() as $r) {
        $att_by_session[$r['session_id']][] = [
            'first_name' => (string)$r['first_name'],
            'last_name'  => (string)$r['last_name'],
        ];
    }
}

$instr_by_session = [];
if (!empty($sessions)) {
    $ids          = array_column($sessions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $instr = db()->prepare(
        "SELECT csi.session_id, u.id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS name
         FROM class_session_instructors csi
         JOIN users u ON u.id = csi.user_id
         WHERE csi.session_id IN ($placeholders)
         ORDER BY name"
    );
    $instr->execute($ids);
    foreach ($instr->fetchAll() as $r) {
        $instr_by_session[$r['session_id']][] = [
            'id'   => (int)$r['id'],
            'name' => (string)$r['name'],
        ];
    }
}

api_respond([
    'sessions' => array_map(fn($s) => [
        'id'            => (int)$s['id'],
        'session_date'  => $s['session_date'],
        'class_type'    => (string)$s['class_type'],
        'present_count' => (int)$s['present_count'],
        'attendees'     => $att_by_session[$s['id']] ?? [],
        'instructors'   => $instr_by_session[$s['id']] ?? [],
    ], $sessions),
    'years' => $years,
]);
