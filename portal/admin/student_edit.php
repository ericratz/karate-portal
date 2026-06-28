<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');
function fmt_phone(string $p): string { $d = preg_replace('/\D/', '', $p); return strlen($d) === 10 ? substr($d,0,3).'-'.substr($d,3,3).'-'.substr($d,6) : $p; }

// If no registration payment remains, revert student back to guest
function sync_registration_status(int $student_id): void {
    $stmt = db()->prepare("SELECT COUNT(*) FROM payments WHERE student_id=? AND payment_type='registration'");
    $stmt->execute([$student_id]);
    if (!(int)$stmt->fetchColumn()) {
        db()->prepare("UPDATE students SET student_type='guest' WHERE id=? AND student_type='student'")
             ->execute([$student_id]);
    }
}

$id    = (int)($_GET['id'] ?? 0);
$ref   = $_GET['ref'] ?? '';
$msg   = '';
$error = '';

// Bulk update attendance from checkboxes
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_attendance') {
    verify_csrf();
    $present_ids = array_map('intval', $_POST['att_present'] ?? []);
    $all_sessions = db()->query('SELECT id FROM class_sessions')->fetchAll(PDO::FETCH_COLUMN);
    $upsert = db()->prepare(
        'INSERT INTO attendance (student_id, session_id, present, recorded_by)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE present=VALUES(present), recorded_by=VALUES(recorded_by)'
    );
    foreach ($all_sessions as $sid) {
        $upsert->execute([$id, (int)$sid, in_array((int)$sid, $present_ids) ? 1 : 0, current_user_id()]);
    }
    audit('update_attendance', 'student', $id);
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Add a note
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_note') {
    verify_csrf();
    $content = trim($_POST['note_content'] ?? '');
    if ($content !== '') {
        db()->prepare('INSERT INTO student_notes (student_id, content, created_by) VALUES (?,?,?)')
             ->execute([$id, $content, current_user_id()]);
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Edit a note
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_note') {
    verify_csrf();
    $note_id = (int)$_POST['note_id'];
    $content = trim($_POST['note_content'] ?? '');
    if ($note_id && $content !== '') {
        db()->prepare('UPDATE student_notes SET content=? WHERE id=? AND student_id=?')
             ->execute([$content, $note_id, $id]);
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Delete a note
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_note') {
    verify_csrf();
    $note_id = (int)$_POST['note_id'];
    if ($note_id) {
        db()->prepare('DELETE FROM student_notes WHERE id=? AND student_id=?')
             ->execute([$note_id, $id]);
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Delete payment waiver
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_waiver') {
    verify_csrf();
    $wid = (int)$_POST['waiver_id'];
    db()->prepare('DELETE FROM payment_waivers WHERE id=? AND student_id=?')->execute([$wid, $id]);
    audit('delete_waiver', 'waiver', $wid);
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Edit payment waiver
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_waiver') {
    verify_csrf();
    $wid    = (int)$_POST['waiver_id'];
    $type   = $_POST['waiver_type']  ?? '';
    $date   = $_POST['granted_date'] ?? date('Y-m-d');
    $reason = trim($_POST['reason']  ?? '');
    $valid_types = ['monthly_tuition','registration','belt_test','slc_training','seminar','all'];
    if ($wid && in_array($type, $valid_types)) {
        db()->prepare(
            'UPDATE payment_waivers SET waiver_type=?, granted_date=?, reason=? WHERE id=? AND student_id=?'
        )->execute([$type, $date, $reason ?: null, $wid, $id]);
        audit('edit_waiver', 'waiver', $wid, "type=$type");
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Delete payment
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_payment') {
    verify_csrf();
    $pid = (int)$_POST['payment_id'];
    db()->prepare('DELETE FROM payments WHERE id=? AND student_id=?')->execute([$pid, $id]);
    audit('delete_payment', 'payment', $pid);
    sync_registration_status($id);
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Edit payment
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_payment') {
    verify_csrf();
    $pid        = (int)$_POST['payment_id'];
    $pay_date   = $_POST['payment_date']   ?? date('Y-m-d');
    $pay_type   = $_POST['payment_type']   ?? 'monthly_tuition';
    $pay_method = $_POST['payment_method'] ?? 'cash';
    $amount     = (float)($_POST['amount'] ?? 0);
    $valid_types   = ['monthly_tuition','registration','belt_test','slc_training','seminar','other','donation'];
    $valid_methods = ['paypal','cash','check','mail'];
    if ($amount > 0 && in_array($pay_type, $valid_types) && in_array($pay_method, $valid_methods)) {
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
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Add payment
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_payment') {
    verify_csrf();
    $pay_date   = $_POST['payment_date']   ?? date('Y-m-d');
    $pay_type   = $_POST['payment_type']   ?? 'monthly_tuition';
    $pay_method = $_POST['payment_method'] ?? 'cash';
    $amount     = (float)($_POST['amount'] ?? 0);
    if ($amount > 0) {
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
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Delete student profile + linked user account
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_profile') {
    verify_csrf();
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
    header('Location: students.php');
    exit;
}

// ── Per-card save handlers ────────────────────────────────────────

// Update student profile info
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    verify_csrf();
    $first    = trim($_POST['first_name'] ?? '');
    $last     = trim($_POST['last_name']  ?? '');
    $dob      = $_POST['date_of_birth']   ?? '';
    $phone    = trim($_POST['phone']      ?? '');
    $email    = trim($_POST['email']      ?? '');
    $ec_name  = trim($_POST['ec_name']    ?? '');
    $ec_phone = trim($_POST['ec_phone']   ?? '');
    $street   = trim($_POST['street_address'] ?? '');
    $csz      = trim($_POST['city_state_zip'] ?? '');
    $reg_date = $_POST['registration_date'] ?? date('Y-m-d');
    $s_type      = in_array($_POST['account_type'] ?? '', ['guest','student','parent','instructor'])
                   ? $_POST['account_type'] : 'guest';
    $medical_note  = trim($_POST['medical_note']  ?? '');
    $uniform_size  = trim($_POST['uniform_size']  ?? '');
    $belt_size     = trim($_POST['belt_size']     ?? '');
    if (!$first || !$last) {
        $error = 'First and last name are required.';
    } else {
        db()->prepare(
            'UPDATE students SET first_name=?, last_name=?, date_of_birth=?, phone=?, email=?,
             emergency_contact_name=?, emergency_contact_phone=?,
             street_address=?, city_state_zip=?,
             registration_date=?, student_type=?,
             medical_note=?, uniform_size=?, belt_size=?
             WHERE id=?'
        )->execute([$first,$last,$dob?:null,$phone,$email,$ec_name,$ec_phone,$street?:null,$csz?:null,$reg_date,$s_type,$medical_note?:null,$uniform_size?:null,$belt_size?:null,$id]);
        $uid_q = db()->prepare('SELECT user_id FROM students WHERE id=?');
        $uid_q->execute([$id]);
        if ($lu = $uid_q->fetchColumn()) {
            db()->prepare('UPDATE users SET first_name=?, last_name=?, email=? WHERE id=?')
                 ->execute([$first, $last, $email ?: null, $lu]);
        }
        audit('update_student', 'student', $id);
        if (empty($_SERVER['HTTP_HX_REQUEST'])) {
            header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
            exit;
        }
    }
}

// Update active status
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_active_status') {
    verify_csrf();
    $raw = $_POST['active_override'] ?? 'auto';
    $active_override = $raw === '1' ? 1 : ($raw === '0' ? 0 : null);
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
    db()->prepare('UPDATE students SET active=?, active_override=? WHERE id=?')
         ->execute([$active, $active_override, $id]);
    audit('update_student', 'student', $id);
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Update existing ranks (bulk)
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ranks') {
    verify_csrf();
    if (!empty($_POST['rank_updates']) && is_array($_POST['rank_updates'])) {
        $upd = db()->prepare('UPDATE student_ranks SET rank_id=?, achieved_date=? WHERE id=? AND student_id=?');
        foreach ($_POST['rank_updates'] as $sr_id => $data) {
            $upd->execute([(int)$data['rank_id'], $data['achieved_date'], (int)$sr_id, $id]);
        }
    }
    audit('update_student', 'student', $id);
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Add rank
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_rank') {
    verify_csrf();
    $new_rank_id   = (int)($_POST['new_rank_id'] ?? 0);
    $new_rank_date = trim($_POST['new_rank_date'] ?? '') ?: date('Y-m-d');
    if ($new_rank_id) {
        db()->prepare('INSERT INTO student_ranks (student_id, rank_id, achieved_date) VALUES (?,?,?)')
             ->execute([$id, $new_rank_id, $new_rank_date]);
        audit('update_student', 'student', $id);
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Delete rank
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_rank') {
    verify_csrf();
    $sr_id = (int)($_POST['sr_id'] ?? 0);
    if ($sr_id) {
        db()->prepare('DELETE FROM student_ranks WHERE id=? AND student_id=?')->execute([$sr_id, $id]);
        audit('update_student', 'student', $id);
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Add belt test (inline)
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_belt_test') {
    verify_csrf();
    $rank_for  = (int)($_POST['rank_testing_for'] ?? 0);
    $test_date = $_POST['test_date'] ?? date('Y-m-d');
    $score     = ($_POST['score'] ?? '') !== '' ? min(100, max(0, (int)$_POST['score'])) : null;
    $result    = $score === null ? 'pending' : ($score >= 80 ? 'pass' : 'fail');
    $fee_paid     = isset($_POST['fee_paid']) ? 1 : 0;
    $belt_awarded = ($result === 'pass') ? 1 : 0;
    if ($rank_for) {
        db()->prepare(
            'INSERT INTO belt_tests (student_id, rank_testing_for, test_date, result, score, fee_paid, belt_awarded)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$id, $rank_for, $test_date, $result, $score, $fee_paid, $belt_awarded]);
        if ($belt_awarded) {
            db()->prepare(
                'INSERT INTO student_ranks (student_id, rank_id, achieved_date)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE achieved_date = VALUES(achieved_date)'
            )->execute([$id, $rank_for, $test_date]);
        }
        audit('add_belt_test', 'student', $id);
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Update belt test
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_belt_test') {
    verify_csrf();
    $bt_id     = (int)($_POST['bt_id'] ?? 0);
    $rank_for  = (int)($_POST['rank_testing_for'] ?? 0);
    $test_date = $_POST['test_date'] ?? date('Y-m-d');
    $score     = ($_POST['score'] ?? '') !== '' ? min(100, max(0, (int)$_POST['score'])) : null;
    $result    = $score === null ? 'pending' : ($score >= 80 ? 'pass' : 'fail');
    $fee_paid     = isset($_POST['fee_paid']) ? 1 : 0;
    $belt_awarded = ($result === 'pass') ? 1 : 0;
    if ($bt_id && $rank_for) {
        db()->prepare(
            'UPDATE belt_tests SET rank_testing_for=?, test_date=?, result=?, score=?, fee_paid=?, belt_awarded=?
             WHERE id=? AND student_id=?'
        )->execute([$rank_for, $test_date, $result, $score, $fee_paid, $belt_awarded, $bt_id, $id]);
        if ($belt_awarded) {
            db()->prepare(
                'INSERT INTO student_ranks (student_id, rank_id, achieved_date)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE achieved_date = VALUES(achieved_date)'
            )->execute([$id, $rank_for, $test_date]);
        }
        audit('update_belt_test', 'student', $id);
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Delete belt test
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_belt_test') {
    verify_csrf();
    $bt_id = (int)($_POST['bt_id'] ?? 0);
    if ($bt_id) {
        db()->prepare('DELETE FROM belt_tests WHERE id=? AND student_id=?')->execute([$bt_id, $id]);
        audit('delete_belt_test', 'student', $id);
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Add payment waiver (inline)
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_waiver') {
    verify_csrf();
    $type   = $_POST['waiver_type'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $date   = $_POST['granted_date'] ?? date('Y-m-d');
    $valid  = ['monthly_tuition','registration','belt_test','slc_training','seminar','all'];
    if (in_array($type, $valid)) {
        db()->prepare(
            'INSERT INTO payment_waivers (student_id, waiver_type, reason, granted_by, granted_date)
             VALUES (?,?,?,?,?)'
        )->execute([$id, $type, $reason ?: null, current_user_id(), $date]);
        audit('grant_waiver', 'waiver', null, "student_id=$id type=$type");
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Add guardian link (student_guardians — no user account needed)
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_guardian') {
    verify_csrf();
    $other_id = (int)($_POST['guardian_student_id'] ?? 0);
    if ($other_id && $other_id !== $id) {
        $ts = db()->prepare('SELECT student_type FROM students WHERE id=?');
        $ts->execute([$id]);
        $stype = $ts->fetchColumn();
        [$parent_sid, $child_sid] = in_array($stype, ['parent', 'instructor'], true) ? [$id, $other_id] : [$other_id, $id];
        db()->prepare('INSERT IGNORE INTO student_guardians (parent_student_id, child_student_id) VALUES (?,?)')
             ->execute([$parent_sid, $child_sid]);
        audit('add_guardian', 'student', $id, "linked=$other_id");
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Remove guardian link
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_guardian') {
    verify_csrf();
    $link_id = (int)($_POST['guardian_link_id'] ?? 0);
    if ($link_id) {
        db()->prepare('DELETE FROM student_guardians WHERE id=? AND (parent_student_id=? OR child_student_id=?)')
             ->execute([$link_id, $id, $id]);
        audit('remove_guardian', 'student', $id, "link_id=$link_id");
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_edit.php?id=$id&ref=" . urlencode($_GET['ref'] ?? 'students'));
        exit;
    }
}

// Add new student (only when id=0)
if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_student') {
    verify_csrf();
    $first    = trim($_POST['first_name'] ?? '');
    $last     = trim($_POST['last_name']  ?? '');
    $dob      = $_POST['date_of_birth']   ?? '';
    $phone    = trim($_POST['phone']      ?? '');
    $email    = trim($_POST['email']      ?? '');
    $ec_name  = trim($_POST['ec_name']    ?? '');
    $ec_phone = trim($_POST['ec_phone']   ?? '');
    $street   = trim($_POST['street_address'] ?? '');
    $csz      = trim($_POST['city_state_zip'] ?? '');
    $reg_date = $_POST['registration_date'] ?? date('Y-m-d');
    $s_type      = in_array($_POST['account_type'] ?? '', ['guest','student','parent','instructor'])
                   ? $_POST['account_type'] : 'guest';
    $medical_note  = trim($_POST['medical_note']  ?? '');
    $uniform_size  = trim($_POST['uniform_size']  ?? '');
    $belt_size     = trim($_POST['belt_size']     ?? '');
    if (!$first || !$last) {
        $error = 'First and last name are required.';
    } else {
        db()->prepare(
            'INSERT INTO students
             (first_name,last_name,date_of_birth,phone,email,
              emergency_contact_name,emergency_contact_phone,
              street_address,city_state_zip,
              registration_date,student_type,medical_note,uniform_size,belt_size,active,active_override)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NULL)'
        )->execute([$first,$last,$dob?:null,$phone,$email,$ec_name,$ec_phone,$street?:null,$csz?:null,$reg_date,$s_type,$medical_note?:null,$uniform_size?:null,$belt_size?:null]);
        $id = (int)db()->lastInsertId();
        header("Location: student_edit.php?id=$id");
        exit;
    }
}

$student      = null;
$last_attended = null;
$linked_user   = null;
$ranks        = [];
$belt_tests   = [];
$attendance   = [];
$notes        = [];
$att_attended = 0;
$att_total    = 0;
$all_ranks    = db()->query('SELECT id, name, kyu_dan FROM ranks ORDER BY rank_order ASC')->fetchAll();
if ($id) {
    $student = db()->prepare('SELECT * FROM students WHERE id=?');
    $student->execute([$id]);
    $student = $student->fetch();
    if (!$student) { header('Location: students.php'); exit; }

    if ($student['user_id']) {
        $lu = db()->prepare('SELECT id, username, is_admin FROM users WHERE id=?');
        $lu->execute([$student['user_id']]);
        $linked_user = $lu->fetch();
    }

    $la = db()->prepare(
        'SELECT MAX(cs.session_date) FROM attendance a
         JOIN class_sessions cs ON cs.id = a.session_id
         WHERE a.student_id = ? AND a.present = 1'
    );
    $la->execute([$id]);
    $last_attended = $la->fetchColumn() ?: null;

    $att_stmt = db()->prepare(
        'SELECT cs.session_date, cs.id AS session_id, COALESCE(a.present, 0) AS present
         FROM class_sessions cs
         LEFT JOIN attendance a ON a.session_id = cs.id AND a.student_id = ?
         ORDER BY cs.session_date DESC'
    );
    $att_stmt->execute([$id]);
    $attendance   = $att_stmt->fetchAll();
    $att_attended = array_sum(array_column($attendance, 'present'));
    $att_total    = count($attendance);

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

    $pay_stmt = db()->prepare(
        'SELECT id, payment_date, payment_type, payment_method, amount
         FROM payments WHERE student_id = ? ORDER BY payment_date DESC'
    );
    $pay_stmt->execute([$id]);
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
        $guardian_candidates = db()->query(
            "SELECT id, first_name, last_name FROM students
             WHERE student_type IN ('student','guest') AND id != $id$excl
             ORDER BY first_name, last_name"
        )->fetchAll();
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
        $guardian_candidates = db()->query(
            "SELECT id, first_name, last_name FROM students
             WHERE student_type IN ('parent','instructor') AND id != $id$excl
             ORDER BY first_name, last_name"
        )->fetchAll();
    }
} else {
    $payment_waivers    = [];
    $payments           = [];
    $guardian_links     = [];
    $guardian_candidates = [];
    $is_guardian_type   = false;
}

$back_url = $id ? '../instructor/student_profile.php?id=' . $id : 'students.php';

$page_title = $id ? 'Edit Student' : 'New Student';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0"><?= $id ? 'Edit: '.htmlspecialchars($student['first_name'].' '.$student['last_name']) : 'New Student' ?></h4>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php
// Precompute display values
$acct_val    = $student['student_type'] ?? 'guest';
$is_active   = (bool)($student['active'] ?? 0);
$ov_raw      = $student['active_override'] ?? null;
$ov_val      = $ov_raw === null ? 'auto' : (string)(int)$ov_raw;
$mode_labels = ['auto' => 'Auto', '1' => 'Set Active', '0' => 'Set Inactive'];
$injury_done = (bool)($student['injury_waiver'] ?? 0);
$injury_date = $student['injury_waiver_date'] ?? null;
?>

<div class="row g-4">

    <!-- ── Left column: Profile Info + Attendance ── -->
    <div class="col-md-6 d-flex flex-column gap-4">

        <?php if ($id): ?>
        <!-- Profile Info — view/edit card -->
        <form id="profile-form" method="post"
              hx-post="student_edit.php?id=<?= $id ?>"
              hx-target="#profile-form" hx-swap="outerHTML" hx-select="#profile-form">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="ref"    value="<?= htmlspecialchars($ref) ?>">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span>Profile Info</span>
                    <div class="d-flex gap-2">
                        <button type="button" id="profileCancelBtn" class="btn btn-sm btn-secondary" style="display:none"
                                onclick="cardCancel('profile')">Cancel</button>
                        <button type="button" id="profileEditBtn" class="btn btn-sm btn-success"
                                onclick="cardToggle('profile')">Edit</button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($error): ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <!-- View mode -->
                    <div id="profile-view">
                        <?php
                        $acct_tips = [
                            'student'    => 'Paying participant ($30/month tuition)',
                            'guest'      => 'Non-paying participant (registration fee not yet paid)',
                            'parent'     => 'Family account — one tuition payment covers the whole family',
                            'instructor' => 'Teaches or assists with classes',
                            'admin'      => 'Full administrative access',
                        ];
                        $addr_parts = array_filter([
                            htmlspecialchars($student['street_address'] ?? ''),
                            htmlspecialchars($student['city_state_zip'] ?? ''),
                        ]);
                        $pv = [
                            'First Name'        => htmlspecialchars($student['first_name'] ?? '') ?: '—',
                            'Last Name'         => htmlspecialchars($student['last_name']  ?? '') ?: '—',
                            'Date of Birth'     => $student['date_of_birth'] ? date('d M Y', strtotime($student['date_of_birth'])) : '—',
                            'Phone'             => ($student['phone'] ?? '') ? fmt_phone($student['phone']) : '—',
                            'Email'             => htmlspecialchars($student['email'] ?? '') ?: '—',
                            'Emergency Contact' => htmlspecialchars($student['emergency_contact_name']  ?? '') ?: '—',
                            'Emergency Phone'   => ($student['emergency_contact_phone'] ?? '') ? fmt_phone($student['emergency_contact_phone']) : '—',
                            'Address'           => $addr_parts ? implode('<br>', $addr_parts) : '—',
                            'Member Since'      => $student['registration_date'] ? date('d M Y', strtotime($student['registration_date'])) : '—',
                        ];
                        foreach ($pv as $lbl => $val): ?>
                        <div class="d-flex py-1 border-bottom">
                            <div class="text-muted small" style="min-width:160px"><?= $lbl ?></div>
                            <div><?= $val ?></div>
                        </div>
                        <?php endforeach; ?>
                        <div class="d-flex py-1 border-bottom">
                            <div class="text-muted small" style="min-width:160px">Account Type</div>
                            <div>
                                <?= ucfirst($acct_val) ?>
                                <?php if (isset($acct_tips[$acct_val])): ?>
                                <span class="text-muted ms-1" data-bs-toggle="tooltip"
                                      title="<?= htmlspecialchars($acct_tips[$acct_val]) ?>">ⓘ</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex py-1 border-bottom">
                            <div class="text-muted small" style="min-width:160px">Active Status</div>
                            <div>
                                <?php if ($is_active): ?>
                                    <span class="badge bg-success" data-bs-toggle="tooltip"
                                          title="Active: attended class in the last 3 months">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary" data-bs-toggle="tooltip"
                                          title="Inactive: no attendance in the last 3 months">Inactive</span>
                                <?php endif; ?>
                                <?php if ($ov_val !== 'auto'): ?>
                                    <span class="badge bg-warning text-dark ms-1" data-bs-toggle="tooltip"
                                          title="Override: active/inactive status manually set by admin">Override</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex py-1 border-bottom">
                            <div class="text-muted small" style="min-width:160px">Waiver</div>
                            <div>
                                <?php if ($injury_done): ?>
                                    <span class="text-success">✓</span>
                                    <?php if ($injury_date): ?><span class="ms-1"><?= date('d M Y', strtotime($injury_date)) ?></span><?php endif; ?>
                                    <a href="waiver_view.php?student_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary ms-2">View</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex py-1">
                            <div class="text-muted small" style="min-width:160px">Uniform Size</div>
                            <div><?= htmlspecialchars($student['uniform_size'] ?? '') ?: '—' ?></div>
                        </div>
                        <div class="d-flex py-1">
                            <div class="text-muted small" style="min-width:160px">Belt Size</div>
                            <div><?= htmlspecialchars($student['belt_size'] ?? '') ?: '—' ?></div>
                        </div>
                        <div class="d-flex py-1">
                            <div class="text-muted small" style="min-width:160px">Medical Note</div>
                            <div><?= !empty($student['medical_note']) ? nl2br(htmlspecialchars($student['medical_note'])) : '—' ?></div>
                        </div>
                    </div>
                    <!-- Edit mode (hidden) -->
                    <div id="profile-edit" style="display:none" class="row g-3">
                        <div class="col-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-control" required
                                   value="<?= htmlspecialchars($student['first_name'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required
                                   value="<?= htmlspecialchars($student['last_name'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control"
                                   value="<?= htmlspecialchars($student['date_of_birth'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?= htmlspecialchars($student['phone'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($student['email'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Emergency Contact</label>
                            <input type="text" name="ec_name" class="form-control"
                                   value="<?= htmlspecialchars($student['emergency_contact_name'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Emergency Phone</label>
                            <input type="tel" name="ec_phone" class="form-control"
                                   value="<?= htmlspecialchars($student['emergency_contact_phone'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Street Address</label>
                            <input type="text" name="street_address" class="form-control"
                                   value="<?= htmlspecialchars($student['street_address'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">City, State, ZIP</label>
                            <input type="text" name="city_state_zip" class="form-control"
                                   value="<?= htmlspecialchars($student['city_state_zip'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Member Since</label>
                            <input type="date" name="registration_date" class="form-control"
                                   value="<?= htmlspecialchars($student['registration_date'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Account Type</label>
                            <select name="account_type" class="form-select">
                                <option value="guest"       <?= $acct_val==='guest'       ? 'selected':'' ?>>Guest</option>
                                <option value="student"     <?= $acct_val==='student'     ? 'selected':'' ?>>Student</option>
                                <option value="parent"      <?= $acct_val==='parent'      ? 'selected':'' ?>>Parent</option>
                                <option value="instructor"  <?= $acct_val==='instructor'  ? 'selected':'' ?>>Instructor</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Uniform Size</label>
                            <select name="uniform_size" class="form-select">
                                <option value="">— not set —</option>
                                <?php foreach (['000','00','0','1','2','3','4','5','6','7','8'] as $us): ?>
                                <option value="<?= $us ?>" <?= ($student['uniform_size'] ?? '') === $us ? 'selected' : '' ?>><?= $us ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Belt Size</label>
                            <select name="belt_size" class="form-select">
                                <option value="">— not set —</option>
                                <?php foreach (['2','3','4','5','6','7','8'] as $bs): ?>
                                <option value="<?= $bs ?>" <?= ($student['belt_size'] ?? '') === $bs ? 'selected' : '' ?>><?= $bs ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Medical Note</label>
                            <textarea name="medical_note" class="form-control" rows="2"
                                      placeholder="Allergies, conditions, medications, etc."><?= htmlspecialchars($student['medical_note'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <?php else: ?>
        <!-- New student — single full form -->
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_student">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Profile Info</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-control" required value="">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required value="">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Emergency Contact</label>
                            <input type="text" name="ec_name" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Emergency Phone</label>
                            <input type="tel" name="ec_phone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Street Address</label>
                            <input type="text" name="street_address" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">City, State, ZIP</label>
                            <input type="text" name="city_state_zip" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Member Since</label>
                            <input type="date" name="registration_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Account Type</label>
                            <select name="account_type" class="form-select">
                                <option value="guest">Guest</option>
                                <option value="student">Student</option>
                                <option value="parent">Parent</option>
                                <option value="instructor">Instructor</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Uniform Size</label>
                            <select name="uniform_size" class="form-select">
                                <option value="">— not set —</option>
                                <?php foreach (['000','00','0','1','2','3','4','5','6','7','8'] as $us): ?>
                                <option value="<?= $us ?>"><?= $us ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Belt Size</label>
                            <select name="belt_size" class="form-select">
                                <option value="">— not set —</option>
                                <?php foreach (['2','3','4','5','6','7','8'] as $bs): ?>
                                <option value="<?= $bs ?>"><?= $bs ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Medical Note</label>
                            <textarea name="medical_note" class="form-control" rows="2"
                                      placeholder="Allergies, conditions, medications, etc."></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Add to Roster</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($id): ?>
        <!-- Attendance -->
        <div id="att-card" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>
                    Recent Attendance
                </span>
                <?php if (!empty($attendance)): ?>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-secondary" id="attCancelBtn" style="display:none"
                            onclick="attCancel()">Cancel</button>
                    <button type="button" class="btn btn-sm btn-success" id="attEditBtn"
                            onclick="toggleAttEdit()">Edit</button>
                </div>
                <?php endif; ?>
            </div>
            <form id="att-form" method="post"
                  hx-post="student_edit.php?id=<?= $id ?>"
                  hx-target="#att-card" hx-swap="outerHTML" hx-select="#att-card">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_attendance">
                <div class="card-body p-0" style="max-height:320px;overflow-y:auto">
                    <?php if (empty($attendance)): ?>
                        <p class="p-3 text-muted">No classes recorded.</p>
                    <?php else: ?>
                    <table class="table table-sm table-hover mb-0">
                        <tbody>
                        <?php foreach ($attendance as $a): ?>
                            <tr>
                                <td>
                                    <a href="../instructor/attendance.php?date=<?= $a['session_date'] ?>"
                                       class="text-primary text-decoration-none">
                                        <?= date('D d M Y', strtotime($a['session_date'])) ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($a['present']): ?>
                                        <span class="badge bg-success">Present</span>
                                    <?php endif; ?>
                                    <span class="att-edit ms-2" style="display:none">
                                        <input type="checkbox" class="form-check-input"
                                               name="att_present[]"
                                               value="<?= $a['session_id'] ?>"
                                               <?= $a['present'] ? 'checked' : '' ?>>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Active Status -->
        <?php if ($id): ?>
        <form id="active-form" method="post"
              hx-post="student_edit.php?id=<?= $id ?>"
              hx-target="#active-form" hx-swap="outerHTML" hx-select="#active-form">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_active_status">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span>Active Status</span>
                    <div class="d-flex gap-2">
                        <button type="button" id="activeCancelBtn" class="btn btn-sm btn-secondary" style="display:none"
                                onclick="cardCancel('active')">Cancel</button>
                        <button type="button" id="activeEditBtn" class="btn btn-sm btn-success"
                                onclick="cardToggle('active')">Edit</button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="active-view">
                        <?= $is_active
                            ? '<span class="badge bg-success me-2">Active</span>'
                            : '<span class="badge bg-secondary me-2">Inactive</span>' ?>
                        <span class="text-muted small"><?= $mode_labels[$ov_val] ?></span>
                        <?php if ($last_attended): ?>
                            <div class="form-text mt-1">
                                Last attended: <strong><?= date('d M Y', strtotime($last_attended)) ?></strong>
                                <?php
                                $months_ago = (new DateTime($last_attended))->diff(new DateTime())->days / 30;
                                echo $months_ago > 3
                                    ? ' — <span class="text-danger">over 3 months ago</span>'
                                    : ' — <span class="text-success">within 3 months</span>';
                                ?>
                            </div>
                        <?php elseif ($id): ?>
                            <div class="form-text mt-1">No attendance on record.</div>
                        <?php endif; ?>
                    </div>
                    <div id="active-edit" style="display:none">
                        <select name="active_override" class="form-select">
                            <option value="auto" <?= $ov_val==='auto' ? 'selected':'' ?>>Auto — inactive after 3 months no attendance</option>
                            <option value="1"    <?= $ov_val==='1'    ? 'selected':'' ?>>Set Active</option>
                            <option value="0"    <?= $ov_val==='0'    ? 'selected':'' ?>>Set Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
        <?php endif; // $id — hide attendance + active status for new student ?>

    </div><!-- /left col -->

    <!-- ── Right column: Status / Waivers / History ── -->
    <div class="col-md-6 d-flex flex-column gap-3">

    <?php if ($id): ?>

        <!-- Payment History -->
        <div id="pay-card" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Payment History</span>
                <div class="d-flex gap-2">
                    <?php if (!empty($payments)): ?>
                    <button id="payEditToggle" type="button" class="btn btn-sm btn-success"
                            onclick="togglePayEdit()">Edit</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-success"
                            onclick="toggleBox('pay-add-box')">+ Add Payment</button>
                </div>
            </div>
            <div id="pay-add-box" style="display:none">
                <div class="card-body border-bottom pb-3">
                    <form method="post"
                          hx-post="student_edit.php?id=<?= $id ?>"
                          hx-target="#pay-card" hx-swap="outerHTML" hx-select="#pay-card">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="add_payment">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small">Date *</label>
                                <input type="date" name="payment_date" class="form-control form-control-sm"
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Amount *</label>
                                <input type="number" name="amount" class="form-control form-control-sm"
                                       step="0.01" min="0.01" placeholder="0.00" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Type *</label>
                                <select name="payment_type" class="form-select form-select-sm">
                                    <option value="monthly_tuition">Monthly Tuition</option>
                                    <option value="registration">Registration Fee</option>
                                    <option value="belt_test">Belt Test Fee</option>
                                    <option value="slc_training">SLC Training</option>
                                    <option value="seminar">Seminar</option>
                                    <option value="other">Other</option>
                                    <option value="donation">Donation</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Method *</label>
                                <select name="payment_method" class="form-select form-select-sm">
                                    <option value="cash">Cash</option>
                                    <option value="check">Check</option>
                                    <option value="paypal" selected>PayPal</option>
                                    <option value="mail">Mail</option>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-success">Save</button>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="toggleBox('pay-add-box')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
                <?php if (empty($payments)): ?>
                    <p class="p-3 text-muted">No payments on record.</p>
                <?php else: ?>
                <table id="payTable" class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                            <th class="pay-action-col"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr class="pay-data-row">
                            <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                            <td><?= ucwords(str_replace('_', ' ', $p['payment_type'])) ?></td>
                            <td><?= ['paypal'=>'PayPal','cash'=>'Cash','check'=>'Check','mail'=>'Mail'][$p['payment_method']] ?? ucfirst($p['payment_method']) ?></td>
                            <td class="text-end">$<?= number_format($p['amount'], 2) ?></td>
                            <td class="pay-action-col text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 me-1"
                                        onclick="togglePayRow(<?= $p['id'] ?>)">Edit</button>
                                <form method="post" class="d-inline"
                                      hx-post="student_edit.php?id=<?= $id ?>"
                                      hx-target="#pay-card" hx-swap="outerHTML" hx-select="#pay-card"
                                      onsubmit="return confirm('Delete this payment?')">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete_payment">
                                    <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger py-0">✕</button>
                                </form>
                            </td>
                        </tr>
                        <tr id="pay-edit-<?= $p['id'] ?>" class="pay-edit-row" style="display:none">
                            <td colspan="5">
                                <form method="post" class="row g-2 align-items-end py-1"
                                      hx-post="student_edit.php?id=<?= $id ?>"
                                      hx-target="#pay-card" hx-swap="outerHTML" hx-select="#pay-card">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="edit_payment">
                                    <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                    <div class="col-auto">
                                        <label class="form-label small mb-1">Date</label>
                                        <input type="date" name="payment_date" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars(date('Y-m-d', strtotime($p['payment_date']))) ?>" required>
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small mb-1">Type</label>
                                        <select name="payment_type" class="form-select form-select-sm">
                                            <?php foreach (['monthly_tuition'=>'Monthly Tuition','registration'=>'Registration Fee','belt_test'=>'Belt Test Fee','slc_training'=>'SLC Training','seminar'=>'Seminar','other'=>'Other','donation'=>'Donation'] as $tv=>$tl): ?>
                                            <option value="<?= $tv ?>" <?= $p['payment_type']===$tv?'selected':'' ?>><?= $tl ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small mb-1">Method</label>
                                        <select name="payment_method" class="form-select form-select-sm">
                                            <?php foreach (['cash'=>'Cash','check'=>'Check','paypal'=>'PayPal','mail'=>'Mail'] as $mv=>$ml): ?>
                                            <option value="<?= $mv ?>" <?= $p['payment_method']===$mv?'selected':'' ?>><?= $ml ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-auto" style="width:100px">
                                        <label class="form-label small mb-1">Amount</label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">$</span>
                                            <input type="number" name="amount" class="form-control"
                                                   step="0.01" min="0.01" value="<?= $p['amount'] ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-sm btn-success">Save</button>
                                        <button type="button" class="btn btn-sm btn-secondary"
                                                onclick="togglePayRow(<?= $p['id'] ?>)">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rank History -->
        <div id="rank-card" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Rank History</span>
                <div class="d-flex gap-2">
                    <?php if (!empty($ranks)): ?>
                    <button id="rankEditToggle" type="button" class="btn btn-sm btn-success"
                            onclick="rankEdit()">Edit</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-success"
                            onclick="toggleBox('rank-add-box')">+ Record Rank</button>
                </div>
            </div>
            <!-- Record rank form (collapsed) -->
            <div id="rank-add-box" style="display:none">
                <div class="card-body border-bottom pb-3">
                    <form method="post"
                          hx-post="student_edit.php?id=<?= $id ?>"
                          hx-target="#rank-card" hx-swap="outerHTML" hx-select="#rank-card">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="add_rank">
                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small">Rank *</label>
                                <select name="new_rank_id" class="form-select form-select-sm" required>
                                    <option value="">— select —</option>
                                    <?php foreach ($all_ranks as $ar): ?>
                                    <option value="<?= $ar['id'] ?>">
                                        <?= htmlspecialchars($ar['kyu_dan'].' — '.$ar['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label small">Date Achieved</label>
                                <input type="date" name="new_rank_date" class="form-control form-control-sm"
                                       value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-success">Save</button>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="toggleBox('rank-add-box')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- Detached delete forms — display:none keeps them out of layout -->
            <?php foreach ($ranks as $r): ?>
            <form id="rankDeleteForm-<?= $r['sr_id'] ?>" method="post" style="display:none"
                  hx-post="student_edit.php?id=<?= $id ?>"
                  hx-target="#rank-card" hx-swap="outerHTML" hx-select="#rank-card"
                  onsubmit="return confirm('Delete this rank?')">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="delete_rank">
                <input type="hidden" name="sr_id" value="<?= $r['sr_id'] ?>">
            </form>
            <?php endforeach; ?>
            <!-- Rank edit form (existing rows) -->
            <form id="rank-edit-form" method="post"
                  hx-post="student_edit.php?id=<?= $id ?>"
                  hx-target="#rank-card" hx-swap="outerHTML" hx-select="#rank-card">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_ranks">
                <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
                    <?php if (empty($ranks)): ?>
                        <p class="p-3 text-muted">No ranks recorded.</p>
                    <?php else: ?>
                    <table id="rankTable" class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Rank</th><th>Date Achieved</th><th class="rank-delete-col"></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ranks as $i => $r): ?>
                            <tr class="<?= $i===0 ? 'table-purple':'' ?>">
                                <td>
                                    <span class="rank-view-cell"><?= htmlspecialchars($r['kyu_dan'].' — '.$r['name']) ?></span>
                                    <select name="rank_updates[<?= $r['sr_id'] ?>][rank_id]"
                                            class="form-select form-select-sm rank-edit-cell" style="display:none;width:auto;max-width:160px">
                                        <?php foreach ($all_ranks as $ar): ?>
                                        <option value="<?= $ar['id'] ?>" <?= $ar['id']==$r['rank_id'] ? 'selected':'' ?>>
                                            <?= htmlspecialchars($ar['kyu_dan'].' — '.$ar['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <span class="rank-view-cell"><?= $r['achieved_date'] ? date('d M Y', strtotime($r['achieved_date'])) : '—' ?></span>
                                    <input type="date" name="rank_updates[<?= $r['sr_id'] ?>][achieved_date]"
                                           class="form-control form-control-sm rank-edit-cell" style="display:none;width:auto"
                                           value="<?= htmlspecialchars($r['achieved_date']) ?>">
                                </td>
                                <td class="rank-delete-col">
                                    <button type="submit" form="rankDeleteForm-<?= $r['sr_id'] ?>"
                                            class="btn btn-sm btn-outline-danger py-0">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Belt Test History -->
        <div id="bt-card" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Belt Test History</span>
                <div class="d-flex gap-2">
                    <?php if (!empty($belt_tests)): ?>
                    <button id="btEditToggle" type="button" class="btn btn-sm btn-success"
                            onclick="toggleBtEdit()">Edit</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-success"
                            onclick="toggleBox('bt-add-box')">+ Record Test</button>
                </div>
            </div>
            <!-- Record test form (collapsed) -->
            <div id="bt-add-box" style="display:none">
                <div class="card-body border-bottom pb-3">
                    <form method="post"
                          hx-post="student_edit.php?id=<?= $id ?>"
                          hx-target="#bt-card" hx-swap="outerHTML" hx-select="#bt-card">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="add_belt_test">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small">Date *</label>
                                <input type="date" name="test_date" class="form-control form-control-sm"
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Score % <span class="text-muted">(blank = pending)</span></label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="score" class="form-control form-control-sm"
                                           min="0" max="100" step="1" placeholder="0–100">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small">Testing For *</label>
                                <select name="rank_testing_for" class="form-select form-select-sm" required>
                                    <option value="">— select rank —</option>
                                    <?php foreach ($all_ranks as $ar): ?>
                                    <option value="<?= $ar['id'] ?>">
                                        <?= htmlspecialchars($ar['kyu_dan'].' — '.$ar['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <div class="form-check mt-1">
                                    <input type="checkbox" class="form-check-input" name="fee_paid" id="bt_fee" value="1">
                                    <label class="form-check-label small" for="bt_fee">Fee Paid</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check mt-1">
                                    <input type="checkbox" class="form-check-input" name="belt_awarded" id="bt_belt" value="1">
                                    <label class="form-check-label small" for="bt_belt">Test Passed</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-success">Save</button>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="toggleBox('bt-add-box')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
                <?php if (empty($belt_tests)): ?>
                    <p class="p-3 text-muted">No belt tests on record.</p>
                <?php else: ?>
                <?php foreach ($belt_tests as $bt): ?>
                <form id="btEditForm-<?= $bt['id'] ?>" method="post"
                      hx-post="student_edit.php?id=<?= $id ?>"
                      hx-target="#bt-card" hx-swap="outerHTML" hx-select="#bt-card">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="update_belt_test">
                    <input type="hidden" name="bt_id" value="<?= $bt['id'] ?>">
                </form>
                <?php endforeach; ?>
                <div id="btList">
                <?php foreach ($belt_tests as $bt): ?>
                <div class="border-bottom px-3 py-2">
                    <div class="bt-row-view-<?= $bt['id'] ?> d-flex align-items-center gap-3 flex-wrap">
                        <span class="text-nowrap"><?= date('d M Y', strtotime($bt['test_date'])) ?></span>
                        <span class="flex-grow-1"><?= htmlspecialchars($bt['kyu_dan']) ?></span>
                        <?php if (isset($bt['score']) && $bt['score'] !== null): ?>
                            <?php if ($bt['result']==='pass'): ?>
                                <span class="badge bg-success"><?= (int)$bt['score'] ?>%</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?= (int)$bt['score'] ?>%</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary">Pending</span>
                        <?php endif; ?>
                        <span>Fee <?= $bt['fee_paid'] ? '<span class="text-success">✓</span>' : '' ?></span>
                        <span>Passed <?= $bt['result'] === 'pass' ? '<span class="text-success">✓</span>' : ($bt['result'] === 'fail' ? '<span class="text-danger">✗</span>' : '<span class="text-muted">—</span>') ?></span>
                        <div class="d-flex gap-2 ms-auto">
                            <button type="button" onclick="btRowEdit(<?= $bt['id'] ?>)"
                                    class="btn btn-sm btn-success py-0">Edit</button>
                            <form method="post" class="d-inline bt-delete-btn"
                                  hx-post="student_edit.php?id=<?= $id ?>"
                                  hx-target="#bt-card" hx-swap="outerHTML" hx-select="#bt-card"
                                  onsubmit="return confirm('Delete this belt test?')">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete_belt_test">
                                <input type="hidden" name="bt_id" value="<?= $bt['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger py-0">✕</button>
                            </form>
                        </div>
                    </div>
                    <div class="bt-row-edit-<?= $bt['id'] ?>" style="display:none">
                        <div class="row g-2 mt-1 mb-2">
                            <div class="col-auto">
                                <label class="form-label small mb-1">Date</label>
                                <input type="date" name="test_date" form="btEditForm-<?= $bt['id'] ?>"
                                       class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($bt['test_date']) ?>">
                            </div>
                            <div class="col">
                                <label class="form-label small mb-1">Testing For</label>
                                <select name="rank_testing_for" form="btEditForm-<?= $bt['id'] ?>"
                                        class="form-select form-select-sm">
                                    <?php foreach ($all_ranks as $ar): ?>
                                    <option value="<?= $ar['id'] ?>" <?= $ar['id'] == $bt['rank_testing_for'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ar['kyu_dan'].' — '.$ar['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div>
                                <label class="form-label small mb-1">Score % <span class="text-muted">(blank = pending)</span></label>
                                <div class="input-group input-group-sm" style="width:120px">
                                    <input type="number" name="score" form="btEditForm-<?= $bt['id'] ?>"
                                           class="form-control form-control-sm" min="0" max="100" step="1"
                                           value="<?= (isset($bt['score']) && $bt['score'] !== null) ? (int)$bt['score'] : '' ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="fee_paid"
                                       form="btEditForm-<?= $bt['id'] ?>" value="1"
                                       id="fee_<?= $bt['id'] ?>" <?= $bt['fee_paid'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="fee_<?= $bt['id'] ?>">Fee Paid</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="belt_awarded"
                                       form="btEditForm-<?= $bt['id'] ?>" value="1"
                                       id="belt_<?= $bt['id'] ?>" <?= $bt['belt_awarded'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="belt_<?= $bt['id'] ?>">Test Passed</label>
                            </div>
                            <div class="d-flex gap-2 ms-auto">
                                <button type="button" class="btn btn-sm btn-secondary"
                                        onclick="btRowCancel(<?= $bt['id'] ?>)">Cancel</button>
                                <button type="submit" form="btEditForm-<?= $bt['id'] ?>"
                                        class="btn btn-sm btn-success">Save</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Waiver -->
        <div id="pw-card" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Exempt</span>
                <div class="d-flex gap-2">
                    <?php if (!empty($payment_waivers)): ?>
                    <button id="pwEditToggle" type="button" class="btn btn-sm btn-success"
                            onclick="togglePwEdit()">Edit</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-success"
                            onclick="toggleBox('pw-add-box')">+ Add Exemption</button>
                </div>
            </div>
            <!-- Add waiver form (collapsed) -->
            <div id="pw-add-box" style="display:none">
                <div class="card-body border-bottom pb-3">
                    <form method="post"
                          hx-post="student_edit.php?id=<?= $id ?>"
                          hx-target="#pw-card" hx-swap="outerHTML" hx-select="#pw-card">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="add_waiver">
                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small">Type *</label>
                                <select name="waiver_type" class="form-select form-select-sm" required>
                                    <option value="monthly_tuition">Monthly Tuition</option>
                                    <option value="registration">Registration Fee</option>
                                    <option value="belt_test">Belt Test Fee</option>
                                    <option value="slc_training">SLC Training</option>
                                    <option value="seminar">Seminar</option>
                                    <option value="all">All Fees</option>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label small">Date</label>
                                <input type="date" name="granted_date" class="form-control form-control-sm"
                                       value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label small">Reason</label>
                                <input type="text" name="reason" class="form-control form-control-sm"
                                       placeholder="Optional">
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-success">Save</button>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="toggleBox('pw-add-box')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body<?= empty($payment_waivers) ? '' : ' p-0' ?>" style="max-height:260px;overflow-y:auto">
                <?php if (empty($payment_waivers)): ?>
                    <p class="text-muted mb-0">No exemptions on record.</p>
                <?php else: ?>
                <table id="pwTable" class="table table-sm table-hover mb-0 align-middle">
                    <tbody>
                    <?php foreach ($payment_waivers as $pw): ?>
                        <tr class="pw-data-row">
                            <td>
                                <?= htmlspecialchars(ucwords(str_replace('_',' ',$pw['waiver_type']))) ?>
                                <?php if (!empty($pw['reason'])): ?>
                                    <div class="text-muted small"><?= htmlspecialchars($pw['reason']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?= date('d M Y', strtotime($pw['granted_date'])) ?></td>
                            <td class="pw-action-col text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 me-1"
                                        onclick="togglePwRow(<?= $pw['id'] ?>)">Edit</button>
                                <form method="post" class="d-inline"
                                      hx-post="student_edit.php?id=<?= $id ?>"
                                      hx-target="#pw-card" hx-swap="outerHTML" hx-select="#pw-card"
                                      onsubmit="return confirm('Delete this waiver?')">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete_waiver">
                                    <input type="hidden" name="waiver_id" value="<?= $pw['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger py-0">✕</button>
                                </form>
                            </td>
                        </tr>
                        <tr id="pw-edit-<?= $pw['id'] ?>" class="pw-edit-row" style="display:none">
                            <td colspan="3">
                                <form method="post" class="row g-2 align-items-end py-1"
                                      hx-post="student_edit.php?id=<?= $id ?>"
                                      hx-target="#pw-card" hx-swap="outerHTML" hx-select="#pw-card">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="edit_waiver">
                                    <input type="hidden" name="waiver_id" value="<?= $pw['id'] ?>">
                                    <div class="col-auto">
                                        <label class="form-label small mb-1">Type</label>
                                        <select name="waiver_type" class="form-select form-select-sm">
                                            <?php foreach (['monthly_tuition'=>'Monthly Tuition','registration'=>'Registration Fee','belt_test'=>'Belt Test Fee','slc_training'=>'SLC Training','seminar'=>'Seminar','all'=>'All Fees'] as $tv=>$tl): ?>
                                            <option value="<?= $tv ?>" <?= $pw['waiver_type']===$tv?'selected':'' ?>><?= $tl ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-auto" style="width:160px">
                                        <label class="form-label small mb-1">Date</label>
                                        <input type="date" name="granted_date" class="form-control form-control-sm w-100"
                                               value="<?= htmlspecialchars($pw['granted_date']) ?>" required>
                                    </div>
                                    <div class="col">
                                        <label class="form-label small mb-1">Reason</label>
                                        <input type="text" name="reason" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars($pw['reason'] ?? '') ?>" placeholder="Optional">
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-sm btn-success">Save</button>
                                        <button type="button" class="btn btn-sm btn-secondary"
                                                onclick="togglePwRow(<?= $pw['id'] ?>)">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Guardian / Children -->
        <div id="guardian-card" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><?= $is_guardian_type ? 'Linked Children' : 'Guardian / Parent' ?></span>
                <div class="d-flex gap-2">
                    <?php if (!empty($guardian_links)): ?>
                    <button id="guardianEditToggle" type="button" class="btn btn-sm btn-success"
                            onclick="toggleGuardianEdit()">Edit</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-success"
                            onclick="toggleBox('guardian-add-box')">+ Link</button>
                </div>
            </div>
            <div id="guardian-add-box" style="display:none">
                <div class="card-body border-bottom pb-3">
                    <form method="post" class="d-flex gap-2 align-items-center flex-wrap"
                          hx-post="student_edit.php?id=<?= $id ?>"
                          hx-target="#guardian-card" hx-swap="outerHTML" hx-select="#guardian-card">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="add_guardian">
                        <?php if (!empty($guardian_candidates)): ?>
                            <select name="guardian_student_id" class="form-select form-select-sm"
                                    style="max-width:240px" required>
                                <option value="">— select —</option>
                                <?php foreach ($guardian_candidates as $gc): ?>
                                    <option value="<?= $gc['id'] ?>">
                                        <?= htmlspecialchars($gc['first_name'] . ' ' . $gc['last_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-success">Add</button>
                        <?php else: ?>
                            <span class="text-muted small">
                                No <?= $is_parent_type ? 'child' : 'parent' ?> records available to link.
                            </span>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-secondary"
                                onclick="toggleBox('guardian-add-box')">Cancel</button>
                    </form>
                </div>
            </div>
            <div class="card-body<?= empty($guardian_links) ? '' : ' p-0' ?>" style="max-height:260px;overflow-y:auto">
                <?php if (empty($guardian_links)): ?>
                    <p class="text-muted mb-0">None linked.</p>
                <?php else: ?>
                <table id="guardianTable" class="table table-sm table-hover mb-0 align-middle">
                    <tbody>
                    <?php foreach ($guardian_links as $gl): ?>
                        <tr>
                            <td>
                                <a href="student_edit.php?id=<?= $gl['student_id'] ?>"
                                   class="text-decoration-none">
                                    <?= htmlspecialchars($gl['first_name'] . ' ' . $gl['last_name']) ?>
                                </a>
                            </td>
                            <td class="guardian-delete-col text-end">
                                <form method="post" class="d-inline"
                                      hx-post="student_edit.php?id=<?= $id ?>"
                                      hx-target="#guardian-card" hx-swap="outerHTML" hx-select="#guardian-card"
                                      onsubmit="return confirm('Remove this link?')">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="remove_guardian">
                                    <input type="hidden" name="guardian_link_id" value="<?= $gl['link_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger py-0">✕</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Waiver -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Waiver</span>
                <a href="waiver_view.php?student_id=<?= $id ?>" class="btn btn-sm btn-success">
                    <?= $injury_done ? 'View' : '+ Enter Waiver' ?>
                </a>
            </div>
            <div class="card-body">
                <?php if ($injury_done): ?>
                    <span class="badge bg-success">Completed</span>
                    <?php if ($injury_date): ?>
                        <span class="ms-1"><?= date('d M Y', strtotime($injury_date)) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-muted">Not completed</span>
                <?php endif; ?>
            </div>
        </div>


    <?php endif; /* $id */ ?>
    </div><!-- /right col -->

</div><!-- /row -->

<style>
    .pw-action-col      { display:none !important; }
    .rank-delete-col    { display:none !important; }
    .note-delete        { display:none !important; }
    .bt-delete-btn      { display:none !important; }
    .pay-action-col     { display:none !important; }
    .guardian-delete-col{ display:none !important; }
    #pwTable.editing       .pw-action-col       { display:table-cell !important; }
    #rankTable.editing     .rank-delete-col     { display:table-cell !important; }
    #notesContainer.notes-editing .note-delete  { display:inline-block !important; }
    #btList.editing        .bt-delete-btn       { display:inline !important; }
    #payTable.editing      .pay-action-col      { display:table-cell !important; }
    #guardianTable.editing .guardian-delete-col { display:table-cell !important; }
</style>
<script>
// Generic: toggle a collapsible add-box
function toggleBox(id) {
    var el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// Generic: Edit → Confirm pattern for single-value cards (profile, active, injury)
// cardId matches: view→ #<id>-view, edit→ #<id>-edit, btn→ #<id>EditBtn, form→ #<id>-form
function cardToggle(cardId) {
    var btn    = document.getElementById(cardId + 'EditBtn');
    var cancel = document.getElementById(cardId + 'CancelBtn');
    var view   = document.getElementById(cardId + '-view');
    var edit   = document.getElementById(cardId + '-edit');
    if (btn.dataset.editing !== 'true') {
        btn.dataset.editing = 'true';
        btn.textContent = 'Confirm';
        btn.classList.replace('btn-success', 'btn-warning');
        if (cancel) cancel.style.display = '';
        if (view) view.style.display = 'none';
        if (edit) edit.style.display = '';
    } else {
        // Programmatic submit doesn't fire the submit event, so clear dirty manually
        if (typeof setFormClean === 'function') setFormClean();
        var form = document.getElementById(cardId + '-form');
        form.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true}));
    }
}
function cardCancel(cardId) {
    var btn    = document.getElementById(cardId + 'EditBtn');
    var cancel = document.getElementById(cardId + 'CancelBtn');
    var view   = document.getElementById(cardId + '-view');
    var edit   = document.getElementById(cardId + '-edit');
    btn.dataset.editing = 'false';
    btn.textContent = 'Edit';
    btn.classList.replace('btn-warning', 'btn-success');
    if (cancel) cancel.style.display = 'none';
    if (view) view.style.display = '';
    if (edit) edit.style.display = 'none';
    // Reset form fields to their original server-rendered values and clear dirty flag
    var form = document.getElementById(cardId + '-form');
    if (form) form.reset();
    if (typeof setFormClean === 'function') setFormClean();
}

// Attendance
var attEditing = false;
function toggleAttEdit() {
    var btn    = document.getElementById('attEditBtn');
    var cancel = document.getElementById('attCancelBtn');
    if (!attEditing) {
        attEditing = true;
        btn.textContent = 'Confirm';
        btn.classList.replace('btn-success', 'btn-warning');
        cancel.style.display = '';
        document.querySelectorAll('.att-edit').forEach(function(el) { el.style.display = ''; });
    } else {
        if (typeof setFormClean === 'function') setFormClean();
        attEditing = false;
        var form = document.getElementById('att-form');
        form.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true}));
    }
}
function attCancel() {
    var btn    = document.getElementById('attEditBtn');
    var cancel = document.getElementById('attCancelBtn');
    attEditing = false;
    btn.textContent = 'Edit';
    btn.classList.replace('btn-warning', 'btn-success');
    cancel.style.display = 'none';
    document.querySelectorAll('.att-edit').forEach(function(el) { el.style.display = 'none'; });
    var form = document.getElementById('att-form');
    if (form) form.reset();
    if (typeof setFormClean === 'function') setFormClean();
}
document.querySelectorAll('.att-edit input[type="checkbox"]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var badge = this.closest('td').querySelector('.badge');
        if (this.checked) { badge.className = 'badge bg-success'; badge.textContent = 'Present'; badge.style.display = ''; }
        else               { badge.style.display = 'none'; }
    });
});

// Payment history — edit toggle reveals action column; per-row edit form
function togglePayEdit() {
    var table = document.getElementById('payTable');
    var btn   = document.getElementById('payEditToggle');
    var on    = table.classList.toggle('editing');
    btn.textContent = on ? 'Done' : 'Edit';
    btn.className   = on ? 'btn btn-sm btn-warning' : 'btn btn-sm btn-success';
    // Collapse any open edit rows when toggling off
    if (!on) {
        table.querySelectorAll('.pay-edit-row').forEach(function(r) { r.style.display = 'none'; });
        if (typeof setFormClean === 'function') setFormClean();
    }
}
function togglePayRow(pid) {
    var row = document.getElementById('pay-edit-' + pid);
    if (!row) return;
    var closing = row.style.display !== 'none';
    row.style.display = closing ? 'none' : '';
    if (closing && typeof setFormClean === 'function') setFormClean();
}

// Guardian links — edit reveals delete column
function toggleGuardianEdit() {
    var table = document.getElementById('guardianTable');
    var btn   = document.getElementById('guardianEditToggle');
    var on    = table.classList.toggle('editing');
    btn.textContent = on ? 'Done' : 'Edit';
    btn.className   = on ? 'btn btn-sm btn-warning' : 'btn btn-sm btn-success';
}

// Payment waivers — edit reveals action column; per-row edit form
function togglePwEdit() {
    var table = document.getElementById('pwTable');
    var btn   = document.getElementById('pwEditToggle');
    var on    = table.classList.toggle('editing');
    btn.textContent = on ? 'Done' : 'Edit';
    btn.className   = on ? 'btn btn-sm btn-warning' : 'btn btn-sm btn-success';
    // Collapse any open edit rows when toggling off
    if (!on) {
        table.querySelectorAll('.pw-edit-row').forEach(function(r) { r.style.display = 'none'; });
        if (typeof setFormClean === 'function') setFormClean();
    }
}
function togglePwRow(wid) {
    var row = document.getElementById('pw-edit-' + wid);
    if (!row) return;
    var closing = row.style.display !== 'none';
    row.style.display = closing ? 'none' : '';
    if (closing && typeof setFormClean === 'function') setFormClean();
}

// Belt awarded can't be checked when result is Fail
function wireBeltAwarded(resultSel, beltCb) {
    function sync() {
        var isFail = resultSel.value !== 'pass';
        beltCb.disabled = isFail;
        if (isFail) beltCb.checked = false;
    }
    resultSel.addEventListener('change', sync);
    sync();
}

// Belt test row — Edit hides view row, reveals 2 edit rows
function btRowEdit(id) {
    document.querySelector('.bt-row-view-' + id).style.display = 'none';
    document.querySelectorAll('.bt-row-edit-' + id).forEach(function(el) { el.style.display = ''; });
}
function btRowCancel(id) {
    document.querySelector('.bt-row-view-' + id).style.display = '';
    document.querySelectorAll('.bt-row-edit-' + id).forEach(function(el) { el.style.display = 'none'; });
    if (typeof setFormClean === 'function') setFormClean();
}

// Belt tests card — Edit reveals ✕ buttons
function toggleBtEdit() {
    var list = document.getElementById('btList');
    var btn  = document.getElementById('btEditToggle');
    var on   = list.classList.toggle('editing');
    btn.textContent = on ? 'Done' : 'Edit';
    btn.className   = on ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-success';
}

// Wire belt-awarded constraint for "Record Test" add form
(function() {
    var rs = document.querySelector('#bt-add-box select[name="result"]');
    var bc = document.querySelector('#bt-add-box input[name="belt_awarded"]');
    if (rs && bc) wireBeltAwarded(rs, bc);
})();

// Wire belt-awarded constraint for each per-row edit form
<?php foreach ($belt_tests as $bt): ?>
(function() {
    var rs = document.querySelector('select[name="result"][form="btEditForm-<?= $bt['id'] ?>"]');
    var bc = document.querySelector('input[name="belt_awarded"][form="btEditForm-<?= $bt['id'] ?>"]');
    if (rs && bc) wireBeltAwarded(rs, bc);
})();
<?php endforeach; ?>

// Rank history — edit makes selects/dates visible + reveals ✕, Confirm submits
var rankEditing = false;
function rankEdit() {
    var btn = document.getElementById('rankEditToggle');
    if (!rankEditing) {
        rankEditing = true;
        btn.textContent = 'Done';
        btn.classList.replace('btn-success', 'btn-danger');
        document.getElementById('rankTable').classList.add('editing');
        document.querySelectorAll('.rank-view-cell').forEach(function(el) { el.style.display = 'none'; });
        document.querySelectorAll('.rank-edit-cell').forEach(function(el) { el.style.display = ''; });
    } else {
        if (typeof setFormClean === 'function') setFormClean();
        rankEditing = false;
        var form = document.getElementById('rank-edit-form');
        form.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true}));
    }
}
</script>

<?php if ($id): ?>
<!-- ── Notes (full width) ── -->
<div id="notes-card" class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Student Notes</span>
        <div class="d-flex gap-2">
            <?php if (!empty($notes)): ?>
            <button type="button" id="notesEditBtn" class="btn btn-sm btn-success"
                    onclick="toggleNotesEdit()">Edit</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add note (always visible) -->
    <div id="addNoteBox">
        <div class="card-body border-bottom pb-3">
            <form method="post" id="addNoteForm"
                  hx-post="student_edit.php?id=<?= $id ?>"
                  hx-target="#notes-card" hx-swap="outerHTML" hx-select="#notes-card">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="add_note">
                <textarea name="note_content" id="addNoteText" class="form-control form-control-sm mb-2"
                          rows="3" placeholder="Add a note…" required></textarea>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Save Note</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing notes -->
    <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
        <?php if (empty($notes)): ?>
            <p class="p-3 text-muted">No notes yet.</p>
        <?php else: ?>
        <div id="notesContainer">
        <?php foreach ($notes as $n): ?>
        <div class="border-bottom p-3" id="note-wrap-<?= $n['id'] ?>">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <small class="text-muted">
                    <?= date('d M Y g:i a', strtotime($n['created_at'])) ?>
                    · <strong><?= htmlspecialchars($n['username'] ?? 'unknown') ?></strong>
                </small>
                <div class="d-flex gap-1 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-success py-0"
                            onclick="noteEdit(<?= $n['id'] ?>)">Edit</button>
                    <form method="post" class="d-inline note-delete"
                          hx-post="student_edit.php?id=<?= $id ?>"
                          hx-target="#notes-card" hx-swap="outerHTML" hx-select="#notes-card"
                          onsubmit="return confirm('Delete this note?')">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="delete_note">
                        <input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0">✕</button>
                    </form>
                </div>
            </div>
            <p class="mb-0 mt-1 note-view-<?= $n['id'] ?>"><?= nl2br(htmlspecialchars($n['content'])) ?></p>
            <form method="post" class="mt-2 note-edit-<?= $n['id'] ?>" style="display:none"
                  hx-post="student_edit.php?id=<?= $id ?>"
                  hx-target="#notes-card" hx-swap="outerHTML" hx-select="#notes-card">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="edit_note">
                <input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                <textarea name="note_content" class="form-control form-control-sm mb-2" rows="3"
                          required><?= htmlspecialchars($n['content']) ?></textarea>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    <button type="button" class="btn btn-sm btn-secondary"
                            onclick="noteCancel(<?= $n['id'] ?>)">Cancel</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Student notes — edit reveals ✕ buttons
var notesEditing = false;
function toggleNotesEdit() {
    var btn       = document.getElementById('notesEditBtn');
    var container = document.getElementById('notesContainer');
    notesEditing  = !notesEditing;
    btn.textContent = notesEditing ? 'Done' : 'Edit';
    btn.className   = notesEditing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-success';
    if (container) container.classList.toggle('notes-editing', notesEditing);
}

function toggleAddNote() {
    var box = document.getElementById('addNoteBox');
    var visible = box.style.display !== 'none';
    box.style.display = visible ? 'none' : 'block';
    if (!visible) {
        document.getElementById('addNoteText').focus();
    }
}
function noteEdit(id) {
    document.querySelector('.note-view-' + id).style.display = 'none';
    document.querySelector('.note-edit-' + id).style.display = 'block';
}
function noteCancel(id) {
    document.querySelector('.note-view-' + id).style.display = '';
    document.querySelector('.note-edit-' + id).style.display = 'none';
    if (typeof setFormClean === 'function') setFormClean();
}
</script>
<script>
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
    new bootstrap.Tooltip(el);
});
</script>

<?php endif; // $id — notes card ?>

<?php if ($id): ?>
<div class="mt-4 text-end">
    <form method="post" class="d-inline"
          onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($student['first_name'] . ' ' . $student['last_name'])) ?>?\n\nThis removes their profile, attendance, payments, and login account. This cannot be undone.')">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="delete_profile">
        <button type="submit" class="btn btn-outline-danger">Delete Profile</button>
    </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

