<?php
// ── Load .env (two levels up from portal/includes/) ───────────────────────
$_env_file = dirname(__DIR__, 2) . '/.env';
if (file_exists($_env_file)) {
    foreach (file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (strncmp(trim($_line), '#', 1) === 0) continue;
        if (strpos($_line, '=') === false) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_k = trim($_k); $_v = trim($_v);
        if (!defined($_k)) define($_k, $_v);
    }
}

// ── Site ──────────────────────────────────────────────────────────────────
define('SITE_NAME', 'Shotokan Karate Portal');
if (!defined('SITE_URL')) define('SITE_URL', 'http://localhost/karate/portal');

// ── Email ─────────────────────────────────────────────────────────────────
if (!defined('DOJO_EMAIL'))  define('DOJO_EMAIL',  'noreply@noji.com');
if (!defined('ADMIN_EMAIL')) define('ADMIN_EMAIL', 'admin@example.com');

// ── Fees (business constants — same everywhere) ───────────────────────────
define('MONTHLY_FEE', 30.00);
define('REG_FEE',     15.00);
define('TEST_FEE',    10.00);
define('SLC_FEE',     10.00);
define('SEMINAR_FEE', 60.00);

// ── Google OAuth ──────────────────────────────────────────────────────────
if (!defined('GOOGLE_CLIENT_ID'))     define('GOOGLE_CLIENT_ID',     '');
if (!defined('GOOGLE_CLIENT_SECRET')) define('GOOGLE_CLIENT_SECRET', '');
define('GOOGLE_REDIRECT_URI', SITE_URL . '/google-callback.php');

// ── PayPal ────────────────────────────────────────────────────────────────
if (!defined('PAYPAL_MODE'))       define('PAYPAL_MODE',       'sandbox');
if (!defined('PAYPAL_CLIENT_ID'))  define('PAYPAL_CLIENT_ID',  '');
if (!defined('PAYPAL_SECRET'))     define('PAYPAL_SECRET',     '');
if (!defined('PAYPAL_PLAN_ID'))    define('PAYPAL_PLAN_ID',    '');
if (!defined('PAYPAL_WEBHOOK_ID')) define('PAYPAL_WEBHOOK_ID', '');

// ── Payment receipt email ─────────────────────────────────────────────────
// $items: array of ['type' => string, 'amount' => float]
function send_payment_receipt(string $to_email, string $to_name, array $items, float $total, string $method, ?string $txn_id = null): void {
    if (!$to_email || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) return;

    $lines = '';
    foreach ($items as $item) {
        $label  = ucwords(str_replace('_', ' ', $item['type']));
        $lines .= "  {$label}: $" . number_format((float)$item['amount'], 2) . "\n";
    }

    $body = "Hi {$to_name},\n\n"
          . "We received your payment. Here is your receipt:\n\n"
          . $lines
          . "\n  Total:  $" . number_format($total, 2) . "\n"
          . "  Method: " . ucwords(str_replace('_', ' ', $method)) . "\n"
          . "  Date:   " . date('d M Y') . "\n";
    if ($txn_id) $body .= "  Transaction ID: {$txn_id}\n";
    $body .= "\nView your full payment history:\n"
           . SITE_URL . "/student/payment_history.php\n\n"
           . "Thank you,\n" . SITE_NAME;

    $headers = "From: " . DOJO_EMAIL . "\r\n"
             . "Reply-To: " . ADMIN_EMAIL . "\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    log_email($to_email, '[' . SITE_NAME . '] Payment Receipt', $body, $headers, 'receipt');
}

function log_event(string $level, string $channel, string $message, array $context = []): void {
    static $valid = ['debug', 'info', 'warning', 'error', 'critical'];
    if (!in_array($level, $valid, true)) $level = 'info';
    try {
        db()->prepare(
            'INSERT INTO error_log (level, channel, message, context, user_id, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $level,
            $channel,
            substr($message, 0, 500),
            $context ? json_encode($context) : null,
            $_SESSION['user_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {}
}

// Global PHP error handler — PHP warnings and user errors go to error_log
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) return false;
    $level = ($errno === E_WARNING || $errno === E_USER_WARNING) ? 'warning'
           : ($errno === E_USER_ERROR ? 'error' : null);
    if ($level === null) return false; // skip notices, deprecated, strict
    log_event($level, 'php', substr($errstr, 0, 490), [
        'file' => basename($errfile), 'line' => $errline,
    ]);
    return false; // let PHP handle it normally too
});

register_shutdown_function(function(): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        log_event('critical', 'php', substr($e['message'], 0, 490), [
            'file' => basename($e['file']), 'line' => $e['line'],
        ]);
    }
});

function log_email(string $to, string $subject, string $body, string $headers, string $type = 'other'): bool {
    $result = mail($to, $subject, $body, $headers);
    try {
        db()->prepare(
            'INSERT INTO email_log (to_email, subject, type, status) VALUES (?, ?, ?, ?)'
        )->execute([$to, $subject, $type, $result ? 'sent' : 'failed']);
    } catch (Exception $e) {}
    return $result;
}
