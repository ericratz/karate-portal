<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url($_SESSION['role']));
    exit;
}

function mask_email(string $email): string {
    $at = strpos($email, '@');
    if ($at === false) return '***';
    $local  = substr($email, 0, $at);
    $domain = substr($email, $at); // includes @
    $masked = substr($local, 0, 1) . str_repeat('*', max(3, strlen($local) - 1));
    return $masked . $domain;
}

$error    = '';
$sent     = false;
$step     = 1;        // 1 = enter username, 2 = confirm masked email
$masked   = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');

    if ($username === '') {
        $error = 'Please enter your username.';

    } elseif (isset($_POST['confirmed'])) {
        // Step 2 — re-look up and send
        $user_stmt = db()->prepare(
            'SELECT id, email FROM users WHERE username = ? AND active = 1 LIMIT 1'
        );
        $user_stmt->execute([$username]);
        $user = $user_stmt->fetch();

        if ($user && $user['email']) {
            db()->prepare('UPDATE password_reset_tokens SET used = 1 WHERE user_id = ? AND used = 0')
                ->execute([$user['id']]);

            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);

            db()->prepare(
                'INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)'
            )->execute([$user['id'], $token, $expires]);

            $reset_url = SITE_URL . '/reset_password.php?token=' . $token;
            $subject   = 'Reset your password — ' . SITE_NAME;
            $body      = "You requested a password reset for your " . SITE_NAME . " account.\n\n"
                       . "Click the link below to set a new password (expires in 1 hour):\n\n"
                       . $reset_url . "\n\n"
                       . "If you did not request this, you can ignore this email.\n";
            $headers   = "From: " . DOJO_EMAIL . "\r\nReply-To: " . ADMIN_EMAIL . "\r\nContent-Type: text/plain; charset=UTF-8";

            $sent_ok = log_email($user['email'], $subject, $body, $headers, 'password_reset');
            if (!$sent_ok) {
                log_event('error', 'email', 'Password reset email failed to send', ['user_id' => $user['id']]);
            }
        }

        $sent = true;

    } else {
        // Step 1 — look up and show masked email
        $user_stmt = db()->prepare(
            'SELECT email FROM users WHERE username = ? AND active = 1 LIMIT 1'
        );
        $user_stmt->execute([$username]);
        $user = $user_stmt->fetch();

        if ($user && $user['email']) {
            $masked = mask_email($user['email']);
            $step   = 2;
        } else {
            // Don't reveal whether username exists — show the same confirm screen with a placeholder
            $masked = '***@***';
            $step   = 2;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password — <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <style nonce="<?= csp_nonce() ?>">
        body { background: #f0f0f0; }
        .card-wrap { max-width: 420px; margin: 80px auto; }
        .card-header { background: #6f42c1; color: #fff; text-align: center; padding: 1.25rem; }
        .card-header h4 { margin: 0; font-weight: 600; }
    </style>
</head>
<body>
<div class="card-wrap">
    <div class="card shadow">
        <div class="card-header"><h4>Forgot Password</h4></div>
        <div class="card-body p-4">

            <?php if ($sent): ?>
                <div class="alert alert-success">
                    Reset link sent. Check your inbox and follow the link within 1 hour.
                </div>
                <div class="text-center mt-2">
                    <a href="login.php">Back to login</a>
                </div>

            <?php elseif ($step === 2): ?>
                <p class="mb-3">We'll send a reset link to:</p>
                <div class="alert alert-secondary text-center fw-semibold fs-5 py-2">
                    <?= htmlspecialchars($masked) ?>
                </div>
                <p class="text-muted small mb-4">Not right? <a href="forgot_password.php">Try a different username.</a></p>
                <form method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="username" value="<?= htmlspecialchars($username) ?>">
                    <input type="hidden" name="confirmed" value="1">
                    <div class="d-grid">
                        <button type="submit" class="btn btn-lg"
                                style="background:#198754;border-color:#198754;color:#fff">Send Reset Link</button>
                    </div>
                </form>

            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <p class="text-muted small mb-3">Enter your username and we'll show where the reset link will be sent.</p>
                <form method="post">
                    <?= csrf_input() ?>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required autofocus
                               autocomplete="username"
                               value="<?= htmlspecialchars($username) ?>">
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-lg"
                                style="background:#198754;border-color:#198754;color:#fff">Continue</button>
                    </div>
                </form>
                <div class="text-center mt-3 small">
                    <a href="login.php">Back to login</a>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>
</body>
</html>
