<?php
// Rent reminder — runs on the 1st of the month AND every Saturday.
// Sends a reminder each time it runs until rent is recorded in expenses.
//
// Two cron entries (both call this same script):
//   0 14 1 * *   (2:00 PM London on the 1st = 7:00 AM Utah on the 1st)
//   0 14 * * 6   (2:00 PM London Saturday   = 7:00 AM Utah Saturday)
// Command: <php binary> <portal path>/cron/rent_reminder.php — see cron/CRON_SETUP.txt

// ── CLI only ──────────────────────────────────────────────────────────────
// This job runs unattended with no authentication check, so it must never be
// triggerable over HTTP. The root .htaccess blocks portal/cron/ — but that is
// webserver configuration, and it is silently inert anywhere AllowOverride is
// not set (as it was in the Docker dev/CI container until V5.0). This guard
// does not depend on the server being configured correctly.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script is CLI-only.\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

date_default_timezone_set('America/Denver');

$month     = date('F Y');
$month_sql = date('Y-m');

try {
    // Check if rent has already been recorded this month
    $already_paid = db()->prepare(
        "SELECT COUNT(*) FROM expenses
         WHERE expense_type = 'rent'
           AND DATE_FORMAT(expense_date, '%Y-%m') = ?"
    );
    $already_paid->execute([$month_sql]);
    $count = (int)$already_paid->fetchColumn();

    if ($count > 0) {
        log_event('info', 'cron', 'Rent check OK — already recorded', ['month' => $month_sql]);
        echo "OK: Rent already recorded for {$month}.\n";
        exit(0);
    }

    $is_first = (date('j') === '1');
    $subject  = $is_first
        ? '[Karate Portal] Center Stage rent due — ' . $month
        : '[Karate Portal] Center Stage rent still unpaid — ' . $month;
    $body = $is_first
        ? "Reminder: Center Stage rent is due for {$month}.\n\n"
            . "Record the payment once paid:\n"
            . SITE_URL . "/admin/expenses.php\n"
        : "Center Stage rent for {$month} has not been recorded yet.\n\n"
            . "This reminder will repeat every Saturday until it is entered.\n\n"
            . "Record it here:\n"
            . SITE_URL . "/admin/expenses.php\n";

    log_event('warning', 'cron', 'Rent reminder sent', ['month' => $month_sql]);
    mail(DOJO_EMAIL, $subject, $body, 'From: ' . DOJO_EMAIL);

    echo "ALERT sent: Rent not yet recorded for {$month}.\n";
} catch (Exception $e) {
    log_event('error', 'cron', 'Rent reminder failed', ['message' => $e->getMessage()]);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
