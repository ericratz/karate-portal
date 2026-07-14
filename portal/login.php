<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';

// Already logged in — send to the right dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url($_SESSION['role']));
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim(post_str('username'));
    $password = post_str('password');

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
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <style nonce="<?= csp_nonce() ?>">
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
                           value="<?= htmlspecialchars(post_str('username')) ?>"
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
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"
                         width="18" height="18" class="me-2" style="vertical-align:middle">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                    Sign in with Google
                </a>
            </div>
            <?php endif; ?>

        </div>
        <div class="card-footer text-center text-muted small py-2">
            <a href="<?= SITE_URL ?>/forgot_password.php">Forgot password?</a>
            &nbsp;|&nbsp;
            Don't have an account?
            <a href="<?= SITE_URL ?>/register.php">Create one</a>
            &nbsp;|&nbsp;
            <a href="/karate/">Back to karate home</a>
        </div>
    </div>
</div>

</body>
</html>

