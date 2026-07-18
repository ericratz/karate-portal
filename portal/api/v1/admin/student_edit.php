<?php
// /api/v1/admin/student_edit.php — the admin student editor.
// GET ?id=N: everything the page renders — profile, linked login, attendance
//     (every session with a present flag), belt tests, rank history + the
//     rank catalogue, notes, payment exemptions, payments (donations merged
//     read-only), and guardian links/candidates.
// POST {action, id, ...}: the old page's 19 handlers, action-for-action —
//     same validation, side effects (guest/student promotion + revert sync,
//     empty-session cleanup, belt-test rank cleanup, cert numbering,
//     linked-user name/email sync), and audit entries.

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('admin');

$WAIVER_TYPES  = ['monthly_tuition', 'registration', 'belt_test', 'slc_training', 'seminar', 'all'];
$PAY_TYPES     = ['monthly_tuition', 'registration', 'belt_test', 'slc_training', 'seminar', 'other', 'donation'];
$PAY_METHODS   = ['paypal', 'cash', 'check', 'mail'];
$ACCOUNT_TYPES = ['guest', 'student', 'parent', 'instructor'];

function next_cert_number(): string {
    $last = db()->query('SELECT cert_number FROM student_ranks WHERE cert_number IS NOT NULL ORDER BY CAST(SUBSTRING(cert_number,6) AS UNSIGNED) DESC LIMIT 1')->fetchColumn();
    return sprintf('SKSD-%04d', $last ? (int)substr($last, 5) + 1 : 1000);
}

// If no registration payment remains, revert student back to guest
function sync_registration_status(int $student_id): void {
    $stmt = db()->prepare("SELECT COUNT(*) FROM payments WHERE student_id=? AND payment_type='registration'");
    $stmt->execute([$student_id]);
    if (!(int)$stmt->fetchColumn()) {
        db()->prepare("UPDATE students SET student_type='guest' WHERE id=? AND student_type='student'")
             ->execute([$student_id]);
    }
}

function require_student(int $id): array {
    $stmt = db()->prepare('SELECT * FROM students WHERE id=?');
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    if (!$student) api_error('Student not found', 404);
    return $student;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input  = api_read_json();
    $action = api_str($input, 'action');
    $id     = api_int($input, 'id');

    // ── New student (no id) ──────────────────────────────────────
    if ($action === 'add_student') {
        $first    = trim(api_str($input, 'first_name'));
        $last     = trim(api_str($input, 'last_name'));
        if (!$first || !$last) api_error('First and last name are required.', 422);
        $s_type = in_array(api_str($input, 'account_type'), $ACCOUNT_TYPES, true)
            ? api_str($input, 'account_type') : 'guest';
        db()->prepare(
            'INSERT INTO students
             (first_name,last_name,date_of_birth,phone,email,
              emergency_contact_name,emergency_contact_phone,
              street_address,city_state_zip,
              registration_date,student_type,medical_note,uniform_size,belt_size,active,active_override)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NULL)'
        )->execute([
            $first, $last,
            api_str($input, 'date_of_birth') ?: null,
            trim(api_str($input, 'phone')),
            trim(api_str($input, 'email')),
            trim(api_str($input, 'ec_name')),
            trim(api_str($input, 'ec_phone')),
            trim(api_str($input, 'street_address')) ?: null,
            trim(api_str($input, 'city_state_zip')) ?: null,
            api_str($input, 'registration_date', date('Y-m-d')),
            $s_type,
            trim(api_str($input, 'medical_note')) ?: null,
            trim(api_str($input, 'uniform_size')) ?: null,
            trim(api_str($input, 'belt_size')) ?: null,
        ]);
        api_respond(['id' => (int)db()->lastInsertId()]);
    }

    if (!$id) api_error('Missing student id', 422);
    require_student($id);

    switch ($action) {
        case 'update_attendance':
            $present_raw = $input['att_present'] ?? [];
            $present_ids = array_map('intval', is_array($present_raw) ? $present_raw : []);
            $all_sessions = db()->query('SELECT id FROM class_sessions')->fetchAll(PDO::FETCH_COLUMN);
            $upsert = db()->prepare(
                'INSERT INTO attendance (student_id, session_id, present, recorded_by)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE present=VALUES(present), recorded_by=VALUES(recorded_by)'
            );
            foreach ($all_sessions as $sid) {
                $upsert->execute([$id, (int)$sid, in_array((int)$sid, $present_ids, true) ? 1 : 0, current_user_id()]);
            }
            // An empty class (nobody present) isn't worth keeping a record of
            if (!empty($all_sessions)) {
                $placeholders = implode(',', array_fill(0, count($all_sessions), '?'));
                db()->prepare(
                    "DELETE FROM class_sessions WHERE id IN ($placeholders)
                     AND id NOT IN (SELECT DISTINCT session_id FROM attendance WHERE present = 1)"
                )->execute($all_sessions);
            }
            audit('update_attendance', 'student', $id);
            api_respond(['saved' => true]);

        case 'add_note':
            $content = trim(api_str($input, 'note_content'));
            if ($content !== '') {
                db()->prepare('INSERT INTO student_notes (student_id, content, created_by) VALUES (?,?,?)')
                     ->execute([$id, $content, current_user_id()]);
            }
            api_respond(['saved' => true]);

        case 'edit_note':
            $note_id = api_int($input, 'note_id');
            $content = trim(api_str($input, 'note_content'));
            if ($note_id && $content !== '') {
                db()->prepare('UPDATE student_notes SET content=? WHERE id=? AND student_id=?')
                     ->execute([$content, $note_id, $id]);
            }
            api_respond(['saved' => true]);

        case 'delete_note':
            $note_id = api_int($input, 'note_id');
            if ($note_id) {
                db()->prepare('DELETE FROM student_notes WHERE id=? AND student_id=?')
                     ->execute([$note_id, $id]);
            }
            api_respond(['saved' => true]);

        case 'delete_waiver':
            $wid = api_int($input, 'waiver_id');
            db()->prepare('DELETE FROM payment_waivers WHERE id=? AND student_id=?')->execute([$wid, $id]);
            audit('delete_waiver', 'waiver', $wid);
            api_respond(['saved' => true]);

        case 'edit_waiver':
            $wid    = api_int($input, 'waiver_id');
            $type   = api_str($input, 'waiver_type');
            $date   = api_str($input, 'granted_date', date('Y-m-d'));
            $reason = trim(api_str($input, 'reason'));
            if ($wid && in_array($type, $WAIVER_TYPES, true)) {
                db()->prepare(
                    'UPDATE payment_waivers SET waiver_type=?, granted_date=?, reason=? WHERE id=? AND student_id=?'
                )->execute([$type, $date, $reason ?: null, $wid, $id]);
                audit('edit_waiver', 'waiver', $wid, "type=$type");
            }
            api_respond(['saved' => true]);

        case 'add_waiver':
            $type   = api_str($input, 'waiver_type');
            $reason = trim(api_str($input, 'reason'));
            $date   = api_str($input, 'granted_date', date('Y-m-d'));
            if (in_array($type, $WAIVER_TYPES, true)) {
                db()->prepare(
                    'INSERT INTO payment_waivers (student_id, waiver_type, reason, granted_by, granted_date)
                     VALUES (?,?,?,?,?)'
                )->execute([$id, $type, $reason ?: null, current_user_id(), $date]);
                audit('grant_waiver', 'waiver', null, "student_id=$id type=$type");
            }
            api_respond(['saved' => true]);

        case 'delete_payment':
            $pid = api_int($input, 'payment_id');
            db()->prepare('DELETE FROM payments WHERE id=? AND student_id=?')->execute([$pid, $id]);
            audit('delete_payment', 'payment', $pid);
            sync_registration_status($id);
            api_respond(['saved' => true]);

        case 'edit_payment':
            $pid        = api_int($input, 'payment_id');
            $pay_date   = api_str($input, 'payment_date', date('Y-m-d'));
            $pay_type   = api_str($input, 'payment_type', 'monthly_tuition');
            $pay_method = api_str($input, 'payment_method', 'cash');
            $amount     = (float)api_str($input, 'amount', '0');
            if ($amount > 0 && in_array($pay_type, $PAY_TYPES, true) && in_array($pay_method, $PAY_METHODS, true)) {
                db()->prepare(
                    'UPDATE payments SET payment_date=?, payment_type=?, payment_method=?, amount=? WHERE id=? AND student_id=?'
                )->execute([$pay_date, $pay_type, $pay_method, $amount, $pid, $id]);
                audit('edit_payment', 'payment', $pid, "amount=$amount type=$pay_type");
                if ($pay_type === 'registration') {
                    db()->prepare("UPDATE students SET student_type='student' WHERE id=? AND student_type='guest'")
                         ->execute([$id]);
                }
                sync_registration_status($id);
            }
            api_respond(['saved' => true]);

        case 'add_payment':
            $pay_date   = api_str($input, 'payment_date', date('Y-m-d'));
            $pay_type   = api_str($input, 'payment_type', 'monthly_tuition');
            $pay_method = api_str($input, 'payment_method', 'cash');
            $amount     = (float)api_str($input, 'amount', '0');
            $dup_count  = 0;
            if ($amount > 0) {
                // Duplicate check (warning only, never blocks): tuition already
                // recorded covering the same month?
                if ($pay_type === 'monthly_tuition') {
                    $dup_q = db()->prepare(
                        "SELECT COUNT(*) FROM payments
                         WHERE student_id=? AND payment_type='monthly_tuition'
                           AND (month_covered = DATE_FORMAT(?, '%Y-%m-01')
                                OR (month_covered IS NULL
                                    AND YEAR(payment_date)=YEAR(?) AND MONTH(payment_date)=MONTH(?)))"
                    );
                    $dup_q->execute([$id, $pay_date, $pay_date, $pay_date]);
                    $dup_count = (int)$dup_q->fetchColumn();
                }
                db()->prepare(
                    'INSERT INTO payments (student_id, payment_date, payment_type, payment_method, amount, recorded_by)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$id, $pay_date, $pay_type, $pay_method, $amount, current_user_id()]);
                audit('add_payment', 'student', $id, "amount=$amount type=$pay_type");
                // Auto-promote guest → student when registration fee is recorded
                if ($pay_type === 'registration') {
                    db()->prepare("UPDATE students SET student_type='student' WHERE id=? AND student_type='guest'")
                         ->execute([$id]);
                }
            }
            api_respond(['saved' => true, 'dup_count' => $dup_count > 0 ? $dup_count + 1 : 0]);

        case 'delete_profile':
            $uid_stmt = db()->prepare('SELECT user_id, first_name, last_name FROM students WHERE id = ?');
            $uid_stmt->execute([$id]);
            $del_row = $uid_stmt->fetch();
            $linked_uid = $del_row['user_id'] ?? null;
            $del_name = ($del_row['first_name'] ?? '') . ' ' . ($del_row['last_name'] ?? '');
            db()->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);
            if ($linked_uid) {
                db()->prepare('DELETE FROM users WHERE id = ?')->execute([$linked_uid]);
            }
            audit('delete_student', 'student', $id, trim($del_name));
            api_respond(['deleted' => true]);

        case 'update_profile':
            $first = trim(api_str($input, 'first_name'));
            $last  = trim(api_str($input, 'last_name'));
            if (!$first || !$last) api_error('First and last name are required.', 422);
            $s_type = in_array(api_str($input, 'account_type'), $ACCOUNT_TYPES, true)
                ? api_str($input, 'account_type') : 'guest';
            $ao_raw = api_str($input, 'active_override', 'auto');
            $active_override = $ao_raw === '1' ? 1 : ($ao_raw === '0' ? 0 : null);
            if ($active_override === 1) { $active = 1; }
            elseif ($active_override === 0) { $active = 0; }
            else {
                $chk = db()->prepare(
                    'SELECT COUNT(*) FROM attendance a JOIN class_sessions cs ON cs.id=a.session_id
                     WHERE a.student_id=? AND a.present=1
                       AND cs.session_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)'
                );
                $chk->execute([$id]);
                $active = (int)$chk->fetchColumn() > 0 ? 1 : 0;
            }
            $email = trim(api_str($input, 'email'));
            db()->prepare(
                'UPDATE students SET first_name=?, last_name=?, date_of_birth=?, phone=?, email=?,
                 emergency_contact_name=?, emergency_contact_phone=?,
                 street_address=?, city_state_zip=?,
                 registration_date=?, student_type=?,
                 medical_note=?, uniform_size=?, belt_size=?,
                 active=?, active_override=?
                 WHERE id=?'
            )->execute([
                $first, $last,
                api_str($input, 'date_of_birth') ?: null,
                trim(api_str($input, 'phone')),
                $email,
                trim(api_str($input, 'ec_name')),
                trim(api_str($input, 'ec_phone')),
                trim(api_str($input, 'street_address')) ?: null,
                trim(api_str($input, 'city_state_zip')) ?: null,
                api_str($input, 'registration_date', date('Y-m-d')),
                $s_type,
                trim(api_str($input, 'medical_note')) ?: null,
                trim(api_str($input, 'uniform_size')) ?: null,
                trim(api_str($input, 'belt_size')) ?: null,
                $active, $active_override, $id,
            ]);
            $uid_q = db()->prepare('SELECT user_id FROM students WHERE id=?');
            $uid_q->execute([$id]);
            if ($lu = $uid_q->fetchColumn()) {
                db()->prepare('UPDATE users SET first_name=?, last_name=?, email=? WHERE id=?')
                     ->execute([$first, $last, $email ?: null, $lu]);
            }
            audit('update_student', 'student', $id);
            api_respond(['saved' => true]);

        case 'update_ranks':
            $updates = $input['rank_updates'] ?? [];
            if (is_array($updates) && !empty($updates)) {
                $upd = db()->prepare('UPDATE student_ranks SET rank_id=?, achieved_date=? WHERE id=? AND student_id=?');
                foreach ($updates as $u) {
                    if (!is_array($u)) continue;
                    $upd->execute([
                        (int)($u['rank_id'] ?? 0),
                        (string)($u['achieved_date'] ?? ''),
                        (int)($u['sr_id'] ?? 0),
                        $id,
                    ]);
                }
            }
            audit('update_student', 'student', $id);
            api_respond(['saved' => true]);

        case 'add_rank':
            $new_rank_id   = api_int($input, 'new_rank_id');
            $new_rank_date = trim(api_str($input, 'new_rank_date')) ?: date('Y-m-d');
            if ($new_rank_id) {
                db()->prepare('INSERT INTO student_ranks (student_id, rank_id, achieved_date, cert_number) VALUES (?,?,?,?)')
                     ->execute([$id, $new_rank_id, $new_rank_date, next_cert_number()]);
                audit('update_student', 'student', $id);
            }
            api_respond(['saved' => true]);

        case 'delete_rank':
            $sr_id = api_int($input, 'sr_id');
            if ($sr_id) {
                db()->prepare('DELETE FROM student_ranks WHERE id=? AND student_id=?')->execute([$sr_id, $id]);
                audit('update_student', 'student', $id);
            }
            api_respond(['saved' => true]);

        case 'delete_belt_test':
            $bt_id = api_int($input, 'bt_id');
            if ($bt_id) {
                // Capture rank info before deleting so we can clean up student_ranks
                $bt_info_q = db()->prepare('SELECT rank_testing_for, belt_awarded FROM belt_tests WHERE id=? AND student_id=?');
                $bt_info_q->execute([$bt_id, $id]);
                $bt_info = $bt_info_q->fetch();
                db()->prepare('DELETE FROM belt_tests WHERE id=? AND student_id=?')->execute([$bt_id, $id]);
                if ($bt_info && $bt_info['belt_awarded']) {
                    // Remove rank record if no other passing test remains for this rank
                    $other = db()->prepare('SELECT COUNT(*) FROM belt_tests WHERE student_id=? AND rank_testing_for=? AND belt_awarded=1');
                    $other->execute([$id, $bt_info['rank_testing_for']]);
                    if ((int)$other->fetchColumn() === 0) {
                        db()->prepare('DELETE FROM student_ranks WHERE student_id=? AND rank_id=?')
                             ->execute([$id, $bt_info['rank_testing_for']]);
                    }
                }
                audit('delete_belt_test', 'student', $id);
            }
            api_respond(['saved' => true]);

        case 'add_guardian':
            $other_id = api_int($input, 'guardian_student_id');
            if ($other_id && $other_id !== $id) {
                $ts = db()->prepare('SELECT student_type FROM students WHERE id=?');
                $ts->execute([$id]);
                $stype = $ts->fetchColumn();
                [$parent_sid, $child_sid] = in_array($stype, ['parent', 'instructor'], true) ? [$id, $other_id] : [$other_id, $id];
                db()->prepare('INSERT IGNORE INTO student_guardians (parent_student_id, child_student_id) VALUES (?,?)')
                     ->execute([$parent_sid, $child_sid]);
                audit('add_guardian', 'student', $id, "linked=$other_id");
            }
            api_respond(['saved' => true]);

        case 'remove_guardian':
            $link_id = api_int($input, 'guardian_link_id');
            if ($link_id) {
                db()->prepare('DELETE FROM student_guardians WHERE id=? AND (parent_student_id=? OR child_student_id=?)')
                     ->execute([$link_id, $id, $id]);
                audit('remove_guardian', 'student', $id, "link_id=$link_id");
            }
            api_respond(['saved' => true]);
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');

$id = get_int('id');
$all_ranks = db()->query('SELECT id, name, kyu_dan FROM ranks ORDER BY rank_order ASC')->fetchAll();

// New-student mode: just the rank catalogue (the form itself is client-side)
if (!$id) {
    api_respond([
        'student'   => null,
        'all_ranks' => array_map(fn($r) => [
            'id'      => (int)$r['id'],
            'name'    => (string)$r['name'],
            'kyu_dan' => (string)$r['kyu_dan'],
        ], $all_ranks),
    ]);
}

$student = require_student($id);

$linked_user = null;
if ($student['user_id']) {
    $lu = db()->prepare('SELECT id, username, is_admin FROM users WHERE id=?');
    $lu->execute([(int)$student['user_id']]);
    $linked_user = $lu->fetch() ?: null;
}

$att_stmt = db()->prepare(
    'SELECT cs.session_date, cs.id AS session_id, COALESCE(a.present, 0) AS present
     FROM class_sessions cs
     LEFT JOIN attendance a ON a.session_id = cs.id AND a.student_id = ?
     ORDER BY cs.session_date DESC'
);
$att_stmt->execute([$id]);
$attendance = $att_stmt->fetchAll();

$bt_stmt = db()->prepare(
    'SELECT bt.id, bt.test_date, bt.result, bt.score, bt.fee_paid, bt.belt_awarded,
            bt.rank_testing_for, r.kyu_dan
     FROM belt_tests bt
     JOIN ranks r ON r.id = bt.rank_testing_for
     WHERE bt.student_id = ?
     ORDER BY bt.test_date DESC'
);
$bt_stmt->execute([$id]);
$belt_tests = $bt_stmt->fetchAll();

$ranks_stmt = db()->prepare(
    'SELECT sr.id AS sr_id, sr.rank_id, r.name, r.kyu_dan, sr.achieved_date
     FROM student_ranks sr JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ? ORDER BY r.rank_order DESC'
);
$ranks_stmt->execute([$id]);
$ranks = $ranks_stmt->fetchAll();

$notes_stmt = db()->prepare(
    'SELECT sn.id, sn.content, sn.created_at, u.username
     FROM student_notes sn
     LEFT JOIN users u ON u.id = sn.created_by
     WHERE sn.student_id = ?
     ORDER BY sn.created_at DESC'
);
$notes_stmt->execute([$id]);
$notes = $notes_stmt->fetchAll();

$pw_stmt = db()->prepare(
    'SELECT id, waiver_type, granted_date, reason
     FROM payment_waivers
     WHERE student_id = ?
     ORDER BY granted_date DESC'
);
$pw_stmt->execute([$id]);
$payment_waivers = $pw_stmt->fetchAll();

// Linked donations are merged in read-only — they're managed on donations.php
$pay_stmt = db()->prepare(
    'SELECT id, payment_date, payment_type, payment_method, amount, 0 AS is_donation
     FROM payments WHERE student_id = ?
     UNION ALL
     SELECT id, payment_date, \'donation\', payment_method, amount, 1
     FROM donations WHERE student_id = ?
     ORDER BY payment_date DESC'
);
$pay_stmt->execute([$id, $id]);
$payments = $pay_stmt->fetchAll();

// Guardian links (student_guardians — no user account required)
$is_guardian_type = in_array($student['student_type'] ?? '', ['parent', 'instructor'], true);
if ($is_guardian_type) {
    $gl_stmt = db()->prepare(
        'SELECT sg.id AS link_id, s.id AS student_id, s.first_name, s.last_name
         FROM student_guardians sg JOIN students s ON s.id = sg.child_student_id
         WHERE sg.parent_student_id = ? ORDER BY s.first_name, s.last_name'
    );
    $gl_stmt->execute([$id]);
    $guardian_links = $gl_stmt->fetchAll();
    $linked_ids = array_map('intval', array_column($guardian_links, 'student_id'));
    $excl = $linked_ids ? ' AND id NOT IN (' . implode(',', $linked_ids) . ')' : '';
    $gc_stmt = db()->prepare(
        "SELECT id, first_name, last_name FROM students
         WHERE student_type IN ('student','guest') AND id != ?$excl
         ORDER BY first_name, last_name"
    );
    $gc_stmt->execute([$id]);
    $guardian_candidates = $gc_stmt->fetchAll();
} else {
    $gl_stmt = db()->prepare(
        'SELECT sg.id AS link_id, s.id AS student_id, s.first_name, s.last_name
         FROM student_guardians sg JOIN students s ON s.id = sg.parent_student_id
         WHERE sg.child_student_id = ? ORDER BY s.first_name, s.last_name'
    );
    $gl_stmt->execute([$id]);
    $guardian_links = $gl_stmt->fetchAll();
    $linked_ids = array_map('intval', array_column($guardian_links, 'student_id'));
    $excl = $linked_ids ? ' AND id NOT IN (' . implode(',', $linked_ids) . ')' : '';
    $gc_stmt = db()->prepare(
        "SELECT id, first_name, last_name FROM students
         WHERE student_type IN ('parent','instructor') AND id != ?$excl
         ORDER BY first_name, last_name"
    );
    $gc_stmt->execute([$id]);
    $guardian_candidates = $gc_stmt->fetchAll();
}

api_respond([
    'student' => [
        'id'                      => (int)$student['id'],
        'first_name'              => (string)$student['first_name'],
        'last_name'               => (string)$student['last_name'],
        'date_of_birth'           => $student['date_of_birth'] ?? null,
        'phone'                   => $student['phone'] ?? null,
        'email'                   => $student['email'] ?? null,
        'emergency_contact_name'  => $student['emergency_contact_name'] ?? null,
        'emergency_contact_phone' => $student['emergency_contact_phone'] ?? null,
        'street_address'          => $student['street_address'] ?? null,
        'city_state_zip'          => $student['city_state_zip'] ?? null,
        'registration_date'       => $student['registration_date'] ?? null,
        'student_type'            => (string)($student['student_type'] ?? 'guest'),
        'medical_note'            => $student['medical_note'] ?? null,
        'uniform_size'            => $student['uniform_size'] ?? null,
        'belt_size'               => $student['belt_size'] ?? null,
        'active'                  => (bool)$student['active'],
        'active_override'         => $student['active_override'] !== null ? (int)$student['active_override'] : null,
        'injury_waiver'           => (bool)$student['injury_waiver'],
        'injury_waiver_date'      => $student['injury_waiver_date'] ?? null,
    ],
    'linked_user' => $linked_user !== null ? [
        'id'       => (int)$linked_user['id'],
        'username' => (string)$linked_user['username'],
        'is_admin' => (bool)$linked_user['is_admin'],
    ] : null,
    'attendance' => array_map(fn($a) => [
        'session_id'   => (int)$a['session_id'],
        'session_date' => (string)$a['session_date'],
        'present'      => (bool)$a['present'],
    ], $attendance),
    'belt_tests' => array_map(fn($bt) => [
        'id'        => (int)$bt['id'],
        'test_date' => (string)$bt['test_date'],
        'result'    => (string)$bt['result'],
        'score'     => $bt['score'] !== null ? (int)$bt['score'] : null,
        'fee_paid'  => (bool)$bt['fee_paid'],
        'kyu_dan'   => (string)$bt['kyu_dan'],
    ], $belt_tests),
    'ranks' => array_map(fn($r) => [
        'sr_id'         => (int)$r['sr_id'],
        'rank_id'       => (int)$r['rank_id'],
        'name'          => (string)$r['name'],
        'kyu_dan'       => (string)$r['kyu_dan'],
        'achieved_date' => $r['achieved_date'] ?? null,
    ], $ranks),
    'all_ranks' => array_map(fn($r) => [
        'id'      => (int)$r['id'],
        'name'    => (string)$r['name'],
        'kyu_dan' => (string)$r['kyu_dan'],
    ], $all_ranks),
    'notes' => array_map(fn($n) => [
        'id'         => (int)$n['id'],
        'content'    => (string)$n['content'],
        'created_at' => (string)$n['created_at'],
        'username'   => $n['username'] ?? null,
    ], $notes),
    'payment_waivers' => array_map(fn($pw) => [
        'id'           => (int)$pw['id'],
        'waiver_type'  => (string)$pw['waiver_type'],
        'granted_date' => (string)$pw['granted_date'],
        'reason'       => $pw['reason'] ?? null,
    ], $payment_waivers),
    'payments' => array_map(fn($p) => [
        'id'             => (int)$p['id'],
        'payment_date'   => (string)$p['payment_date'],
        'payment_type'   => (string)$p['payment_type'],
        'payment_method' => (string)$p['payment_method'],
        'amount'         => (float)$p['amount'],
        'is_donation'    => (bool)$p['is_donation'],
    ], $payments),
    'is_guardian_type' => $is_guardian_type,
    'guardian_links' => array_map(fn($gl) => [
        'link_id'    => (int)$gl['link_id'],
        'student_id' => (int)$gl['student_id'],
        'name'       => trim($gl['first_name'] . ' ' . $gl['last_name']),
    ], $guardian_links),
    'guardian_candidates' => array_map(fn($gc) => [
        'id'   => (int)$gc['id'],
        'name' => trim($gc['first_name'] . ' ' . $gc['last_name']),
    ], $guardian_candidates),
]);
