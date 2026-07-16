<?php
// GET /api/v1/parent/family.php — the family tab bar + summary table.
// Own student record (if any) plus linked children with per-child summary,
// mirroring the top half of parent/index.php.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/family.php';
require_once __DIR__ . '/../../../includes/belt_helpers.php';

api_require_method('GET');
api_require_role('parent', 'student', 'guest', 'instructor', 'admin');

$user_id = (int)current_user_id();
$own = family_own_student($user_id);

$own_data = null;
$children = [];

if ($own !== null) {
    $own_data = [
        'id'            => (int)$own['id'],
        'first_name'    => (string)$own['first_name'],
        'last_name'     => (string)$own['last_name'],
        'student_type'  => (string)$own['student_type'],
        'date_of_birth' => $own['date_of_birth'] ?? null,
        'injury_waiver' => (bool)$own['injury_waiver'],
    ];

    $children_stmt = db()->prepare(
        'SELECT s.id, s.first_name, s.last_name, s.student_type, s.injury_waiver, s.date_of_birth,
                (SELECT r.kyu_dan FROM student_ranks sr
                 JOIN ranks r ON r.id = sr.rank_id
                 WHERE sr.student_id = s.id
                 ORDER BY r.rank_order DESC LIMIT 1) AS kyu_dan
         FROM student_guardians sg
         JOIN students s ON s.id = sg.child_student_id
         WHERE sg.parent_student_id = ?
         ORDER BY s.first_name, s.last_name'
    );
    $children_stmt->execute([(int)$own['id']]);

    foreach ($children_stmt->fetchAll() as $ch) {
        $cid = (int)$ch['id'];

        $la = db()->prepare(
            'SELECT cs.session_date FROM attendance a
             JOIN class_sessions cs ON cs.id = a.session_id
             WHERE a.student_id = ? AND a.present = 1
             ORDER BY cs.session_date DESC LIMIT 1'
        );
        $la->execute([$cid]);
        $last_attendance = $la->fetchColumn();

        $lp = db()->prepare(
            'SELECT payment_date, payment_type FROM payments
             WHERE student_id = ?
             ORDER BY payment_date DESC LIMIT 1'
        );
        $lp->execute([$cid]);
        $last_payment = $lp->fetch();

        $children[] = [
            'id'              => $cid,
            'first_name'      => (string)$ch['first_name'],
            'last_name'       => (string)$ch['last_name'],
            'student_type'    => (string)$ch['student_type'],
            'date_of_birth'   => $ch['date_of_birth'] ?? null,
            'injury_waiver'   => (bool)$ch['injury_waiver'],
            'kyu_dan'         => $ch['kyu_dan'] ?? null,
            'last_attendance' => $last_attendance !== false ? $last_attendance : null,
            'last_payment'    => $last_payment !== false
                ? ['date' => $last_payment['payment_date'], 'type' => (string)$last_payment['payment_type']]
                : null,
            'next_rank'       => belt_next_rank($ch['kyu_dan'] ?? null, $ch['date_of_birth'] ?? null),
        ];
    }
}

api_respond(['own_student' => $own_data, 'children' => $children]);
