<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('student', 'instructor', 'admin');

$id = (int)($_GET['id'] ?? 0);
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_note') {
    verify_csrf();
    $content = trim($_POST['note_content'] ?? '');
    if ($id && $content !== '') {
        db()->prepare('INSERT INTO student_notes (student_id, content, created_by) VALUES (?,?,?)')
             ->execute([$id, $content, current_user_id()]);
        audit('add_note', 'student', $id);
    }
    header("Location: student_profile.php?id=$id&noted=1");
    exit;
}

// Instructor/Admin: bulk update attendance from checkboxes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_attendance') {
    verify_csrf();
    if (!has_role('instructor', 'admin')) { header("Location: student_profile.php?id=$id"); exit; }
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
    header("Location: student_profile.php?id=$id");
    exit;
}

// Instructor/Admin: toggle attendance present/absent (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_attendance') {
    verify_csrf();
    $session_id = (int)$_POST['session_id'];
    if ($id && $session_id) {
        $cur = db()->prepare('SELECT present FROM attendance WHERE student_id=? AND session_id=?');
        $cur->execute([$id, $session_id]);
        $row = $cur->fetch();
        $new_present = $row ? ($row['present'] ? 0 : 1) : 1;
        db()->prepare(
            'INSERT INTO attendance (student_id, session_id, present, recorded_by)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE present=VALUES(present), recorded_by=VALUES(recorded_by)'
        )->execute([$id, $session_id, $new_present, current_user_id()]);
        audit('toggle_attendance', 'student', $id, "session $session_id present=$new_present");
        header('Content-Type: application/json');
        echo json_encode(['present' => $new_present]);
        exit;
    }
    http_response_code(400);
    exit;
}


$student = db()->prepare(
    'SELECT s.*, u.username, u.email AS login_email, u.last_login, u.role
     FROM students s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?'
);
$student->execute([$id]);
$student = $student->fetch();
if (!$student) { header('Location: index.php'); exit; }

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

// Payment history
$payments = db()->prepare(
    'SELECT payment_date, payment_type, payment_method, amount, transaction_id, notes
     FROM payments WHERE student_id = ? ORDER BY payment_date DESC'
);
$payments->execute([$id]);
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

if ($student['student_type'] === 'parent' && $student['user_id']) {
    // Viewing a parent — load their linked children
    $ch_stmt = db()->prepare(
        'SELECT s.id, s.first_name, s.last_name
         FROM parent_students ps
         JOIN students s ON s.id = ps.student_id
         WHERE ps.parent_user_id = ?
         ORDER BY s.first_name, s.last_name'
    );
    $ch_stmt->execute([$student['user_id']]);
    $children = $ch_stmt->fetchAll();

    if (!empty($children)) {
        $family_tabs[] = [
            'id'   => $id,
            'name' => $student['first_name'] . ' ' . $student['last_name'],
            'role' => 'parent',
        ];
        foreach ($children as $ch) {
            $family_tabs[] = [
                'id'   => $ch['id'],
                'name' => $ch['first_name'] . ' ' . $ch['last_name'],
                'role' => 'child',
            ];
        }
    }
} else {
    // Viewing a child — check if they belong to a parent
    $par_stmt = db()->prepare(
        'SELECT u.id AS parent_user_id, sp.id AS parent_sid,
                sp.first_name AS par_first, sp.last_name AS par_last
         FROM parent_students ps
         JOIN users u ON u.id = ps.parent_user_id
         JOIN students sp ON sp.user_id = u.id
         WHERE ps.student_id = ?
         LIMIT 1'
    );
    $par_stmt->execute([$id]);
    $par_row = $par_stmt->fetch();

    if ($par_row) {
        // Load all siblings under this parent
        $sib_stmt = db()->prepare(
            'SELECT s.id, s.first_name, s.last_name
             FROM parent_students ps
             JOIN students s ON s.id = ps.student_id
             WHERE ps.parent_user_id = ?
             ORDER BY s.first_name, s.last_name'
        );
        $sib_stmt->execute([$par_row['parent_user_id']]);
        $siblings = $sib_stmt->fetchAll();

        $family_tabs[] = [
            'id'   => $par_row['parent_sid'],
            'name' => $par_row['par_first'] . ' ' . $par_row['par_last'],
            'role' => 'parent',
        ];
        foreach ($siblings as $sib) {
            $family_tabs[] = [
                'id'   => $sib['id'],
                'name' => $sib['first_name'] . ' ' . $sib['last_name'],
                'role' => 'child',
            ];
        }
    }
}

$page_title = $student['first_name'] . ' ' . $student['last_name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-3">
    <h4 class="mb-0">
        <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
        <?php if (!$student['active']): ?>
            <span class="badge bg-secondary ms-2">Inactive</span>
        <?php endif; ?>
    </h4>
    <?php if (has_role('admin')): ?>
    <div class="ms-auto d-flex gap-2">
        <a href="../admin/student_edit.php?id=<?= $id ?>&ref=profile"
           class="btn btn-sm btn-success">Edit Profile</a>
    </div>
    <?php elseif (has_role('student') && !has_role('instructor')): ?>
    <div class="ms-auto">
        <a href="../student/profile_edit.php"
           class="btn btn-sm btn-success">Edit Profile</a>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($family_tabs)): ?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($family_tabs as $tab): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab['id'] === $id ? 'active' : '' ?>"
           href="student_profile.php?id=<?= $tab['id'] ?>">
            <?= htmlspecialchars($tab['name']) ?>
            <?php if ($tab['role'] === 'parent'): ?>
                <span class="badge bg-secondary ms-1" style="font-size:.6rem;vertical-align:middle">Parent</span>
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
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Profile Info</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="small text-muted mb-1">Date of Birth</div>
                        <div><?= $student['date_of_birth'] ? date('M j, Y', strtotime($student['date_of_birth'])) : '—' ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted mb-1">Phone</div>
                        <div><?= htmlspecialchars($student['phone'] ?? '—') ?></div>
                    </div>
                    <div class="col-12">
                        <div class="small text-muted mb-1">Email</div>
                        <div><?= htmlspecialchars($student['email'] ?? '—') ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted mb-1">Emergency Contact</div>
                        <div><?= htmlspecialchars($student['emergency_contact_name'] ?? '—') ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted mb-1">Emergency Phone</div>
                        <div><?= htmlspecialchars($student['emergency_contact_phone'] ?? '—') ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted mb-1">Member Since</div>
                        <div><?= date('M j, Y', strtotime($student['registration_date'])) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted mb-1">Account Type</div>
                        <div>
                            <?php
                            $acct_tips = [
                                'student'    => 'Paying participant ($30/month tuition)',
                                'guest'      => 'Non-paying participant (registration fee not yet paid)',
                                'parent'     => 'Family account — one tuition payment covers the whole family',
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
                            echo $type_badges[$student['student_type']] ?? '<span class="badge bg-secondary">Guest</span>';
                            $tip = $acct_tips[$student['student_type']] ?? '';
                            if ($tip): ?>
                            <span class="text-muted small ms-1" data-bs-toggle="tooltip" title="<?= htmlspecialchars($tip) ?>">ⓘ</span>
                        <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted mb-1">Liability Waiver</div>
                        <div>
                            <?php if ($student['injury_waiver']): ?>
                                <span class="text-success">✓ <?= $student['injury_waiver_date'] ? date('M j, Y', strtotime($student['injury_waiver_date'])) : '' ?></span>
                            <?php else: ?>
                                <span class="text-danger">✗ Not signed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted mb-1">Account</div>
                        <div>
                            <?php if ($student['username']): ?>
                                <?= htmlspecialchars($student['username']) ?>
                            <?php else: ?>
                                <span class="text-muted">No login</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted mb-1">Last Login</div>
                        <div><?= $student['last_login'] ? date('M j, Y', strtotime($student['last_login'])) : '—' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attendance -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>
                    Recent Attendance
                </span>
                <?php if (has_role('instructor') && !has_role('admin') && !empty($attendance)): ?>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="attEditBtn"
                            onclick="toggleAttEdit()">Edit</button>
                    <button type="submit" form="att-form" class="btn btn-sm btn-success" id="attConfirmBtn"
                            style="display:none">Confirm</button>
                </div>
                <?php endif; ?>
            </div>
            <form id="att-form" method="post">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_attendance">
                <div class="card-body p-0" style="max-height:320px;overflow-y:auto">
                    <?php if (empty($attendance)): ?>
                        <p class="p-3 text-muted">No classes recorded.</p>
                    <?php else: ?>
                    <table class="table table-sm table-hover mb-0">
                        <tbody>
                        <?php foreach ($attendance as $a): ?>
                            <?php if (!$a['present'] && !has_role('instructor', 'admin')): continue; endif; ?>
                            <tr>
                                <td><?= date('D, M j, Y', strtotime($a['session_date'])) ?></td>
                                <?php if (has_role('instructor', 'admin')): ?>
                                <td>
                                    <?php if ($a['present']): ?>
                                        <span class="badge bg-success">Present</span>
                                    <?php endif; ?>
                                    <?php if (has_role('instructor') && !has_role('admin')): ?>
                                    <span class="att-edit ms-2" style="display:none">
                                        <input type="checkbox" class="form-check-input"
                                               name="att_present[]"
                                               value="<?= $a['session_id'] ?>"
                                               <?= $a['present'] ? 'checked' : '' ?>>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
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
            <div class="card-body p-0">
                <?php if (empty($payments)): ?>
                    <p class="p-3 text-muted">No payments on record.</p>
                <?php else: ?>
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
                            <td><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
                            <td><?= ucwords(str_replace('_', ' ', $p['payment_type'])) ?></td>
                            <td><?= ucfirst($p['payment_method']) ?></td>
                            <td class="text-end">$<?= number_format($p['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rank History -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Rank History</div>
            <div class="card-body p-0">
                <?php if (empty($ranks)): ?>
                    <p class="p-3 text-muted">No ranks recorded.</p>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Rank</th><th>Date Achieved</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ranks as $i => $r): ?>
                        <tr class="<?= $i === 0 ? 'table-purple' : '' ?>">
                            <td><?= htmlspecialchars($r['kyu_dan']) ?></td>
                            <td><?= date('M Y', strtotime($r['achieved_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Belt Tests -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Belt Test History</div>
            <div class="card-body p-0">
                <?php if (empty($belt_tests)): ?>
                    <p class="p-3 text-muted">No belt tests on record.</p>
                <?php else: ?>
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
                            <td class="text-nowrap"><?= date('M j, Y', strtotime($bt['test_date'])) ?></td>
                            <td><?= htmlspecialchars($bt['kyu_dan']) ?></td>
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
                                <?= $bt['belt_awarded'] ? '<span class="badge bg-success">Yes</span>' : '<span class="text-muted">—</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- ── Bottom: Notes (full width) ── -->
<?php if (has_role('admin')): ?>
<div class="card border-0 shadow-sm mt-4">
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
                <?= date('M j, Y g:i a', strtotime($n['created_at'])) ?>
                · <strong><?= htmlspecialchars($n['username'] ?? 'unknown') ?></strong>
            </small>
            <p class="mb-0 small"><?= nl2br(htmlspecialchars($n['content'])) ?></p>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php elseif (has_role('instructor')): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white fw-semibold">Add Note</div>
    <div class="card-body">
        <?php if (isset($_GET['noted'])): ?>
        <div class="alert alert-success py-2 mb-3">Note saved.</div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_note">
            <textarea name="note_content" class="form-control form-control-sm mb-2"
                      rows="3" placeholder="Add a private note…" required></textarea>
            <button type="submit" class="btn btn-sm btn-primary">Save Note</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
    new bootstrap.Tooltip(el);
});
</script>

<?php if (has_role('instructor', 'admin')): ?>
<script>
function toggleAttEdit() {
    const btn     = document.getElementById('attEditBtn');
    const confirm = document.getElementById('attConfirmBtn');
    const on      = btn.dataset.editing === '1';
    document.querySelectorAll('.att-edit').forEach(el => el.style.display = on ? 'none' : '');
    btn.dataset.editing  = on ? '0' : '1';
    btn.style.display    = on ? '' : 'none';
    confirm.style.display = on ? 'none' : '';
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
