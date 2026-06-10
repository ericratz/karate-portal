<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('parent');

$user_id = current_user_id();

// Build the list of student IDs this parent is allowed to edit
$allowed_ids = [];

$own_stmt = db()->prepare('SELECT id FROM students WHERE user_id = ?');
$own_stmt->execute([$user_id]);
$own_student_id = null;
if ($own_row = $own_stmt->fetch()) {
    $own_student_id = (int)$own_row['id'];
    $allowed_ids[]  = $own_student_id;
}

$ch_stmt = db()->prepare(
    'SELECT s.id FROM parent_students ps
     JOIN students s ON s.id = ps.student_id
     WHERE ps.parent_user_id = ?'
);
$ch_stmt->execute([$user_id]);
foreach ($ch_stmt->fetchAll() as $r) {
    $allowed_ids[] = (int)$r['id'];
}

// Accept student_id from GET or POST
$student_id = (int)($_POST['student_id'] ?? $_GET['student_id'] ?? 0);
if (!$student_id || !in_array($student_id, $allowed_ids, true)) {
    header('Location: index.php');
    exit;
}

$msg = $error = '';
$pw_msg = $pw_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

// ── Change password (for the parent's own login account) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $urow = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $urow->execute([$user_id]);
    $urow = $urow->fetch();

    if (!password_verify($current, $urow['password_hash'])) {
        $pw_error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $pw_error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $pw_error = 'New passwords do not match.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
             ->execute([password_hash($new, PASSWORD_BCRYPT), $user_id]);
        $pw_msg = 'Password updated successfully.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // Already handled above — skip profile save block below
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $first        = trim($_POST['first_name']   ?? '');
    $last         = trim($_POST['last_name']    ?? '');
    $dob          = $_POST['date_of_birth']     ?? '';
    $phone        = trim($_POST['phone']        ?? '');
    $email        = trim($_POST['email']        ?? '');
    $ec_name      = trim($_POST['ec_name']      ?? '');
    $ec_phone     = trim($_POST['ec_phone']     ?? '');
    $medical_note = trim($_POST['medical_note'] ?? '');

    if (!$first || !$last || !$dob) {
        $error = 'First name, last name, and date of birth are required.';
    } else {
        db()->prepare(
            'UPDATE students SET
                first_name=?, last_name=?, date_of_birth=?,
                phone=?, email=?,
                emergency_contact_name=?, emergency_contact_phone=?,
                medical_note=?
             WHERE id=?'
        )->execute([$first, $last, $dob, $phone, $email, $ec_name, $ec_phone, $medical_note ?: null, $student_id]);

        // Keep linked user record in sync
        $lu_stmt = db()->prepare('SELECT user_id FROM students WHERE id=?');
        $lu_stmt->execute([$student_id]);
        if ($linked_uid = $lu_stmt->fetchColumn()) {
            db()->prepare('UPDATE users SET first_name=?, last_name=?, email=? WHERE id=?')
                 ->execute([$first, $last, $email ?: null, $linked_uid]);
        }

        audit('update_student', 'student', $student_id, "by_parent_user_id=$user_id");
        $msg = 'Profile saved.';
    }
}

// Load student record
$student = db()->prepare('SELECT * FROM students WHERE id=?');
$student->execute([$student_id]);
$student = $student->fetch();
if (!$student) { header('Location: index.php'); exit; }

$page_title = 'Edit Profile — ' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">Edit Profile — <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h4>
</div>

<?php if ($msg):       ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error):     ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($pw_msg):    ?><div class="alert alert-success"><?= htmlspecialchars($pw_msg) ?></div><?php endif; ?>
<?php if ($pw_error):  ?><div class="alert alert-danger"><?= htmlspecialchars($pw_error) ?></div><?php endif; ?>

<div class="row g-4">
<div class="col-md-7">

<!-- Profile card -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Profile</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_input() ?>
            <input type="hidden" name="student_id" value="<?= $student_id ?>">

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
                <label class="form-label">Date of Birth *</label>
                <input type="date" name="date_of_birth" class="form-control" required
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
                <label class="form-label">Medical Note</label>
                <textarea name="medical_note" class="form-control" rows="2"
                          placeholder="Allergies, conditions, medications, etc."><?= htmlspecialchars($student['medical_note'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success">Save Profile</button>
                <a href="index.php?student_id=<?= $student_id ?>" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

</div><!-- /col-md-7 -->

<div class="col-md-5">

<?php if ($student_id === $own_student_id): ?>
<!-- Change Password card (applies to the parent's own login) -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Change Password</span>
        <button class="btn btn-sm btn-danger" type="button"
                data-bs-toggle="collapse" data-bs-target="#changePasswordForm">
            Change Password
        </button>
    </div>
    <div class="collapse <?= $pw_error || $pw_msg ? 'show' : '' ?>" id="changePasswordForm">
        <div class="card-body">
            <p class="text-muted small mb-3">This changes your own login password.</p>
            <form method="post" class="row g-3">
                <?= csrf_input() ?>
                <input type="hidden" name="student_id" value="<?= $student_id ?>">
                <input type="hidden" name="change_password" value="1">
                <div class="col-12">
                    <label class="form-label">Current Password *</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="col-12">
                    <label class="form-label">New Password *</label>
                    <input type="password" name="new_password" class="form-control"
                           required minlength="8" placeholder="Min. 8 characters">
                </div>
                <div class="col-12">
                    <label class="form-label">Confirm New Password *</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

</div><!-- /col-md-5 -->
</div><!-- /row -->

<?php include __DIR__ . '/../includes/footer.php'; ?>
