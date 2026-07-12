<?php
// Auth helpers — included by every protected page
// Call require_login() or require_role() at the top of each page

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

date_default_timezone_set('America/Denver');

// ── Prevent browser from caching portal pages ─────────────────
// Stops the back-forward cache from serving stale data after edits
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// ── Session hardening ─────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── CSP nonce ────────────────────────────────────────────────
// One random nonce per request, for inline <script>/<style> tags.
function csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

$__csp_nonce = csp_nonce();
$__csp_report_url = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/karate/portal/api/csp_report.php';
header("Reporting-Endpoints: csp-endpoint=\"$__csp_report_url\"");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$__csp_nonce' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.paypal.com https://www.paypalobjects.com https://accounts.google.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; img-src 'self' data: https://www.paypalobjects.com; frame-src https://www.paypal.com https://accounts.google.com; connect-src 'self' https://www.paypal.com https://api.paypal.com https://api.sandbox.paypal.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; report-uri /karate/portal/api/csp_report.php; report-to csp-endpoint");

// ── CSRF ─────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        log_event('warning', 'security', 'CSRF token mismatch', ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
        http_response_code(403);
        exit('Security check failed. Please go back and try again.');
    }
}

// ── Role / login guards ──────────────────────────────────────
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

function require_role(string ...$roles): void {
    require_login();
    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function has_role(string ...$roles): bool {
    return in_array($_SESSION['role'] ?? '', $roles, true);
}

function current_user_id(): ?int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

// ── Rate limiting ─────────────────────────────────────────────
function is_rate_limited(string $username, string $ip): bool {
    // Never rate-limit localhost — allows test suite to run freely
    if ($ip === '127.0.0.1' || $ip === '::1') return false;
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE (identifier = ? OR identifier = ?)
               AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stmt->execute([$username, $ip]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Exception $e) {
        return false; // if table doesn't exist yet, don't block login
    }
}

function record_failed_login(string $username, string $ip): void {
    try {
        $db = db();
        $db->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')->execute([$username]);
        $db->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')->execute([$ip]);
        $db->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    } catch (Exception $e) { /* table may not exist yet */ }
}

// ── Audit log ─────────────────────────────────────────────────
function audit(string $action, ?string $target_type = null, ?int $target_id = null, ?string $detail = null): void {
    try {
        db()->prepare(
            'INSERT INTO activity_log (user_id, username, action, target_type, target_id, detail, ip_address)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? null,
            $action,
            $target_type,
            $target_id,
            $detail,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) { /* never break normal operations */ }
}

// ── Login / logout ────────────────────────────────────────────
// Returns 'ok' | 'invalid' | 'inactive' | 'rate_limited'
function attempt_login(string $username, string $password): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (is_rate_limited($username, $ip)) {
        log_event('warning', 'auth', 'Login rate limited', ['username' => $username, 'ip' => $ip]);
        return 'rate_limited';
    }

    $stmt = db()->prepare(
        'SELECT id, password_hash, is_admin, active FROM users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Wrong username or wrong password
    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_failed_login($username, $ip);
        log_event('warning', 'auth', 'Login failed', ['username' => $username, 'ip' => $ip]);
        return 'invalid';
    }

    // Correct credentials but account deactivated
    if (!$user['active']) {
        log_event('warning', 'auth', 'Login attempt on inactive account', ['username' => $username]);
        return 'inactive';
    }

    // Clear rate limit on success
    try {
        db()->prepare('DELETE FROM login_attempts WHERE identifier = ? OR identifier = ?')
            ->execute([$username, $ip]);
    } catch (Exception $e) {}

    // Rehash if bcrypt cost has changed
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
        $new_hash = password_hash($password, PASSWORD_BCRYPT);
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
             ->execute([$new_hash, $user['id']]);
    }

    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
         ->execute([$user['id']]);

    // @: session_regenerate_id() warns "headers already sent" under CLI/PHPUnit
    // (no real HTTP header stream there) — harmless, never occurs on real requests.
    @session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $username;

    $stype = db()->prepare('SELECT student_type FROM students WHERE user_id = ? LIMIT 1');
    $stype->execute([$user['id']]);
    $stype_val = $stype->fetchColumn() ?: 'student';
    $_SESSION['role'] = $user['is_admin'] ? 'admin' : $stype_val;

    audit('login_success');
    return 'ok';
}

function dashboard_url(string $role): string {
    switch ($role) {
        case 'admin':      return SITE_URL . '/admin/';
        case 'instructor': return SITE_URL . '/instructor/';
        case 'parent':     return SITE_URL . '/parent/';
        default:           return SITE_URL . '/student/'; // student, guest
    }
}


function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

/** Render a person's name with proper capitalisation and XSS escaping. */
function hn(string $name): string {
    return htmlspecialchars(ucwords(strtolower($name)));
}

