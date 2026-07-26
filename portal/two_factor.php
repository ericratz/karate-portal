<?php
// Two-factor challenge — the step between a correct password and a session.
//
// Reached only via attempt_login() returning 'twofa'. Until a code is accepted
// there is no $_SESSION['user_id'], so every existing role guard already denies
// this visitor everything; the pending marker carries no privileges of its own.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/twofactor.php';

// Already fully logged in — nothing to do here.
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url($_SESSION['role']));
    exit;
}

$pending = twofa_pending();
if ($pending === null) {
    // No half-login, or it timed out. Start again rather than hint at why.
    redirect('/login.php');
}

$user_id  = (int)$pending['user_id'];
$username = (string)$pending['username'];
$is_admin = (bool)$pending['is_admin'];

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();

    $code  = trim(post_str('code'));
    $trust = post_str('trust_device') !== '';
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '';

    // Rate-limited on the same table as password attempts, under a distinct
    // identifier: without this the second factor is brute-forceable at leisure,
    // since the password is already known by the time anyone reaches this page.
    if (is_rate_limited('2fa:' . $username, '2fa:' . $ip)) {
        $error = 'Too many attempts. Please wait 15 minutes and try again.';
        log_event('warning', 'auth', 'Two-factor rate limited', ['user_id' => $user_id, 'ip' => $ip]);

    } elseif ($code === '') {
        $error = 'Enter the 6-digit code from your authenticator app.';

    } else {
        // A backup code contains a dash; a TOTP code is six digits. Try the
        // shape that matches rather than burning a backup code on a typo.
        $ok = str_contains($code, '-')
            ? twofa_consume_backup_code($user_id, $code)
            : twofa_verify_totp($user_id, $code);

        if ($ok) {
            try {
                db()->prepare('DELETE FROM login_attempts WHERE identifier = ? OR identifier = ?')
                    ->execute(['2fa:' . $username, '2fa:' . $ip]);
            } catch (Exception $e) {}

            if ($trust) twofa_trust_this_device($user_id);
            twofa_purge_expired_devices();

            establish_session($user_id, $username, $is_admin);
            header('Location: ' . dashboard_url($_SESSION['role']));
            exit;
        }

        record_failed_login('2fa:' . $username, '2fa:' . $ip);
        audit('twofa_fail', 'user', $user_id);
        log_event('warning', 'auth', 'Two-factor code rejected', ['user_id' => $user_id, 'ip' => $ip]);
        $error = 'That code was not correct. Codes change every 30 seconds — try the current one.';
    }
}

$remaining = twofa_backup_codes_remaining($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-Factor Verification — <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet" href="<?= app_url('/assets/css/portal.css') ?>">
    <style nonce="<?= csp_nonce() ?>">
        body { background: #f0f0f0; }
        .twofa-card { max-width: 440px; margin: 80px auto; }
        .twofa-card .card-header {
            background: #6f42c1; color: #fff; text-align: center; padding: 1.25rem;
        }
        .twofa-card .card-header h4 { margin: 0; font-weight: 600; }
        /* Codes are read off a screen and typed in a hurry — give them room. */
        #code { font-size: 1.5rem; letter-spacing: .35em; text-align: center; }
    </style>
</head>
<body>
<div class="twofa-card">
    <div class="card shadow">
        <div class="card-header">
            <h4 class="brand-name">Shotokan Karate and <span class="text-nowrap">Self-defense</span></h4>
            <small class="brand-subtitle">Two-Factor Verification</small>
        </div>
        <div class="card-body p-4">

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <p class="text-muted small mb-3">
                Signed in as <strong><?= htmlspecialchars($username) ?></strong>.
                Enter the current 6-digit code from your authenticator app.
            </p>

            <form method="post">
                <?= csrf_input() ?>
                <div class="mb-3">
                    <label for="code" class="form-label">Authentication code</label>
                    <input type="text" id="code" name="code" class="form-control"
                           inputmode="numeric" autocomplete="one-time-code"
                           maxlength="11" autofocus required>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" value="1"
                           id="trust_device" name="trust_device" checked>
                    <label class="form-check-label" for="trust_device">
                        Trust this device for <?= TWOFA_TRUST_DAYS ?> days
                        <span class="d-block text-muted small">
                            You won't be asked for a code again on this browser.
                        </span>
                    </label>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-nav btn-lg">Verify</button>
                </div>
            </form>

            <hr class="my-4">
            <p class="text-muted small mb-0">
                Lost your phone? Enter one of your backup codes above instead —
                they look like <code>a1b2c-d3e4f</code>.
                <?php if ($remaining > 0): ?>
                    You have <strong><?= $remaining ?></strong> unused.
                <?php else: ?>
                    <strong>You have none left.</strong>
                <?php endif; ?>
            </p>
        </div>
        <div class="card-footer text-center text-muted small py-2">
            <a href="<?= app_url('/logout.php') ?>">Cancel and sign out</a>
        </div>
    </div>
</div>
</body>
</html>
