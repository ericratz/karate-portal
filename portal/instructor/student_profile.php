<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('student', 'instructor', 'admin');
function fmt_phone(string $p): string { $d = preg_replace('/\D/', '', $p); return strlen($d) === 10 ? substr($d,0,3).'-'.substr($d,3,3).'-'.substr($d,6) : $p; }

$id = get_int('id');
if (!$id) { header('Location: index.php'); exit; }

// Students may only view their own profile
if (has_role('student') && !has_role('instructor', 'admin')) {
    $own = db()->prepare('SELECT id FROM students WHERE user_id = ?');
    $own->execute([current_user_id()]);
    $own_id = (int)$own->fetchColumn();
    if (!$own_id || $own_id !== $id) {
        header('Location: ../student/index.php');
        exit;
    }
}

// Instructor/Admin: add a note (write-only for instructors)
$note_error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_note') {
    verify_csrf();
    $content = trim(post_str('note_content'));
    if ($content === '') {
        $note_error = 'Note cannot be empty.';
    } else {
        db()->prepare('INSERT INTO student_notes (student_id, content, created_by) VALUES (?,?,?)')
             ->execute([$id, $content, current_user_id()]);
        audit('add_note', 'student', $id);
        if (empty($_SERVER['HTTP_HX_REQUEST'])) {
            header("Location: student_profile.php?id=$id&noted=1");
            exit;
        }
        $note_just_added = true;
    }
}

// Instructor/Admin: bulk update attendance from checkboxes
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_attendance') {
    verify_csrf();
    if (!has_role('instructor', 'admin')) { header("Location: student_profile.php?id=$id"); exit; }
    $att_present = $_POST['att_present'] ?? [];
    $present_ids = array_map('intval', is_array($att_present) ? $att_present : []);
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
        header("Location: student_profile.php?id=$id");
        exit;
    }
}

$student = db()->prepare(
    'SELECT s.*, u.username, u.email AS login_email, u.last_login
     FROM students s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?'
);
$student->execute([$id]);
$student = $student->fetch();
if (!$student) { header('Location: index.php'); exit; }

$profile_error  = '';
$profile_saved  = false;
$note_just_added = $note_just_added ?? false;

// Own-profile edit (student or instructor viewing their own record)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    verify_csrf();
    if ((int)($student['user_id'] ?? 0) !== current_user_id()) {
        header("Location: student_profile.php?id=$id"); exit;
    }
    $first    = trim(post_str('first_name'));
    $last     = trim(post_str('last_name'));
    $dob      = post_str('date_of_birth');
    $phone    = trim(post_str('phone'));
    $email    = trim(post_str('email'));
    $ec_name  = trim(post_str('ec_name'));
    $ec_phone = trim(post_str('ec_phone'));
    $street   = trim(post_str('street_address'));
    $csz      = trim(post_str('city_state_zip'));
    $medical  = trim(post_str('medical_note'));
    if (!$first || !$last) {
        $profile_error = 'First and last name are required.';
    } else {
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
        $profile_saved = true;
        $sq = db()->prepare('SELECT s.*, u.username, u.email AS login_email, u.last_login FROM students s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?');
        $sq->execute([$id]);
        $student = $sq->fetch();
    }
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_profile.php?id=$id"); exit;
    }
}

// Full rank history
$ranks = db()->prepare(
    'SELECT sr.id AS sr_id, sr.rank_id, r.name, r.kyu_dan, sr.achieved_date, sr.notes
     FROM student_ranks sr JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ? ORDER BY r.rank_order DESC'
);
$ranks->execute([$id]);
$ranks = $ranks->fetchAll();

// All class sessions and whether this student was present
$attendance = db()->prepare(
    'SELECT cs.session_date, cs.id AS session_id,
            COALESCE(a.present, 0) AS present
     FROM class_sessions cs
     LEFT JOIN attendance a ON a.session_id = cs.id AND a.student_id = ?
     ORDER BY cs.session_date DESC'
);
$attendance->execute([$id]);
$attendance = $attendance->fetchAll();

$total    = count($attendance);
$attended = array_sum(array_column($attendance, 'present'));
$pct      = $total ? round($attended / $total * 100) : 0;


// Belt test history
$belt_tests = db()->prepare(
    'SELECT bt.id, bt.test_date, bt.result, bt.score, bt.fee_paid, bt.belt_awarded,
            r.kyu_dan, r.name AS rank_name
     FROM belt_tests bt
     JOIN ranks r ON r.id = bt.rank_testing_for
     WHERE bt.student_id = ?
     ORDER BY bt.test_date DESC'
);
$belt_tests->execute([$id]);
$belt_tests = $belt_tests->fetchAll();

// Payment history — linked donations merged in as type 'donation'
$payments = db()->prepare(
    'SELECT payment_date, payment_type, payment_method, amount, transaction_id, notes
     FROM payments WHERE student_id = ?
     UNION ALL
     SELECT payment_date, \'donation\', payment_method, amount, NULL, notes
     FROM donations WHERE student_id = ?
     ORDER BY payment_date DESC'
);
$payments->execute([$id, $id]);
$payments = $payments->fetchAll();

// Notes (instructor/admin only)
$notes = [];
if (has_role('instructor', 'admin')) {
    $notes_stmt = db()->prepare(
        'SELECT sn.id, sn.content, sn.created_at, u.username
         FROM student_notes sn
         LEFT JOIN users u ON u.id = sn.created_by
         WHERE sn.student_id = ?
         ORDER BY sn.created_at DESC'
    );
    $notes_stmt->execute([$id]);
    $notes = $notes_stmt->fetchAll();
}

// ── Family tabs ───────────────────────────────────────────────
// Build a tab list if this student is a parent or is a child of a parent.
$family_tabs = [];

if (in_array($student['student_type'], ['parent', 'instructor'], true)) {
    // Viewing a parent or instructor — load children via student_guardians
    $ch_stmt = db()->prepare(
        'SELECT s.id, s.first_name, s.last_name
         FROM student_guardians sg JOIN students s ON s.id = sg.child_student_id
         WHERE sg.parent_student_id = ? ORDER BY s.first_name, s.last_name'
    );
    $ch_stmt->execute([$id]);
    $children = $ch_stmt->fetchAll();

    if (!empty($children)) {
        $family_tabs[] = ['id' => $id, 'name' => $student['first_name'] . ' ' . $student['last_name'], 'role' => 'parent'];
        foreach ($children as $ch) {
            $family_tabs[] = ['id' => $ch['id'], 'name' => $ch['first_name'] . ' ' . $ch['last_name'], 'role' => 'child'];
        }
    }
} else {
    // Viewing a child — find parent and siblings via student_guardians
    $pg_stmt = db()->prepare(
        'SELECT sp.id AS parent_sid, sp.first_name AS par_first, sp.last_name AS par_last
         FROM student_guardians sg JOIN students sp ON sp.id = sg.parent_student_id
         WHERE sg.child_student_id = ? LIMIT 1'
    );
    $pg_stmt->execute([$id]);
    $pg_row = $pg_stmt->fetch();

    if ($pg_row) {
        $par_row = $pg_row;
        $sib_stmt = db()->prepare(
            'SELECT s.id, s.first_name, s.last_name
             FROM student_guardians sg JOIN students s ON s.id = sg.child_student_id
             WHERE sg.parent_student_id = ? ORDER BY s.first_name, s.last_name'
        );
        $sib_stmt->execute([$pg_row['parent_sid']]);
        $siblings = $sib_stmt->fetchAll();
    } else {
        $par_row = null;
        $siblings = [];
    }

    if ($par_row) {
        $family_tabs[] = ['id' => $par_row['parent_sid'], 'name' => $par_row['par_first'] . ' ' . $par_row['par_last'], 'role' => 'parent'];
        foreach ($siblings as $sib) {
            $family_tabs[] = ['id' => $sib['id'], 'name' => $sib['first_name'] . ' ' . $sib['last_name'], 'role' => 'child'];
        }
    }
}

$page_title = ucwords(strtolower($student['first_name'] . ' ' . $student['last_name']));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-3">
    <h4 class="mb-0">
        <?= hn($student['first_name'] . ' ' . $student['last_name']) ?>
        <?php if (!$student['active']): ?>
            <span class="badge bg-secondary ms-2">Inactive</span>
        <?php endif; ?>
    </h4>
    <?php if (has_role('admin')): ?>
    <div class="ms-auto d-flex gap-2">
        <a href="../admin/member_card.php?student_id=<?= $id ?>" target="_blank"
           class="btn btn-sm btn-outline-secondary">Member Card</a>
        <a href="../admin/student_edit.php?id=<?= $id ?>&ref=profile"
           class="btn btn-sm btn-success">Full Edit</a>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($family_tabs)): ?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($family_tabs as $tab): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab['id'] === $id ? 'active' : '' ?>"
           href="student_profile.php?id=<?= $tab['id'] ?>">
            <?= hn($tab['name']) ?>
            <?php if ($tab['role'] === 'parent'): ?>
                <span class="badge bg-info text-dark ms-1" style="font-size:.6rem;vertical-align:middle">Parent</span>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Left: Profile + Attendance ── -->
    <div class="col-md-6 d-flex flex-column gap-4">

        <!-- Profile Info -->
        <?php
        $can_edit_profile = (has_role('student') || has_role('instructor'))
                            && (int)($student['user_id'] ?? 0) === current_user_id();
        $acct_tips = [
            'student'    => 'Registration fee paid',
            'guest'      => 'Non-paying participant (registration fee not yet paid)',
            'parent'     => 'Family account',
            'instructor' => 'Teaches or assists with classes',
            'admin'      => 'Full administrative access',
        ];
        $type_badges = [
            'admin'      => '<span class="badge bg-danger">Admin</span>',
            'instructor' => '<span class="badge bg-warning text-dark">Instructor</span>',
            'student'    => '<span class="badge bg-primary">Student</span>',
            'parent'     => '<span class="badge bg-info text-dark">Parent</span>',
            'guest'      => '<span class="badge bg-secondary">Guest</span>',
        ];
        $tip = $acct_tips[$student['student_type']] ?? '';
        if ($student['injury_waiver']) {
            $waiver_url = has_role('instructor', 'admin')
                ? '../admin/waiver_view.php?student_id=' . $student['id']
                : '../student/waiver.php';
            $waiver_val = '<span class="text-success">✓</span> '
                . ($student['injury_waiver_date'] ? date('d M Y', strtotime($student['injury_waiver_date'])) : '')
                . ' <a href="' . $waiver_url . '" class="btn btn-sm btn-outline-secondary ms-2">View</a>';
        } else {
            $waiver_val = '—';
        }
        $addr_parts = array_filter([
            htmlspecialchars($student['street_address'] ?? ''),
            htmlspecialchars($student['city_state_zip'] ?? ''),
        ]);
        ?>
        <div id="profile-card" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Profile Info</span>
                <?php if ($can_edit_profile): ?>
                <div class="d-flex gap-2">
                    <button type="button" id="profileCancelBtn" class="btn btn-sm btn-secondary card-cancel-btn" style="display:none"
                            data-card-id="profile">Cancel</button>
                    <button type="button" id="profileEditBtn" class="btn btn-sm btn-success card-toggle-btn"
                            data-card-id="profile">Edit</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body py-2 px-3">
                <?php if ($profile_error): ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($profile_error) ?></div><?php endif; ?>
                <?php if ($profile_saved): ?><div class="alert alert-success py-2 mb-3">Profile saved.</div><?php endif; ?>
                <!-- View mode -->
                <div id="profile-view">
                    <?php
                    $pv = [
                        'Date of Birth'     => $student['date_of_birth'] ? date('d M Y', strtotime($student['date_of_birth'])) : '—',
                        'Phone'             => ($student['phone'] ?? '') ? fmt_phone($student['phone']) : '—',
                        'Email'             => htmlspecialchars($student['email'] ?? '') ?: '—',
                        'Emergency Contact' => htmlspecialchars($student['emergency_contact_name']  ?? '') ?: '—',
                        'Emergency Phone'   => ($student['emergency_contact_phone'] ?? '') ? fmt_phone($student['emergency_contact_phone']) : '—',
                        'Address'           => $addr_parts ? implode('<br>', $addr_parts) : '—',
                        'Member Since'      => date('d M Y', strtotime($student['registration_date'])),
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
                            <?= $type_badges[$student['student_type']] ?? '<span class="badge bg-secondary">Guest</span>' ?>
                            <?php if ($tip): ?>
                            <span class="text-muted ms-1" data-bs-toggle="tooltip" title="<?= htmlspecialchars($tip) ?>">ⓘ</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex py-1 border-bottom">
                        <div class="text-muted small" style="min-width:160px">Waiver</div>
                        <div><?= $waiver_val ?></div>
                    </div>
                    <div class="d-flex py-1 border-bottom">
                        <div class="text-muted small" style="min-width:160px">Account</div>
                        <div><?= $student['username'] ? htmlspecialchars($student['username']) : '<span class="text-muted">No login</span>' ?></div>
                    </div>
                    <div class="d-flex py-1 border-bottom">
                        <div class="text-muted small" style="min-width:160px">Last Login</div>
                        <div><?= $student['last_login'] ? date('d M Y', strtotime($student['last_login'])) : '—' ?></div>
                    </div>
                    <div class="d-flex py-1 border-bottom">
                        <div class="text-muted small" style="min-width:160px">Uniform Size</div>
                        <div><?= htmlspecialchars($student['uniform_size'] ?? '') ?: '—' ?></div>
                    </div>
                    <div class="d-flex py-1 border-bottom">
                        <div class="text-muted small" style="min-width:160px">Belt Size</div>
                        <div><?= htmlspecialchars($student['belt_size'] ?? '') ?: '—' ?></div>
                    </div>
                    <div class="d-flex py-1">
                        <div class="text-muted small" style="min-width:160px">Medical Note</div>
                        <div><?= !empty($student['medical_note']) ? nl2br(htmlspecialchars($student['medical_note'])) : '—' ?></div>
                    </div>
                </div>
                <?php if ($can_edit_profile): ?>
                <!-- Edit mode (hidden) -->
                <div id="profile-edit" style="display:none">
                    <form id="profile-form" method="post" class="row g-3"
                          hx-post="student_profile.php?id=<?= $id ?>"
                          hx-target="#profile-card" hx-swap="outerHTML" hx-select="#profile-card">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="update_profile">
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
                        <div class="col-12">
                            <label class="form-label">Medical Note</label>
                            <textarea name="medical_note" class="form-control" rows="2"><?= htmlspecialchars($student['medical_note'] ?? '') ?></textarea>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attendance -->
        <div id="att-card" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Sessions Attended</span>
                <div class="d-flex gap-2">
                    <?php if (has_role('instructor') && !has_role('admin') && !empty($attendance)): ?>
                    <button type="button" id="attEditBtn" class="btn btn-sm btn-outline-secondary">Edit</button>
                    <button type="submit" id="attConfirmBtn" form="att-form" class="btn btn-sm btn-success"
                            style="display:none">Confirm</button>
                    <?php endif; ?>
                </div>
            </div>
            <form id="att-form" method="post"
                  hx-post="student_profile.php?id=<?= $id ?>"
                  hx-target="#att-card" hx-swap="outerHTML" hx-select="#att-card">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_attendance">
                <div class="card-body p-0" style="max-height:320px;overflow-y:auto">
                    <?php if (empty($attendance)): ?>
                        <p class="p-3 text-muted">No classes recorded.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <tbody>
                        <?php foreach ($attendance as $a): ?>
                            <?php if (!$a['present']): continue; endif; ?>
                            <tr>
                                <td>
                                    <?php if (has_role('instructor', 'admin')): ?>
                                    <a href="attendance.php?date=<?= $a['session_date'] ?>"
                                       class="text-primary text-decoration-none">
                                        <?= date('D d M Y', strtotime($a['session_date'])) ?>
                                    </a>
                                    <?php else: // students can't open the take-attendance page ?>
                                    <?= date('D d M Y', strtotime($a['session_date'])) ?>
                                    <?php endif; ?>
                                </td>
                                <?php if (has_role('instructor') && !has_role('admin')): ?>
                                <td>
                                    <span class="att-edit" style="display:none">
                                        <input type="checkbox" class="form-check-input"
                                               name="att_present[]"
                                               value="<?= $a['session_id'] ?>"
                                               checked>
                                    </span>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

    </div>

    <!-- ── Right: Belt Tests + Rank History + Payments ── -->
    <div class="col-md-6 d-flex flex-column gap-4">

        <!-- Payments -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Payment History</div>
            <div class="card-body p-0" style="max-height:320px;overflow-y:auto">
                <?php if (empty($payments)): ?>
                    <p class="p-3 text-muted">No payments on record.</p>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                            <td><?= ucwords(str_replace('_', ' ', (string)$p['payment_type'])) ?></td>
                            <td><?= ucfirst($p['payment_method']) ?></td>
                            <td class="text-end">$<?= number_format($p['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rank History -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Rank History</div>
            <div class="card-body p-0" style="max-height:320px;overflow-y:auto">
                <?php if (empty($ranks)): ?>
                    <p class="p-3 text-muted">No ranks recorded.</p>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Rank</th><th>Date Achieved</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ranks as $i => $r): ?>
                        <tr class="<?= $i === 0 ? 'table-purple' : '' ?>">
                            <td><?= htmlspecialchars($r['kyu_dan']) ?></td>
                            <td><?= date('M Y', strtotime($r['achieved_date'])) ?></td>
                            <td>
                                <a href="../admin/certificate.php?student_id=<?= $id ?>&rank_id=<?= $r['rank_id'] ?>"
                                   target="_blank" class="btn btn-sm btn-outline-secondary py-0">Certificate</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Belt Tests -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Belt Test History</span>
            </div>
            <div class="card-body p-0" style="max-height:320px;overflow-y:auto">
                <?php if (empty($belt_tests)): ?>
                    <p class="p-3 text-muted">No belt tests on record.</p>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Testing For</th>
                            <th>Score</th>
                            <th class="text-center">Fee</th>
                            <th class="text-center">Awarded</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($belt_tests as $bt): ?>
                        <tr>
                            <td class="text-nowrap"><?= date('d M Y', strtotime($bt['test_date'])) ?></td>
                            <td>
                                <?php if (has_role('admin')): ?>
                                <a href="belt_test_edit.php?id=<?= $bt['id'] ?>&ref_pid=<?= $id ?>"
                                   class="text-primary text-decoration-none">
                                    <?= htmlspecialchars($bt['kyu_dan']) ?>
                                </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($bt['kyu_dan']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($bt['score']) && $bt['score'] !== null): ?>
                                    <?php if ($bt['result']==='pass'): ?>
                                        <span class="badge bg-success"><?= (int)$bt['score'] ?>%</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><?= (int)$bt['score'] ?>%</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?= $bt['fee_paid'] ? '<span class="text-success">✓</span>' : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td class="text-center">
                                <?php if ($bt['result'] === 'pass'): ?>
                                    <span class="badge bg-success">Passed</span>
                                <?php elseif ($bt['result'] === 'fail'): ?>
                                    <span class="text-danger">✗</span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Guardian / Family Links -->
        <?php
        $related = array_values(array_filter($family_tabs, fn($t) => $t['id'] !== $id));
        if (!empty($related)):
        ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Linked Family</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php foreach ($related as $rel): ?>
                        <tr>
                            <td>
                                <a href="student_profile.php?id=<?= $rel['id'] ?>">
                                    <?= hn($rel['name']) ?>
                                </a>
                                <?php if ($rel['role'] === 'parent'): ?>
                                    <span class="badge bg-info text-dark ms-2" style="font-size:.7rem">Parent</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ── Bottom: Notes (full width) ── -->
<?php if (has_role('admin')): ?>
<div id="notes-card" class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white fw-semibold">
        Student Notes
        <span class="text-muted fw-normal small ms-2">(<?= count($notes) ?>)</span>
    </div>
    <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
        <?php if (empty($notes)): ?>
            <p class="p-3 text-muted">No notes yet.</p>
        <?php else: ?>
        <?php foreach ($notes as $n): ?>
        <div class="border-bottom p-3">
            <small class="text-muted d-block mb-1">
                <?= date('d M Y g:i a', strtotime($n['created_at'])) ?>
                · <strong><?= htmlspecialchars($n['username'] ?? 'unknown') ?></strong>
            </small>
            <p class="mb-0 small"><?= nl2br(htmlspecialchars($n['content'])) ?></p>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php elseif (has_role('instructor')): ?>
<div id="notes-card" class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white fw-semibold">Add Note</div>
    <div class="card-body">
        <?php if (isset($_GET['noted']) || !empty($note_just_added)): ?>
        <div class="alert alert-success py-2 mb-3">Note saved.</div>
        <?php endif; ?>
        <?php if ($note_error): ?>
        <div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($note_error) ?></div>
        <?php endif; ?>
        <form method="post"
              hx-post="student_profile.php?id=<?= $id ?>"
              hx-target="#notes-card" hx-swap="outerHTML" hx-select="#notes-card">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_note">
            <textarea name="note_content" class="form-control form-control-sm mb-2"
                      rows="3" placeholder="Add a private note…" required></textarea>
            <button type="submit" class="btn btn-sm btn-primary">Save Note</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">
function cardToggle(cardId) {
    var btn    = document.getElementById(cardId + 'EditBtn');
    var cancel = document.getElementById(cardId + 'CancelBtn');
    var view   = document.getElementById(cardId + '-view');
    var edit   = document.getElementById(cardId + '-edit');
    if (btn.dataset.editing !== 'true') {
        btn.dataset.editing = 'true';
        btn.textContent = 'Save';
        btn.classList.replace('btn-success', 'btn-warning');
        if (cancel) cancel.style.display = '';
        if (view) view.style.display = 'none';
        if (edit) edit.style.display = '';
    } else {
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
    var form = document.getElementById(cardId + '-form');
    if (form) form.reset();
    if (typeof setFormClean === 'function') setFormClean();
}

// Delegated — #profile-card / #att-card get replaced wholesale by htmx on
// save, so bind from document to survive swaps.
document.addEventListener('click', function(e) {
    var btn;
    if ((btn = e.target.closest('.card-toggle-btn'))) {
        cardToggle(btn.dataset.cardId);
        return;
    }
    if ((btn = e.target.closest('.card-cancel-btn'))) {
        cardCancel(btn.dataset.cardId);
        return;
    }
    if ((btn = e.target.closest('#attEditBtn'))) {
        btn.style.display = 'none';
        var confirmBtn = document.getElementById('attConfirmBtn');
        if (confirmBtn) confirmBtn.style.display = '';
        document.querySelectorAll('.att-edit').forEach(function(el) { el.style.display = ''; });
        return;
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

