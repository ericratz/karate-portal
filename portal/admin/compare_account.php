<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$user_id         = (int)($_GET['user_id']         ?? 0);
$student_id      = (int)($_GET['student_id']      ?? 0);
$link_request_id = (int)($_GET['link_request_id'] ?? 0);

if (!$user_id) { header('Location: index.php'); exit; }

$msg = $error = '';

// ── Link accounts ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link') {
    verify_csrf();
    $uid  = (int)$_POST['user_id'];
    $sid  = (int)$_POST['student_id'];
    $lrid = (int)$_POST['link_request_id'];

    if ($uid && $sid) {
        db()->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$uid]);
        db()->prepare('UPDATE students SET user_id = ? WHERE id = ?')->execute([$uid, $sid]);

        $stype_stmt = db()->prepare('SELECT student_type FROM students WHERE id = ?');
        $stype_stmt->execute([$sid]);
        $stype = $stype_stmt->fetchColumn();
        $role  = in_array($stype, ['instructor','admin']) ? $stype
               : ($stype === 'parent' ? 'parent' : 'student');
        db()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role, $uid]);
        audit('link_account', 'user', $uid, "student_id=$sid");

        if ($lrid) {
            try {
                db()->prepare(
                    'UPDATE link_requests SET resolved=1, resolved_at=NOW(), resolved_by=? WHERE id=?'
                )->execute([current_user_id(), $lrid]);
            } catch (Exception $e) {}
        }

        header('Location: index.php?linked=1');
        exit;
    }
    $error = 'Invalid user or student selection.';
}

// ── Dismiss link request without linking ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss') {
    verify_csrf();
    $lrid = (int)$_POST['link_request_id'];
    if ($lrid) {
        try {
            db()->prepare(
                'UPDATE link_requests SET resolved=1, resolved_at=NOW(), resolved_by=? WHERE id=?'
            )->execute([current_user_id(), $lrid]);
        } catch (Exception $e) {}
    }
    header('Location: index.php');
    exit;
}

// ── Load user ─────────────────────────────────────────────────
$u_stmt = db()->prepare('SELECT u.*, s.date_of_birth AS student_dob, s.phone AS student_phone
                         FROM users u LEFT JOIN students s ON s.user_id = u.id
                         WHERE u.id = ?');
$u_stmt->execute([$user_id]);
$user = $u_stmt->fetch();
if (!$user) { header('Location: index.php'); exit; }

// Check if user already has a linked student
$existing_link_stmt = db()->prepare('SELECT id, first_name, last_name FROM students WHERE user_id = ?');
$existing_link_stmt->execute([$user_id]);
$existing_link = $existing_link_stmt->fetch();

// ── Load link request ─────────────────────────────────────────
$link_req = null;
if ($link_request_id) {
    try {
        $lr_stmt = db()->prepare('SELECT * FROM link_requests WHERE id = ? AND user_id = ?');
        $lr_stmt->execute([$link_request_id, $user_id]);
        $link_req = $lr_stmt->fetch() ?: null;
    } catch (Exception $e) {}
}

// ── Load selected student ─────────────────────────────────────
$student = null;
if ($student_id) {
    $s_stmt = db()->prepare(
        'SELECT s.*,
                (SELECT r.kyu_dan FROM student_ranks sr
                 JOIN ranks r ON r.id = sr.rank_id
                 WHERE sr.student_id = s.id
                 ORDER BY r.rank_order DESC LIMIT 1) AS current_rank,
                (SELECT MAX(cs.session_date)
                 FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id
                 WHERE a.student_id = s.id AND a.present = 1) AS last_attended
         FROM students s WHERE s.id = ?'
    );
    $s_stmt->execute([$student_id]);
    $student = $s_stmt->fetch() ?: null;
    if (!$student) $student_id = 0;
}

// ── All students for picker ───────────────────────────────────────────────────
$all_students = db()->query(
    'SELECT id, first_name, last_name, student_type
     FROM students
     ORDER BY first_name, last_name'
)->fetchAll();

// Type labels and badge colours
$type_labels = [
    'new_guest'        => 'New Student',
    'existing_student' => 'Existing Student',
    'parent'           => 'Parent',
];
$type_colours = [
    'new_guest'        => 'bg-success',
    'existing_student' => 'bg-primary',
    'parent'           => 'bg-info text-dark',
];

$page_title = 'Compare Account';
include __DIR__ . '/../includes/header.php';

// Helper: highlight cell if values differ
function cmp_class(string $a, string $b): string {
    return (strtolower(trim($a)) !== strtolower(trim($b))) ? 'table-warning' : '';
}
?>

<style>
    .bg-purple { background-color: #6f42c1 !important; }
    .compare-table td:first-child { width: 38%; color: #6c757d; font-size: .85rem; }
    .compare-table td:last-child  { font-weight: 500; }
</style>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Dashboard</a>
    <h4 class="mb-0">Compare &amp; Link Account</h4>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($link_req): ?>
<div class="alert alert-info d-flex justify-content-between align-items-start mb-4">
    <div>
        <span class="badge <?= $type_colours[$link_req['request_type']] ?? 'bg-secondary' ?> me-2">
            <?= $type_labels[$link_req['request_type']] ?? ucfirst($link_req['request_type']) ?>
        </span>
        <strong><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></strong>
        submitted a link request
        <span class="text-muted small">(<?= date('d M Y g:i a', strtotime($link_req['created_at'])) ?>)</span>
        <?php if ($link_req['notes']): ?>
            <div class="mt-1 small fst-italic">"<?= htmlspecialchars($link_req['notes']) ?>"</div>
        <?php endif; ?>
    </div>
    <form method="post" class="ms-3 flex-shrink-0">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="dismiss">
        <input type="hidden" name="link_request_id" value="<?= $link_request_id ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary"
                onclick="return confirm('Dismiss this request without linking?')">Dismiss</button>
    </form>
</div>
<?php endif; ?>

<!-- Student picker -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Select a Student Record to Compare</div>
    <div class="card-body py-3">
        <form method="get" action="compare_account.php" id="studentPickerForm" class="d-flex gap-2 align-items-end flex-wrap">
            <input type="hidden" name="user_id" value="<?= $user_id ?>">
            <?php if ($link_request_id): ?>
            <input type="hidden" name="link_request_id" value="<?= $link_request_id ?>">
            <?php endif; ?>
            <div>
                <label class="form-label small mb-1">Student Record</label>
                <select name="student_id" id="studentPicker" class="form-select form-select-sm" style="min-width:260px">
                    <option value="">— pick a student —</option>
                    <?php foreach ($all_students as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $s['id'] === $student_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>
                        (<?= $s['student_type'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="student_edit.php?prefill_first=<?= urlencode($user['first_name']) ?>&prefill_last=<?= urlencode($user['last_name']) ?>&prefill_email=<?= urlencode($user['email'] ?? '') ?>"
               class="btn btn-outline-secondary btn-sm">+ Create New Student Record</a>
        </form>
    </div>
</div>

<!-- Side-by-side comparison -->
<div class="row g-4 mb-4">

    <!-- Login account -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Login Account</span>
                <a href="user_profile.php?id=<?= $user_id ?>" class="btn btn-sm btn-outline-secondary">View</a>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0 compare-table">
                    <tbody>
                        <tr class="<?= $student ? cmp_class($user['first_name'] ?? '', $student['first_name']) : '' ?>">
                            <td>First Name</td>
                            <td><?= htmlspecialchars($user['first_name'] ?? '—') ?></td>
                        </tr>
                        <tr class="<?= $student ? cmp_class($user['last_name'] ?? '', $student['last_name']) : '' ?>">
                            <td>Last Name</td>
                            <td><?= htmlspecialchars($user['last_name'] ?? '—') ?></td>
                        </tr>
                        <tr class="<?= $student ? cmp_class($user['date_of_birth'] ?? '', $student['date_of_birth'] ?? '') : '' ?>">
                            <td>Date of Birth</td>
                            <td><?= !empty($user['date_of_birth']) ? date('d M Y', strtotime($user['date_of_birth'])) : '—' ?></td>
                        </tr>
                        <tr class="<?= $student ? cmp_class($user['email'] ?? '', $student['email'] ?? '') : '' ?>">
                            <td>Email</td>
                            <td><?= htmlspecialchars($user['email'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td>Username</td>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                        </tr>
                        <tr>
                            <td>Role</td>
                            <td><?= htmlspecialchars($user['role']) ?></td>
                        </tr>
                        <tr>
                            <td>Status</td>
                            <td><?= $user['active']
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-danger">Deactivated</span>' ?></td>
                        </tr>
                        <tr>
                            <td>Currently Linked To</td>
                            <td>
                                <?php if ($existing_link): ?>
                                    <a href="../instructor/student_profile.php?id=<?= $existing_link['id'] ?>">
                                        <?= htmlspecialchars($existing_link['first_name'].' '.$existing_link['last_name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Not linked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Student record -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Student Record</span>
                <?php if ($student): ?>
                <a href="../instructor/student_profile.php?id=<?= $student['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (!$student): ?>
                    <p class="p-3 text-muted mb-0">Select a student record above to compare.</p>
                <?php else: ?>
                <table class="table table-sm mb-0 compare-table">
                    <tbody>
                        <tr class="<?= cmp_class($user['first_name'] ?? '', $student['first_name']) ?>">
                            <td>First Name</td>
                            <td><?= htmlspecialchars($student['first_name']) ?></td>
                        </tr>
                        <tr class="<?= cmp_class($user['last_name'] ?? '', $student['last_name']) ?>">
                            <td>Last Name</td>
                            <td><?= htmlspecialchars($student['last_name']) ?></td>
                        </tr>
                        <tr class="<?= cmp_class($user['date_of_birth'] ?? '', $student['date_of_birth'] ?? '') ?>">
                            <td>Date of Birth</td>
                            <td><?= !empty($student['date_of_birth']) ? date('d M Y', strtotime($student['date_of_birth'])) : '—' ?></td>
                        </tr>
                        <tr class="<?= cmp_class($user['email'] ?? '', $student['email'] ?? '') ?>">
                            <td>Email</td>
                            <td><?= htmlspecialchars($student['email'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td>Type</td>
                            <td><?= htmlspecialchars($student['student_type']) ?></td>
                        </tr>
                        <tr>
                            <td>Current Rank</td>
                            <td><?= htmlspecialchars($student['current_rank'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td>Last Attended</td>
                            <td><?= $student['last_attended']
                                ? date('d M Y', strtotime($student['last_attended']))
                                : '<span class="text-muted">Never</span>' ?></td>
                        </tr>
                        <tr>
                            <td>Registered</td>
                            <td><?= $student['registration_date']
                                ? date('d M Y', strtotime($student['registration_date']))
                                : '—' ?></td>
                        </tr>
                        <tr>
                            <td>Waiver</td>
                            <td><?= $student['injury_waiver']
                                ? '<span class="text-success">✓ Signed</span>'
                                : '<span class="text-danger">✗ Not signed</span>' ?></td>
                        </tr>
                        <tr>
                            <td>Currently Linked To</td>
                            <td>
                                <?php
                                $sl_stmt = db()->prepare('SELECT id, username FROM users WHERE id = (SELECT user_id FROM students WHERE id = ?)');
                                $sl_stmt->execute([$student['id']]);
                                $sl = $sl_stmt->fetch();
                                ?>
                                <?php if ($sl): ?>
                                    <a href="user_profile.php?id=<?= $sl['id'] ?>">
                                        <?= htmlspecialchars($sl['username']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Not linked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php if ($student): ?>
<div class="d-flex gap-3 align-items-center">
    <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action"           value="link">
        <input type="hidden" name="user_id"          value="<?= $user_id ?>">
        <input type="hidden" name="student_id"       value="<?= $student_id ?>">
        <input type="hidden" name="link_request_id"  value="<?= $link_request_id ?>">
        <button type="submit" class="btn btn-primary px-4"
                onclick="return confirm('Link <?= htmlspecialchars(addslashes($user['username'])) ?> to <?= htmlspecialchars(addslashes($student['first_name'].' '.$student['last_name'])) ?>?')">
            Link These Accounts
        </button>
    </form>
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
    <?php if ($existing_link || ($sl ?? null)): ?>
        <span class="text-warning small">
            ⚠ One or both sides are already linked — linking here will replace the existing link.
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
document.getElementById('studentPicker').addEventListener('change', function() {
    if (!this.value) return;
    window.setFormClean();
    var params = new URLSearchParams();
    params.set('user_id', '<?= $user_id ?>');
    <?php if ($link_request_id): ?>params.set('link_request_id', '<?= $link_request_id ?>');<?php endif; ?>
    params.set('student_id', this.value);
    window.location.href = 'compare_account.php?' + params.toString();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

