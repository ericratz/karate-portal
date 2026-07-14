<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url($_SESSION['role']));
    exit;
}

$token = trim(get_str('token'));
$error = '';
$done  = false;

// Look up token
$tok_stmt = db()->prepare(
    'SELECT t.id, t.user_id, t.expires_at, t.used
     FROM password_reset_tokens t
     WHERE t.token = ? LIMIT 1'
);
$tok_stmt->execute([$token]);
$tok = $tok_stmt->fetch();

$valid = $tok
      && !$tok['used']
      && strtotime($tok['expires_at']) > time();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $valid) {
    verify_csrf();
    $new     = post_str('new_password');
    $confirm = post_str('confirm_password');

    if (strlen($new) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
             ->execute([password_hash($new, PASSWORD_BCRYPT), $tok['user_id']]);
        db()->prepare('UPDATE password_reset_tokens SET used = 1 WHERE id = ?')
             ->execute([$tok['id']]);
        audit('password_reset', 'user', $tok['user_id']);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password — <?= htmlspecialchars(SITE_NAME) ?></title>
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
        <div class="card-header"><h4>Reset Password</h4></div>
        <div class="card-body p-4">

            <?php if ($done): ?>
                <div class="alert alert-success">Your password has been reset.</div>
                <div class="text-center mt-2">
                    <a href="login.php" class="btn btn-success">Log In</a>
                </div>

            <?php elseif (!$valid): ?>
                <div class="alert alert-danger">
                    This reset link is invalid or has expired.
                </div>
                <div class="text-center mt-2">
                    <a href="forgot_password.php">Request a new link</a>
                </div>

            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="post" action="reset_password.php?token=<?= htmlspecialchars($token) ?>">
                    <?= csrf_input() ?>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control"
                               minlength="8" required autofocus>
                        <div class="form-text">Minimum 8 characters.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg"
                                style="background:#198754;border-color:#198754">Set New Password</button>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </div>
</div>
</body>
</html>
