<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: /karate/portal/student/');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        // Check username taken
        $check = db()->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            $error = 'That username is already taken.';
        } else {
            $db = db();
            $db->prepare(
                'INSERT INTO users (username, password_hash, role, email, first_name, last_name)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $username,
                password_hash($password, PASSWORD_BCRYPT),
                'student',
                $email,
                $first,
                $last,
            ]);
            $uid = (int)$db->lastInsertId();
            // Auto-create a student profile linked to this account
            $db->prepare(
                'INSERT INTO students
                 (user_id, first_name, last_name, date_of_birth, email, registration_date, student_type, active)
                 VALUES (?,?,?,?,?,CURDATE(),"guest",1)'
            )->execute([$uid, $first, $last, $dob, $email]);
            $success = 'Account created! You can now log in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register — Shotokan Karate</title>
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
            <small>Create an Account</small>
        </div>
        <div class="card-body p-4">

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <a href="/karate/portal/login.php" class="btn btn-primary w-100">Go to Login</a>
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
            Already have an account? <a href="/karate/portal/login.php">Log in</a>
        </div>
    </div>
</div>

</body>
</html>
