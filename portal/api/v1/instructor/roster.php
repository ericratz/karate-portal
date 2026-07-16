<?php
// GET /api/v1/instructor/roster.php — every student row for the roster page,
// grouped client-side by student_type. Same query as the old
// instructor/students.php (highest rank per student, last attendance,
// login-account flag), plus the rank list for the filter dropdown.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/auto_inactive.php';

api_require_method('GET');
api_require_role('instructor', 'admin');
apply_auto_inactive();

$all = db()->query(
    'SELECT s.id, s.first_name, s.last_name, s.student_type, s.active,
            s.active_override, s.injury_waiver, s.medical_note,
            r.kyu_dan, u.id AS user_id,
            (SELECT MAX(cs.session_date)
             FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id
             WHERE a.student_id = s.id AND a.present = 1) AS last_attended
     FROM students s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN student_ranks sr ON sr.student_id = s.id
     LEFT JOIN ranks r ON r.id = sr.rank_id
     WHERE (sr.id IS NULL OR sr.rank_id = (
         SELECT sr2.rank_id FROM student_ranks sr2
         JOIN ranks r2 ON r2.id = sr2.rank_id
         WHERE sr2.student_id = s.id
         ORDER BY r2.rank_order DESC LIMIT 1
     ))
     ORDER BY s.first_name, s.last_name'
)->fetchAll();

$ranks = db()->query('SELECT kyu_dan FROM ranks ORDER BY rank_order')->fetchAll(PDO::FETCH_COLUMN);

api_respond([
    'students' => array_map(fn($s) => [
        'id'              => (int)$s['id'],
        'first_name'      => (string)$s['first_name'],
        'last_name'       => (string)$s['last_name'],
        'student_type'    => (string)$s['student_type'],
        'active'          => (bool)$s['active'],
        'active_override' => $s['active_override'] !== null,
        'injury_waiver'   => (bool)$s['injury_waiver'],
        'medical_note'    => $s['medical_note'] !== null && trim($s['medical_note']) !== '' ? trim($s['medical_note']) : null,
        'kyu_dan'         => $s['kyu_dan'] ?? null,
        'has_login'       => $s['user_id'] !== null,
        'last_attended'   => $s['last_attended'] ?? null,
    ], $all),
    'ranks' => $ranks,
]);
