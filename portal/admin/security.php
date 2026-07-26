<?php
// Admin → Security: enrol in two-factor, manage backup codes, review the
// browsers that are currently trusted.
//
// Server-rendered rather than an SPA route on purpose: it is reached rarely, by
// one or two people, and handles a secret that is shown exactly once. Keeping it
// off the JSON API means the secret never travels as part of a cached fetch
// response or sits in a client-side store.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/twofactor.php';
require_role('admin');

// (int): current_user_id() is nullable in general, but require_role('admin')
// above has already sent an unauthenticated visitor to the login page.
$user_id  = (int)current_user_id();
$username = (string)($_SESSION['username'] ?? '');

$error = '';
$notice = '';
/** @var string[] $new_codes Shown once, immediately after generation. */
$new_codes = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'begin') {
        // Hold the candidate secret in the session, NOT the database: an
        // abandoned enrolment must not leave the account half-configured.
        $_SESSION['twofa_setup_secret'] = totp_new_secret();

    } elseif ($action === 'cancel') {
        unset($_SESSION['twofa_setup_secret']);

    } elseif ($action === 'confirm') {
        $secret = (string)($_SESSION['twofa_setup_secret'] ?? '');
        $code   = trim(post_str('code'));
        if ($secret === '') {
            $error = 'Setup expired. Start again.';
        } elseif (totp_verify($secret, $code) === null) {
            // Proving a working code before switching 2FA on is the whole point
            // of this step — it is what stops the account locking itself out
            // against a mistyped or mis-scanned secret.
            $error = 'That code was not correct. Check the app and try the current code.';
        } else {
            twofa_enable($user_id, $secret);
            unset($_SESSION['twofa_setup_secret']);
            $new_codes = twofa_generate_backup_codes($user_id);
            $notice = 'Two-factor authentication is on. Save these backup codes now — they are shown only once.';
        }

    } elseif ($action === 'regenerate') {
        if (twofa_enabled($user_id)) {
            $new_codes = twofa_generate_backup_codes($user_id);
            $notice = 'New backup codes generated. The previous set no longer works.';
        }

    } elseif ($action === 'revoke_devices') {
        $n = twofa_revoke_all_devices($user_id);
        audit('twofa_devices_revoked', 'user', $user_id, "count=$n");
        $notice = $n === 1
            ? 'Removed 1 trusted device. It will ask for a code next time.'
            : "Removed $n trusted devices. They will ask for a code next time.";

    } elseif ($action === 'disable') {
        // Re-check the password: this switches off a security control, so
        // holding a live session is not sufficient authority on its own.
        $pw = post_str('password');
        $q  = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $q->execute([$user_id]);
        $hash = (string)$q->fetchColumn();
        if ($pw === '' || !password_verify($pw, $hash)) {
            $error = 'Password incorrect — two-factor is still on.';
        } else {
            twofa_disable($user_id);
            $notice = 'Two-factor authentication is off.';
        }
    }
}

$enabled       = twofa_enabled($user_id);
$setup_secret  = (string)($_SESSION['twofa_setup_secret'] ?? '');
$remaining     = $enabled ? twofa_backup_codes_remaining($user_id) : 0;
$devices       = $enabled ? twofa_list_devices($user_id) : [];

$page_title = 'Security';
require_once __DIR__ . '/../includes/header.php';
?>

<h3 class="mb-3">Security</h3>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<?php if ($new_codes): ?>
    <div class="card mb-4 border-warning">
        <div class="card-header bg-white fw-semibold">Backup codes — save these now</div>
        <div class="card-body">
            <p class="text-muted small">
                Each works once, in place of a code from your app. Keep them somewhere
                that is not your phone. This is the only time they are shown.
            </p>
            <div class="row row-cols-2 row-cols-md-5 g-2 mb-3">
                <?php foreach ($new_codes as $c): ?>
                    <div class="col"><code class="d-block border rounded text-center py-2"><?= htmlspecialchars($c) ?></code></div>
                <?php endforeach; ?>
            </div>
            <p class="small mb-0">
                If you lose both your authenticator and these codes, two-factor has to be
                switched off directly in the database — see <code>migrations/v5_two_factor.sql</code>.
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Two-Factor Authentication</span>
        <span class="badge <?= $enabled ? 'bg-success' : 'bg-secondary' ?>">
            <?= $enabled ? 'On' : 'Off' ?>
        </span>
    </div>
    <div class="card-body">

    <?php if (!$enabled && $setup_secret === ''): ?>
        <p class="text-muted">
            Adds a code from your phone on top of your password. You are asked for it
            once per browser, then not again for <?= TWOFA_TRUST_DAYS ?> days — not at every login.
            It applies to admin accounts only.
        </p>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="begin">
            <button class="btn btn-action">Set up two-factor</button>
        </form>

    <?php elseif ($setup_secret !== ''): ?>
        <p class="fw-semibold mb-2">1. Add this key to your authenticator app</p>
        <p class="text-muted small mb-2">
            Google Authenticator, Authy, 1Password — any of them. On a phone, the link
            opens the app directly. On a computer, choose "enter a setup key" and type it.
        </p>
        <p class="mb-2">
            <code class="fs-5 user-select-all"><?= htmlspecialchars(totp_secret_display($setup_secret)) ?></code>
        </p>
        <p class="mb-4">
            <a href="<?= htmlspecialchars(totp_uri($setup_secret, $username, 'Shotokan Karate')) ?>">
                Open in authenticator app
            </a>
        </p>

        <p class="fw-semibold mb-2">2. Enter the code it shows</p>
        <form method="post" class="d-flex gap-2 align-items-start flex-wrap">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="confirm">
            <input type="text" name="code" class="form-control" style="max-width:160px"
                   inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                   placeholder="123456" autofocus required>
            <button class="btn btn-action">Turn on two-factor</button>
            <button class="btn btn-secondary" name="action" value="cancel">Cancel</button>
        </form>

    <?php else: ?>
        <p class="text-muted mb-3">
            On since <?= htmlspecialchars(date('j M Y')) ?>. You have
            <strong><?= $remaining ?></strong> unused backup code<?= $remaining === 1 ? '' : 's' ?>.
        </p>
        <div class="d-flex gap-2 flex-wrap">
            <form method="post">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="regenerate">
                <button class="btn btn-sm btn-action">Generate new backup codes</button>
            </form>
        </div>

        <hr class="my-4">
        <p class="fw-semibold mb-1">Turn two-factor off</p>
        <p class="text-muted small">Confirm your password. This also forgets every trusted device.</p>
        <form method="post" class="d-flex gap-2 align-items-start flex-wrap">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="disable">
            <input type="password" name="password" class="form-control" style="max-width:220px"
                   autocomplete="current-password" placeholder="Your password" required>
            <!-- Outline, not solid: destructive actions are a red line that fills
                 on hover, matching the navbar's Log out button. -->
            <button class="btn btn-sm btn-outline-danger">Turn off</button>
        </form>
    <?php endif; ?>

    </div>
</div>

<?php if ($enabled): ?>
<div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Trusted devices</span>
        <?php if ($devices): ?>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="revoke_devices">
            <button class="btn btn-sm btn-outline-danger">Forget all</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (!$devices): ?>
            <p class="p-3 mb-0 text-muted">No trusted devices — every sign-in asks for a code.</p>
        <?php else: ?>
        <table class="table mb-0">
            <thead class="table-light">
                <tr><th>Browser</th><th>Added</th><th>Last used</th><th>Expires</th></tr>
            </thead>
            <tbody>
            <?php foreach ($devices as $d): ?>
                <tr>
                    <td class="small"><?= htmlspecialchars((string)($d['user_agent'] ?: 'Unknown')) ?></td>
                    <td class="small"><?= htmlspecialchars(date('j M Y', (int)strtotime((string)$d['created_at']))) ?></td>
                    <td class="small">
                        <?= $d['last_seen']
                            ? htmlspecialchars(date('j M Y', (int)strtotime((string)$d['last_seen'])))
                            : '<span class="text-muted">never</span>' ?>
                    </td>
                    <td class="small"><?= htmlspecialchars(date('j M Y', (int)strtotime((string)$d['expires_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
