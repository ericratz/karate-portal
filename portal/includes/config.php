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
    @mail($to_email, '[' . SITE_NAME . '] Payment Receipt', $body, $headers);
}
