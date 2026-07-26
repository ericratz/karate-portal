<?php
// Two-factor policy: who is challenged, when, and how a device earns the right
// not to be asked again.
//
// The shape of this is a deliberate product decision, not the usual default.
// Being prompted for a code at every login was explicitly not wanted, so the
// challenge is per-DEVICE and long-lived: sign in once from a browser, mark it
// trusted, and it is not asked again for ~6 months. What that protects is the
// case that actually matters here — someone who has learned the password
// logging in from their own machine, which has no trust cookie.
//
// Scope is admin accounts only. They are the accounts that can read the whole
// roster, edit payments and reach the logs; a student account is not worth
// pushing a family through authenticator setup for.

declare(strict_types=1);

require_once __DIR__ . '/totp.php';

/** How long a trusted device stays trusted. */
const TWOFA_TRUST_DAYS = 180;
const TWOFA_COOKIE     = 'karate_device';
/** How many single-use backup codes are issued at a time. */
const TWOFA_BACKUP_CODES = 10;

/** Is two-factor actually turned on for this user? */
function twofa_enabled(int $user_id): bool {
    return twofa_secret($user_id) !== '';
}

/** The user's stored TOTP secret, or '' when enrolment was never completed. */
function twofa_secret(int $user_id): string {
    $q = db()->prepare('SELECT totp_secret FROM users WHERE id = ? AND totp_enabled_at IS NOT NULL LIMIT 1');
    $q->execute([$user_id]);
    $secret = $q->fetchColumn();
    return is_string($secret) ? $secret : '';
}

/**
 * Does this login need a code?
 *
 * Only when the account is an admin, has completed enrolment, and the browser
 * is not presenting a still-valid trust cookie.
 */
function twofa_challenge_required(int $user_id, bool $is_admin): bool {
    if (!$is_admin) return false;
    if (twofa_secret($user_id) === '') return false;
    return !twofa_device_is_trusted($user_id);
}

// ── Trusted devices ───────────────────────────────────────────────────────
//
// Split-token ("selector:validator") rather than a single opaque string. The
// selector is the lookup key and is stored as-is; only a hash of the validator
// is stored. A stolen database therefore yields no usable cookies, and the
// lookup is by an indexed exact match instead of scanning every row and
// comparing hashes — which is what makes a constant-time compare meaningful.

/** Read and parse the device cookie. Returns [selector, validator] or null. */
function twofa_cookie_parts(): ?array {
    $raw = $_COOKIE[TWOFA_COOKIE] ?? '';
    // Exactly one colon: anything else is not a cookie we issued.
    if (substr_count($raw, ':') !== 1) return null;
    // array_pad guarantees two elements regardless of the input.
    [$selector, $validator] = array_pad(explode(':', $raw, 2), 2, '');
    if ($selector === '' || $validator === '') return null;
    return [$selector, $validator];
}

/** Is the presented device cookie valid for this user right now? */
function twofa_device_is_trusted(int $user_id): bool {
    $parts = twofa_cookie_parts();
    if ($parts === null) return false;
    [$selector, $validator] = $parts;

    $q = db()->prepare(
        'SELECT id, validator_hash FROM trusted_devices
         WHERE selector = ? AND user_id = ? AND expires_at > NOW() LIMIT 1'
    );
    $q->execute([$selector, $user_id]);
    $row = $q->fetch();
    if (!$row) return false;

    if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        // Right selector, wrong validator: either a stale cookie or someone
        // guessing. Drop the record so a guessing attempt cannot be retried.
        db()->prepare('DELETE FROM trusted_devices WHERE id = ?')->execute([$row['id']]);
        log_event('warning', 'auth', 'Device cookie validator mismatch', ['user_id' => $user_id]);
        return false;
    }

    db()->prepare('UPDATE trusted_devices SET last_seen = NOW() WHERE id = ?')->execute([$row['id']]);
    return true;
}

/** Mark the current browser trusted for TWOFA_TRUST_DAYS and set the cookie. */
function twofa_trust_this_device(int $user_id): void {
    $selector  = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expires   = time() + (TWOFA_TRUST_DAYS * 86400);

    db()->prepare(
        'INSERT INTO trusted_devices (user_id, selector, validator_hash, expires_at, user_agent)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $user_id,
        $selector,
        hash('sha256', $validator),
        date('Y-m-d H:i:s', $expires),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);

    // Same flags as the session cookie: HttpOnly so script cannot read it,
    // SameSite=Lax so it is not sent on cross-site POSTs, Secure per
    // site_is_https() (which fails safe to true off a dev environment).
    //
    // @: like session_regenerate_id() elsewhere, setcookie() warns "headers
    // already sent" under CLI/PHPUnit where there is no HTTP header stream.
    // Harmless there, never reached on a real request.
    @setcookie(TWOFA_COOKIE, $selector . ':' . $validator, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => site_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Forget every trusted device for a user — the "sign out everywhere" action. */
function twofa_revoke_all_devices(int $user_id): int {
    $stmt = db()->prepare('DELETE FROM trusted_devices WHERE user_id = ?');
    $stmt->execute([$user_id]);
    return $stmt->rowCount();
}

/** Trusted devices for the account settings list. */
function twofa_list_devices(int $user_id): array {
    $q = db()->prepare(
        'SELECT id, user_agent, created_at, last_seen, expires_at
         FROM trusted_devices WHERE user_id = ? AND expires_at > NOW()
         ORDER BY last_seen IS NULL, last_seen DESC'
    );
    $q->execute([$user_id]);
    return $q->fetchAll();
}

/** Drop expired rows. Called opportunistically; nothing depends on it running. */
function twofa_purge_expired_devices(): void {
    try {
        db()->prepare('DELETE FROM trusted_devices WHERE expires_at <= NOW()')->execute();
    } catch (Exception $e) {
        // Housekeeping only — never let it break a login.
    }
}

// ── Backup codes ──────────────────────────────────────────────────────────
//
// The way back in when the phone is lost. Stored as bcrypt hashes, single-use,
// and returned in plaintext exactly once — at generation — because that is the
// only moment they can be shown.

/**
 * Replace the user's backup codes. Returns the new plaintext codes; they cannot
 * be recovered afterwards.
 *
 * @return string[]
 */
function twofa_generate_backup_codes(int $user_id): array {
    db()->prepare('DELETE FROM user_backup_codes WHERE user_id = ?')->execute([$user_id]);

    $ins = db()->prepare('INSERT INTO user_backup_codes (user_id, code_hash) VALUES (?, ?)');
    $codes = [];
    for ($i = 0; $i < TWOFA_BACKUP_CODES; $i++) {
        // 10 hex chars, shown grouped as xxxxx-xxxxx to be read off paper.
        $raw  = bin2hex(random_bytes(5));
        $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        $codes[] = $code;
        $ins->execute([$user_id, password_hash($code, PASSWORD_BCRYPT)]);
    }
    return $codes;
}

/** How many unused backup codes remain. */
function twofa_backup_codes_remaining(int $user_id): int {
    $q = db()->prepare('SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL');
    $q->execute([$user_id]);
    return (int)$q->fetchColumn();
}

/**
 * Consume a backup code. Each is valid once; a correct code is burned whether
 * or not the rest of the login later succeeds.
 */
function twofa_consume_backup_code(int $user_id, string $code): bool {
    $code = strtolower(trim($code));
    if ($code === '') return false;

    $q = db()->prepare('SELECT id, code_hash FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL');
    $q->execute([$user_id]);

    foreach ($q->fetchAll() as $row) {
        if (password_verify($code, (string)$row['code_hash'])) {
            db()->prepare('UPDATE user_backup_codes SET used_at = NOW() WHERE id = ?')
                ->execute([$row['id']]);
            audit('twofa_backup_code_used', 'user', $user_id);
            log_event('warning', 'auth', 'Two-factor backup code used', ['user_id' => $user_id]);
            return true;
        }
    }
    return false;
}

// ── Enrolment ─────────────────────────────────────────────────────────────

/**
 * Finish enrolment: store the secret and switch 2FA on. The caller must already
 * have verified a code generated from this secret, which is what proves the
 * authenticator was set up correctly before the account starts requiring it.
 */
function twofa_enable(int $user_id, string $secret): void {
    db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled_at = NOW() WHERE id = ?')
        ->execute([$secret, $user_id]);
    audit('twofa_enabled', 'user', $user_id);
    log_event('info', 'auth', 'Two-factor enabled', ['user_id' => $user_id]);
}

/** Turn 2FA off and clear everything associated with it. */
function twofa_disable(int $user_id): void {
    db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_counter = NULL WHERE id = ?')
        ->execute([$user_id]);
    db()->prepare('DELETE FROM user_backup_codes WHERE user_id = ?')->execute([$user_id]);
    twofa_revoke_all_devices($user_id);
    audit('twofa_disabled', 'user', $user_id);
    log_event('warning', 'auth', 'Two-factor disabled', ['user_id' => $user_id]);
}

/**
 * Verify a TOTP code for a user, rejecting reuse.
 *
 * A code stays valid for the length of its step plus the drift window, so
 * without this a code read over someone's shoulder (or out of a phishing proxy)
 * could be replayed for up to ~90 seconds. Recording the last counter accepted
 * makes each one strictly single-use.
 */
function twofa_verify_totp(int $user_id, string $code): bool {
    $secret = twofa_secret($user_id);
    if ($secret === '') return false;

    $counter = totp_verify($secret, $code);
    if ($counter === null) return false;

    $q = db()->prepare('SELECT totp_last_counter FROM users WHERE id = ? LIMIT 1');
    $q->execute([$user_id]);
    $last = $q->fetchColumn();
    if ($last !== null && $last !== false && $counter <= (int)$last) {
        log_event('warning', 'auth', 'Two-factor code replayed', ['user_id' => $user_id]);
        return false;
    }

    db()->prepare('UPDATE users SET totp_last_counter = ? WHERE id = ?')->execute([$counter, $user_id]);
    return true;
}
