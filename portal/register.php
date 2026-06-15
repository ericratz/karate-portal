<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: /karate/portal/student/');
    exit;
}

$error  = '';
$step   = 'form'; // form | notify | done
$action = ($_POST['action'] ?? '');

// ── Notify Noji ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'notify') {
    verify_csrf();
    $uid   = (int)($_SESSION['notify_user_id'] ?? 0);
    $type  = $_POST['request_type'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    if ($uid && in_array($type, ['new_guest','existing_student','parent'])) {
        try {
            db()->prepare(
                'INSERT INTO link_requests (user_id, request_type, notes) VALUES (?,?,?)'
            )->execute([$uid, $type, $notes ?: null]);
        } catch (Exception $e) {}
    }
    unset($_SESSION['notify_user_id']);
    $step = 'done';

// ── Skip ──────────────────────────────────────────────────────
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'skip') {
    verify_csrf();
    unset($_SESSION['notify_user_id']);
    $step = 'done';

// ── Register ──────────────────────────────────────────────────
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first    = trim($_POST['first_name'] ?? '');
    $last     = trim($_POST['last_name']  ?? '');
    $dob      = $_POST['date_of_birth']   ?? '';
    $email    = trim($_POST['email']      ?? '');
    $username = trim($_POST['username']   ?? '');
    $password = $_POST['password']        ?? '';
    $confirm  = $_POST['confirm']         ?? '';

    if (!$first || !$last || !$dob || !$email || !$username || !$password) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $check = db()->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            $error = 'That username is already taken.';
        } else {
            db()->prepare(
                'INSERT INTO users (username, password_hash, role, email, first_name, last_name)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $username,
                password_hash($password, PASSWORD_BCRYPT),
                'student', $email, $first, $last,
            ]);
            $_SESSION['notify_user_id'] = (int)db()->lastInsertId();
            $step = 'notify';
        }
    }
}

// Restore notify step on back-button / refresh
if ($step === 'form' && !empty($_SESSION['notify_user_id'])) {
    $step = 'notify';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register — <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #f0f0f0; }
        .register-card { max-width: 480px; margin: 60px auto; }
        .register-card .card-header {
            background: #6f42c1; color: #fff;
            text-align: center; padding: 1.25rem;
        }
        .register-card .card-header h4 { margin: 0; font-weight: 600; }
        .option-card {
            cursor: pointer;
            border: 2px solid #dee2e6 !important;
            border-radius: .375rem;
            transition: border-color .15s, background-color .15s;
            user-select: none;
        }
        .option-card:hover  { border-color: #6f42c1 !important; background: #faf7ff; }
        .option-card.selected {
            border-color: #6f42c1 !important;
            background-color: #f3eeff !important;
        }
    </style>
</head>
<body>

<div class="register-card">
    <div class="card shadow">
        <div class="card-header">
            <h4>Shotokan Karate</h4>
            <small>
                <?php
                if ($step === 'notify') echo 'One more thing&hellip;';
                elseif ($step === 'done') echo 'You&rsquo;re all set!';
                else echo 'Create an Account';
                ?>
            </small>
        </div>
        <div class="card-body p-4">

        <?php if ($step === 'done'): ?>

            <div class="alert alert-success mb-3">
                Your account has been created. Noji has been notified and will link your
                account to the right records shortly.
            </div>
            <a href="/karate/portal/login.php" class="btn btn-primary w-100">Go to Login</a>

        <?php elseif ($step === 'notify'): ?>

            <p class="text-muted small mb-3">
                Your account has been created. Help Noji understand who you are so your
                account can be linked to the right records.
            </p>

            <form method="post" id="notifyForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="notify">
                <input type="hidden" name="request_type" id="requestType" value="">

                <div class="d-flex flex-column gap-2 mb-3">

                    <div class="option-card p-3 d-flex align-items-start gap-3"
                         onclick="selectType('new_guest', this)">
                        <span class="fs-3 lh-1 mt-1">🥋</span>
                        <div>
                            <div class="fw-semibold">I'm a new student</div>
                            <div class="text-muted small">Signing up for the first time — I don't have any existing records here</div>
                        </div>
                    </div>

                    <div class="option-card p-3 d-flex align-items-start gap-3"
                         onclick="selectType('existing_student', this)">
                        <span class="fs-3 lh-1 mt-1">📋</span>
                        <div>
                            <div class="fw-semibold">I've trained here before</div>
                            <div class="text-muted small">Noji has records of me and I need this account linked to them</div>
                        </div>
                    </div>

                    <div class="option-card p-3 d-flex align-items-start gap-3"
                         onclick="selectType('parent', this)">
                        <span class="fs-3 lh-1 mt-1">👨‍👩‍👧</span>
                        <div>
                            <div class="fw-semibold">I'm a parent</div>
                            <div class="text-muted small">My child or children are the ones attending classes</div>
                        </div>
                    </div>

                </div>

                <div id="notesWrap" class="mb-3" style="display:none">
                    <label class="form-label small mb-1" id="notesLabel">Additional details (optional)</label>
                    <textarea name="notes" id="notesField" class="form-control form-control-sm" rows="2"
                              placeholder=""></textarea>
                </div>

                <button type="submit" class="btn btn-primary w-100" id="notifyBtn" disabled>
                    Notify Noji
                </button>
            </form>

            <form method="post" class="text-center mt-2">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="skip">
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">
                    Skip — I'll sort this out later
                </button>
            </form>

        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= csrf_input() ?>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-control" required
                               value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" required
                               value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Date of Birth *</label>
                        <input type="date" name="date_of_birth" class="form-control" required
                               value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>">
                    </div>
                    <div class="col-6"></div>
                    <div class="col-12">
                        <label class="form-label">Email Address *</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" class="form-control" required
                               autocomplete="off"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Password *</label>
                        <input type="password" name="password" class="form-control"
                               required minlength="8">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Confirm *</label>
                        <input type="password" name="confirm" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary w-100">Create Account</button>
                    </div>
                </div>
            </form>

        <?php endif; ?>

        </div>
        <div class="card-footer text-center text-muted small py-2">
            <?php if ($step === 'form'): ?>
                Already have an account? <a href="/karate/portal/login.php">Log in</a>
            <?php else: ?>
                &nbsp;
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($step === 'notify'): ?>
<script>
var NOTES_LABELS = {
    new_guest:         'Anything to add? (optional)',
    existing_student:  'What name would Noji know you by? (optional)',
    parent:            "Your child's name and class (optional)"
};
var NOTES_PLACEHOLDERS = {
    new_guest:         'e.g. I came to a trial class last week…',
    existing_student:  'e.g. I trained here around 2022, I may be listed under a different name…',
    parent:            'e.g. My daughter Sarah attends the Tuesday 6 pm class…'
};

function selectType(type, el) {
    document.querySelectorAll('.option-card').forEach(function(c) {
        c.classList.remove('selected');
    });
    el.classList.add('selected');
    document.getElementById('requestType').value = type;
    document.getElementById('notifyBtn').disabled = false;

    var label = document.getElementById('notesLabel');
    var field = document.getElementById('notesField');
    label.textContent  = NOTES_LABELS[type]       || 'Additional details (optional)';
    field.placeholder  = NOTES_PLACEHOLDERS[type]  || '';
    document.getElementById('notesWrap').style.display = '';
}
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

