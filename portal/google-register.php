<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

// Must arrive here from Google callback
if (empty($_SESSION['google_pending'])) {
    header('Location: /karate/portal/login.php');
    exit;
}

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url($_SESSION['role']));
    exit;
}

$g     = $_SESSION['google_pending'];
$email = $g['email'];
$first = $g['first'];
$last  = $g['last'];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $first    = trim($_POST['first_name']    ?? $first);
    $last     = trim($_POST['last_name']     ?? $last);
    $dob      = trim($_POST['date_of_birth'] ?? '');
    $username = trim($_POST['username']      ?? '');

    if (!$first || !$last || !$dob || !$username) {
        $error = 'All fields are required.';
    } else {
        $check = db()->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            $error = 'That username is already taken. Please choose another.';
        } else {
            $db = db();

            // No password — account can only be accessed via Google OAuth
            $db->prepare(
                'INSERT INTO users (username, password_hash, role, email, first_name, last_name, active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)'
            )->execute([
                $username,
                password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), // random, unusable
                'student',
                $email,
                $first,
                $last,
            ]);
            $uid = (int)$db->lastInsertId();

            // Clear pending data and log them straight in
            // No student record is created — Noji links the account to a roster entry from the admin panel
            unset($_SESSION['google_pending']);

            session_regenerate_id(true);
            $_SESSION['user_id']  = $uid;
            $_SESSION['role']     = 'student';
            $_SESSION['username'] = $username;

            audit('google_register');

            header('Location: /karate/portal/student/');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complete Registration — Shotokan Karate</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #f0f0f0; }
        .register-card { max-width: 460px; margin: 60px auto; }
        .register-card .card-header {
            background: #6f42c1; color: #fff;
            text-align: center; padding: 1.25rem;
        }
        .register-card .card-header h4 { margin: 0; font-weight: 600; }
    </style>
</head>
<body>

<div class="register-card">
    <div class="card shadow">
        <div class="card-header">
            <h4>Shotokan Karate</h4>
            <small>Complete Your Registration</small>
        </div>
        <div class="card-body p-4">

            <p class="text-muted small mb-3">
                Signed in as <strong><?= htmlspecialchars($email) ?></strong> via Google.
                Just a couple more details to finish setting up your account.
            </p>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= csrf_input() ?>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-control" required
                               value="<?= htmlspecialchars($_POST['first_name'] ?? $first) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" required
                               value="<?= htmlspecialchars($_POST['last_name'] ?? $last) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Date of Birth *</label>
                        <input type="date" name="date_of_birth" class="form-control" required
                               value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" value="<?= htmlspecialchars($email) ?>" disabled>
                        <div class="form-text">Provided by Google — used to log in.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Choose a Username *</label>
                        <input type="text" name="username" class="form-control" required
                               autocomplete="off"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        <div class="form-text">Used for display within the portal.</div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary w-100">Create Account</button>
                    </div>
                </div>
            </form>

        </div>
        <div class="card-footer text-center text-muted small py-2">
            Wrong account? <a href="/karate/portal/login.php">Go back</a>
        </div>
    </div>
</div>

</body>
</html>
