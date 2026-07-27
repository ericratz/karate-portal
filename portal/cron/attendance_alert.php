<?php
// Attendance reminder — runs every SATURDAY at 8:00 PM server time
// Alerts if no attendance was recorded for today's class.
//
// Cron schedule: 0 3 * * 0   (3:00 AM London Sunday = 8:00 PM Utah Saturday)
// Command: <php binary> <portal path>/cron/attendance_alert.php — see cron/CRON_SETUP.txt

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

// Class is today (Saturday) — check if attendance was recorded for today
$today = date('Y-m-d');

try {
    $session = db()->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
    $session->execute([$today]);
    $found = $session->fetch();

    if ($found) {
        log_event('info', 'cron', 'Attendance check OK', ['date' => $today, 'session_id' => $found['id']]);
        echo "OK: Attendance recorded for " . date('D j M Y', (int) strtotime($today)) . " (session id={$found['id']}).\n";
    } else {
        log_event('warning', 'cron', 'Attendance not recorded — alert sent', ['date' => $today]);
        mail(
            DOJO_EMAIL,
            '[Karate Portal] Attendance not recorded — ' . date('j M Y', (int) strtotime($today)),
            "No attendance was recorded for the class on "
                . date('l, F j, Y', (int) strtotime($today)) . ".\n\n"
                . "Please log in and record attendance:\n"
                . SITE_URL . "/instructor/attendance.php?date={$today}",
            'From: ' . DOJO_EMAIL
        );
        echo "ALERT sent: No attendance found for " . date('D j M Y', (int) strtotime($today)) . ".\n";
    }
} catch (Exception $e) {
    log_event('error', 'cron', 'Attendance check failed', ['message' => $e->getMessage()]);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
