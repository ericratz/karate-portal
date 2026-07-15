<?php
// GET /api/v1/parent/attendance.php?student_id=N — full attendance history
// for one family member. Mirrors parent/attendance.php.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/family.php';

api_require_method('GET');
api_require_role('parent');

$user_id    = (int)current_user_id();
$student_id = get_int('student_id');

if (!family_can_access($user_id, $student_id)) {
    api_error('Student not linked to your account', 403);
}

$stmt = db()->prepare('SELECT id, first_name, last_name FROM students WHERE id = ?');
$stmt->execute([$student_id]);
$student = $stmt->fetch();
if ($student === false) {
    api_error('Student not found', 404);
}

$attended = db()->prepare(
    'SELECT cs.session_date
     FROM attendance a
     JOIN class_sessions cs ON cs.id = a.session_id
     WHERE a.student_id = ? AND a.present = 1
     ORDER BY cs.session_date DESC'
);
$attended->execute([$student_id]);
$dates = $attended->fetchAll(PDO::FETCH_COLUMN);

api_respond([
    'student' => [
        'id'         => (int)$student['id'],
        'first_name' => (string)$student['first_name'],
        'last_name'  => (string)$student['last_name'],
    ],
    'total_attended' => count($dates),
    'dates'          => $dates,
]);
