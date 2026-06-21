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
    } else {
        switch (attempt_login($username, $password)) {
            case 'ok':
                header('Location: ' . dashboard_url($_SESSION['role']));
                exit;
            case 'inactive':
                $error = 'Your account has been deactivated. Contact Noji for help.';
                break;
            case 'rate_limited':
                $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
                break;
            default:
                $error = 'Invalid username or password.';
        }
    }
}

// Google OAuth error messages
$google_errors = [
    'google_failed' => 'Google sign-in failed. Please try again.',
    'inactive'      => 'Your account is inactive. Contact Noji.',
];
if ($error === '' && isset($_GET['error']) && isset($google_errors[$_GET['error']])) {
    $error = $google_errors[$_GET['error']];
}

// Build Google OAuth URL if credentials are configured
$google_login_url = null;
if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
    require_once __DIR__ . '/vendor/autoload.php';
    $google_client = new Google\Client();
    $google_client->setClientId(GOOGLE_CLIENT_ID);
    $google_client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $google_client->setRedirectUri(GOOGLE_REDIRECT_URI);
    $google_client->addScope('email');
    $google_client->addScope('profile');
    $google_login_url = $google_client->createAuthUrl();
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
        .btn-google {
            background: #fff;
            border: 1px solid #dadce0;
            color: #3c4043;
            font-weight: 500;
        }
        .btn-google:hover { background: #f8f8f8; border-color: #c6c6c6; }
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

            <?php if ($google_login_url): ?>
            <div class="d-flex align-items-center my-3">
                <hr class="flex-grow-1">
                <span class="px-2 text-muted small">or</span>
                <hr class="flex-grow-1">
            </div>
            <div class="d-grid">
                <a href="<?= htmlspecialchars($google_login_url) ?>" class="btn btn-google">
                    <img src="https://developers.google.com/identity/images/g-logo.png"
                         width="18" height="18" class="me-2" alt="">
                    Sign in with Google
                </a>
            </div>
            <?php endif; ?>

        </div>
        <div class="card-footer text-center text-muted small py-2">
            Don't have an account?
            <a href="<?= SITE_URL ?>/register.php">Create one</a>
            &nbsp;|&nbsp;
            <a href="/karate/">Back to karate home</a>
        </div>
    </div>
</div>

</body>
</html>

