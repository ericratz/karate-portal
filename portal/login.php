<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';

// Already logged in — send to the right dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url($_SESSION['role']));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } elseif (is_rate_limited($username, $_SERVER['REMOTE_ADDR'] ?? '')) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } elseif (attempt_login($username, $password)) {
        header('Location: ' . dashboard_url($_SESSION['role']));
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}

function dashboard_url(string $role): string {
    switch ($role) {
        case 'admin':      return '/karate/portal/admin/';
        case 'instructor': return '/karate/portal/instructor/';
        default:           return '/karate/portal/student/';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #f0f0f0; }
        .login-card {
            max-width: 400px;
            margin: 80px auto;
        }
        .login-card .card-header {
            background: #6f42c1;
            color: #fff;
            text-align: center;
            padding: 1.25rem;
        }
        .login-card .card-header h4 { margin: 0; font-weight: 600; }
        .login-card .card-header small { opacity: .85; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="card shadow">
        <div class="card-header">
            <h4>Shotokan Karate</h4>
            <small>Student &amp; Instructor Portal</small>
        </div>
        <div class="card-body p-4">

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <?= csrf_input() ?>
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" id="username" name="username"
                           class="form-control" autocomplete="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           autofocus required>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password"
                           class="form-control" autocomplete="current-password" required>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">Log In</button>
                </div>
            </form>

        </div>
        <div class="card-footer text-center text-muted small py-2">
            Don't have an account?
            <a href="/karate/portal/register.php">Create one</a>
            &nbsp;|&nbsp;
            <a href="/karate/">Back to karate home</a>
        </div>
    </div>
</div>

</body>
</html>
