<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

$user_id  = current_user_id();
$msg      = $error = '';
$pw_msg   = $pw_error = '';

// Load student record
$student = db()->prepare('SELECT * FROM students WHERE user_id = ?');
$student->execute([$user_id]);
$student = $student->fetch();

// ── Change password ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password']     ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $user = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $user->execute([$user_id]);
    $user = $user->fetch();

    if (!password_verify($current, $user['password_hash'])) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['change_password'])) {
    $first    = trim($_POST['first_name']  ?? '');
    $last     = trim($_POST['last_name']   ?? '');
    $dob      = $_POST['date_of_birth']    ?? '';
    $phone    = trim($_POST['phone']       ?? '');
    $email    = trim($_POST['email']       ?? '');
    $ec_name  = trim($_POST['ec_name']     ?? '');
    $ec_phone = trim($_POST['ec_phone']    ?? '');

    $email = trim($_POST['email'] ?? '');
    if (!$first || !$last || !$dob || !$email) {
        $error = 'First name, last name, date of birth, and email are required.';
    } else {
        if ($student) {
            db()->prepare(
                'UPDATE students SET
                    first_name=?, last_name=?, date_of_birth=?,
                    phone=?, email=?,
                    emergency_contact_name=?, emergency_contact_phone=?
                 WHERE user_id=?'
            )->execute([$first,$last,$dob,$phone,$email,$ec_name,$ec_phone,$user_id]);
        } else {
            db()->prepare(
                'INSERT INTO students
                 (user_id,first_name,last_name,date_of_birth,phone,email,
                  emergency_contact_name,emergency_contact_phone,
                  registration_date,student_type,active)
                 VALUES (?,?,?,?,?,?,?,?,CURDATE(),"guest",1)'
            )->execute([$user_id,$first,$last,$dob,$phone,$email,$ec_name,$ec_phone]);
        }
        // Also update users table name
        db()->prepare('UPDATE users SET first_name=?, last_name=?, email=? WHERE id=?')
             ->execute([$first, $last, $email ?: null, $user_id]);

        $msg = 'Profile saved.';
        // Reload
        $student = db()->prepare('SELECT * FROM students WHERE user_id = ?');
        $student->execute([$user_id]);
        $student = $student->fetch();
    }
} // end profile POST

// Auto-pay status
$sub_stmt = db()->prepare(
    "SELECT paypal_subscription_id FROM subscriptions WHERE student_id=? AND status='active' LIMIT 1"
);
$sub_stmt->execute([$student['id'] ?? 0]);
$active_subscription = $student ? $sub_stmt->fetchColumn() : false;

$autopay_key = $_GET['autopay'] ?? '';
switch ($autopay_key) {
    case 'cancelled': $autopay_msg = ['type' => 'success', 'text' => 'Monthly auto-pay has been cancelled.']; break;
    default:          $autopay_msg = null;
}

$page_title = 'My Profile';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">My Profile</h4>
</div>

<?php if ($autopay_msg): ?><div class="alert alert-<?= $autopay_msg['type'] ?>"><?= $autopay_msg['text'] ?></div><?php endif; ?>
<?php if ($msg):      ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error):    ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($pw_msg):   ?><div class="alert alert-success"><?= htmlspecialchars($pw_msg) ?></div><?php endif; ?>
<?php if ($pw_error): ?><div class="alert alert-danger"><?= htmlspecialchars($pw_error) ?></div><?php endif; ?>

<div class="row g-4">
<div class="col-md-7">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Profile</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_input() ?>
            <div class="col-6">
                <label class="form-label">First Name *</label>
                <input type="text" name="first_name" class="form-control" required
                       value="<?= htmlspecialchars($student['first_name'] ?? $_SESSION['username'] ?? '') ?>">
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
                <label class="form-label">Email *</label>
                <input type="email" name="email" class="form-control" required
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
                <button class="btn btn-success">Save Profile</button>
            </div>
        </form>
    </div>
</div>
</div><!-- /col-md-7 -->

<!-- Change Password + Contact -->
<div class="col-md-5 d-flex flex-column gap-4">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Change Password</span>
        <button class="btn btn-sm btn-danger"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#changePasswordForm">
            Change Password
        </button>
    </div>
    <div class="collapse <?= $pw_error || $pw_msg ? 'show' : '' ?>" id="changePasswordForm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_input() ?>
            <input type="hidden" name="change_password" value="1">
            <div class="col-12">
                <label class="form-label">Current Password *</label>
                <input type="password" name="current_password" class="form-control" required>
            </div>
            <div class="col-6">
                <label class="form-label">New Password *</label>
                <input type="password" name="new_password" class="form-control"
                       required minlength="8" placeholder="Min. 8 characters">
            </div>
            <div class="col-6">
                <label class="form-label">Confirm New Password *</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <div class="col-12">
                <button class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>
    </div><!-- /collapse -->
</div>


<!-- Auto-Pay -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Monthly Auto-Pay</div>
    <div class="card-body">
        <?php if ($active_subscription): ?>
            <p class="text-success fw-semibold mb-2">✓ Active</p>
            <p class="text-muted small mb-3">
                PayPal automatically charges $<?= number_format(MONTHLY_FEE, 2) ?> each month.
            </p>
            <form method="post" action="subscription_cancel.php"
                  onsubmit="return confirm('Cancel your monthly auto-pay? You will need to pay manually each month.')">
                <?= csrf_input() ?>
                <button type="submit" class="btn btn-sm btn-danger">Cancel Auto-Pay</button>
            </form>
        <?php else: ?>
            <p class="text-muted small mb-3">
                No auto-pay set up. <a href="pay.php">Set up monthly auto-pay</a> to have PayPal charge $<?= number_format(MONTHLY_FEE, 2) ?> automatically each month.
            </p>
        <?php endif; ?>
    </div>
</div>

</div><!-- /col-md-5 -->
</div><!-- /row -->

<?php include __DIR__ . '/../includes/footer.php'; ?>
