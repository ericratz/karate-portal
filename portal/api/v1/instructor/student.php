<?php
// /api/v1/instructor/student.php — the instructor-facing student profile.
//
// GET ?id=N → full profile context: student row (instructor-visible fields),
//   rank history, attended sessions, belt tests, payments (donations merged),
//   notes (admin only — instructors are write-only), family tabs, and flags.
// POST {action:"add_note", id, content}                → save a private note
// POST {action:"update_attendance", id, present_session_ids}
//   → present=1 for the given sessions, 0 for every other session (bulk
//     uncheck from the profile's attendance card; instructor-only)
// POST {action:"update_profile", id, ...fields}
//   → own linked record only, same field set + audit as the old page

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('instructor', 'admin');

function load_student(int $id): ?array {
    $stmt = db()->prepare(
        'SELECT s.*, u.username, u.email AS login_email, u.last_login
         FROM students s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function student_payload(array $s): array {
    return [
        'id'                      => (int)$s['id'],
        'first_name'              => (string)$s['first_name'],
        'last_name'               => (string)$s['last_name'],
        'student_type'            => (string)$s['student_type'],
        'active'                  => (bool)$s['active'],
        'date_of_birth'           => $s['date_of_birth'] ?? null,
        'phone'                   => $s['phone'] ?? null,
        'email'                   => $s['email'] ?? null,
        'emergency_contact_name'  => $s['emergency_contact_name'] ?? null,
        'emergency_contact_phone' => $s['emergency_contact_phone'] ?? null,
        'street_address'          => $s['street_address'] ?? null,
        'city_state_zip'          => $s['city_state_zip'] ?? null,
        'registration_date'       => $s['registration_date'] ?? null,
        'injury_waiver'           => (bool)$s['injury_waiver'],
        'injury_waiver_date'      => $s['injury_waiver_date'] ?? null,
        'uniform_size'            => $s['uniform_size'] ?? null,
        'belt_size'               => $s['belt_size'] ?? null,
        'medical_note'            => $s['medical_note'] ?? null,
        'username'                => $s['username'] ?? null,
        'last_login'              => $s['last_login'] ?? null,
        'user_id'                 => $s['user_id'] !== null ? (int)$s['user_id'] : null,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input  = api_read_json();
    $action = api_str($input, 'action');
    $id     = api_int($input, 'id');

    $student = load_student($id);
    if ($student === null) {
        api_error('Student not found', 404);
    }

    if ($action === 'add_note') {
        $content = trim(api_str($input, 'content'));
        if ($content === '') {
            api_error('Note cannot be empty.', 422);
        }
        db()->prepare('INSERT INTO student_notes (student_id, content, created_by) VALUES (?,?,?)')
             ->execute([$id, $content, current_user_id()]);
        audit('add_note', 'student', $id);
        api_respond(['added' => true]);
    }

    if ($action === 'update_attendance') {
        $raw = $input['present_session_ids'] ?? [];
        $present_ids = array_map('intval', is_array($raw) ? $raw : []);
        $all_sessions = db()->query('SELECT id FROM class_sessions')->fetchAll(PDO::FETCH_COLUMN);
        $upsert = db()->prepare(
            'INSERT INTO attendance (student_id, session_id, present, recorded_by)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE present=VALUES(present), recorded_by=VALUES(recorded_by)'
        );
        foreach ($all_sessions as $sid) {
            $upsert->execute([$id, (int)$sid, in_array((int)$sid, $present_ids, true) ? 1 : 0, current_user_id()]);
        }
        audit('update_attendance', 'student', $id);
        api_respond(['updated' => true]);
    }

    if ($action === 'update_profile') {
        // Own linked record only — an instructor cannot edit other students here
        if ((int)($student['user_id'] ?? 0) !== (int)current_user_id()) {
            api_error('You can only edit your own profile here.', 403);
        }
        $first = trim(api_str($input, 'first_name'));
        $last  = trim(api_str($input, 'last_name'));
        if ($first === '' || $last === '') {
            api_error('First and last name are required.', 422);
        }
        $dob      = api_str($input, 'date_of_birth');
        $phone    = trim(api_str($input, 'phone'));
        $email    = trim(api_str($input, 'email'));
        $ec_name  = trim(api_str($input, 'ec_name'));
        $ec_phone = trim(api_str($input, 'ec_phone'));
        $street   = trim(api_str($input, 'street_address'));
        $csz      = trim(api_str($input, 'city_state_zip'));
        $medical  = trim(api_str($input, 'medical_note'));

        db()->prepare(
            'UPDATE students SET first_name=?, last_name=?, date_of_birth=?,
             phone=?, email=?, emergency_contact_name=?, emergency_contact_phone=?,
             street_address=?, city_state_zip=?, medical_note=? WHERE id=?'
        )->execute([$first, $last, $dob ?: null, $phone, $email, $ec_name, $ec_phone,
                    $street ?: null, $csz ?: null, $medical ?: null, $id]);
        if ($student['user_id']) {
            db()->prepare('UPDATE users SET first_name=?, last_name=?, email=? WHERE id=?')
                 ->execute([$first, $last, $email ?: null, (int)$student['user_id']]);
        }
        audit('update_student', 'student', $id, 'by_self');
        $fresh = load_student($id);
        api_respond(['saved' => true, 'student' => student_payload($fresh ?? $student)]);
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');

$id = get_int('id');
$student = $id > 0 ? load_student($id) : null;
if ($student === null) {
    api_error('Student not found', 404);
}

$is_admin = ($_SESSION['role'] ?? '') === 'admin';

// Full rank history
$ranks = db()->prepare(
    'SELECT sr.rank_id, r.name, r.kyu_dan, sr.achieved_date
     FROM student_ranks sr JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ? ORDER BY r.rank_order DESC'
);
$ranks->execute([$id]);

// Sessions the student attended (the edit flow only ever unchecks these)
$att = db()->prepare(
    'SELECT cs.id AS session_id, cs.session_date
     FROM class_sessions cs
     JOIN attendance a ON a.session_id = cs.id AND a.student_id = ? AND a.present = 1
     ORDER BY cs.session_date DESC'
);
$att->execute([$id]);

$belt_tests = db()->prepare(
    'SELECT bt.id, bt.test_date, bt.result, bt.score, bt.fee_paid,
            r.kyu_dan, r.name AS rank_name
     FROM belt_tests bt
     JOIN ranks r ON r.id = bt.rank_testing_for
     WHERE bt.student_id = ?
     ORDER BY bt.test_date DESC'
);
$belt_tests->execute([$id]);

$payments = db()->prepare(
    'SELECT payment_date, payment_type, payment_method, amount
     FROM payments WHERE student_id = ?
     UNION ALL
     SELECT payment_date, \'donation\', payment_method, amount
     FROM donations WHERE student_id = ?
     ORDER BY payment_date DESC'
);
$payments->execute([$id, $id]);

// Notes are admin-read, instructor-write-only
$notes = [];
if ($is_admin) {
    $notes_stmt = db()->prepare(
        'SELECT sn.id, sn.content, sn.created_at, u.username
         FROM student_notes sn
         LEFT JOIN users u ON u.id = sn.created_by
         WHERE sn.student_id = ?
         ORDER BY sn.created_at DESC'
    );
    $notes_stmt->execute([$id]);
    foreach ($notes_stmt->fetchAll() as $n) {
        $notes[] = [
            'id'         => (int)$n['id'],
            'content'    => (string)$n['content'],
            'created_at' => $n['created_at'],
            'username'   => $n['username'] ?? null,
        ];
    }
}

// Family tabs: parent/instructor rows list their children; child rows list
// their parent + siblings. Same logic as the old page.
$family_tabs = [];
if (in_array($student['student_type'], ['parent', 'instructor'], true)) {
    $ch = db()->prepare(
        'SELECT s.id, s.first_name, s.last_name
         FROM student_guardians sg JOIN students s ON s.id = sg.child_student_id
         WHERE sg.parent_student_id = ? ORDER BY s.first_name, s.last_name'
    );
    $ch->execute([$id]);
    $children = $ch->fetchAll();
    if (!empty($children)) {
        $family_tabs[] = ['id' => $id, 'name' => $student['first_name'] . ' ' . $student['last_name'], 'role' => 'parent'];
        foreach ($children as $c) {
            $family_tabs[] = ['id' => (int)$c['id'], 'name' => $c['first_name'] . ' ' . $c['last_name'], 'role' => 'child'];
        }
    }
} else {
    $pg = db()->prepare(
        'SELECT sp.id, sp.first_name, sp.last_name
         FROM student_guardians sg JOIN students sp ON sp.id = sg.parent_student_id
         WHERE sg.child_student_id = ? LIMIT 1'
    );
    $pg->execute([$id]);
    $parent = $pg->fetch();
    if ($parent) {
        $family_tabs[] = ['id' => (int)$parent['id'], 'name' => $parent['first_name'] . ' ' . $parent['last_name'], 'role' => 'parent'];
        $sib = db()->prepare(
            'SELECT s.id, s.first_name, s.last_name
             FROM student_guardians sg JOIN students s ON s.id = sg.child_student_id
             WHERE sg.parent_student_id = ? ORDER BY s.first_name, s.last_name'
        );
        $sib->execute([(int)$parent['id']]);
        foreach ($sib->fetchAll() as $s) {
            $family_tabs[] = ['id' => (int)$s['id'], 'name' => $s['first_name'] . ' ' . $s['last_name'], 'role' => 'child'];
        }
    }
}

api_respond([
    'student'          => student_payload($student),
    'can_edit_profile' => (int)($student['user_id'] ?? 0) === (int)current_user_id(),
    'is_admin'         => $is_admin,
    'ranks'            => array_map(fn($r) => [
        'rank_id'       => (int)$r['rank_id'],
        'name'          => (string)$r['name'],
        'kyu_dan'       => (string)$r['kyu_dan'],
        'achieved_date' => $r['achieved_date'],
    ], $ranks->fetchAll()),
    'attended_sessions' => array_map(fn($a) => [
        'session_id'   => (int)$a['session_id'],
        'session_date' => $a['session_date'],
    ], $att->fetchAll()),
    'belt_tests' => array_map(fn($t) => [
        'id'        => (int)$t['id'],
        'test_date' => $t['test_date'],
        'result'    => (string)$t['result'],
        'score'     => $t['score'] !== null ? (int)$t['score'] : null,
        'fee_paid'  => (bool)$t['fee_paid'],
        'kyu_dan'   => (string)$t['kyu_dan'],
        'rank_name' => (string)$t['rank_name'],
    ], $belt_tests->fetchAll()),
    'payments' => array_map(fn($p) => [
        'payment_date'   => $p['payment_date'],
        'payment_type'   => (string)$p['payment_type'],
        'payment_method' => (string)$p['payment_method'],
        'amount'         => (float)$p['amount'],
    ], $payments->fetchAll()),
    'notes'       => $notes,
    'family_tabs' => $family_tabs,
]);
