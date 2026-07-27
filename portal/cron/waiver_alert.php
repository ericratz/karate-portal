<?php
// Liability waiver alert — runs every FRIDAY at 8:00 AM server time
// Alerts if any active student attended in the last 7 days without signing the liability waiver.
//
// Cron schedule: 0 15 * * 5   (3:00 PM London Friday = 8:00 AM Utah Friday)
// Command: <php binary> <portal path>/cron/waiver_alert.php — see cron/CRON_SETUP.txt

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

// Students who attended in the last 7 days but have no signed waiver
try {
$no_waiver = db()->query(
    'SELECT DISTINCT s.first_name, s.last_name, s.student_type,
            MAX(cs.session_date) AS last_attended
     FROM students s
     JOIN attendance a   ON a.student_id = s.id AND a.present = 1
     JOIN class_sessions cs ON cs.id = a.session_id
     WHERE s.injury_waiver = 0
       AND s.active = 1
       AND cs.session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
       AND s.id IN (
           SELECT a2.student_id FROM attendance a2
           JOIN class_sessions cs2 ON cs2.id = a2.session_id
           WHERE a2.present = 1
             AND cs2.session_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
       )
     GROUP BY s.id
     ORDER BY s.last_name, s.first_name'
)->fetchAll();

    if (empty($no_waiver)) {
        log_event('info', 'cron', 'Waiver check OK — all attendees have signed');
        echo "OK: All students who attended this week have signed waivers.\n";
        exit(0);
    }

    $list = '';
    foreach ($no_waiver as $s) {
        $type  = ucfirst($s['student_type']);
        $list .= "  - {$s['last_name']}, {$s['first_name']} ({$type}) — last attended "
               . date('j M Y', (int) strtotime($s['last_attended'])) . "\n";
    }

    log_event('warning', 'cron', 'Waiver alert sent', ['count' => count($no_waiver)]);
    mail(
        DOJO_EMAIL,
        '[Karate Portal] ' . count($no_waiver) . ' attendee(s) missing liability waiver',
        "The following students/guests attended class recently but have not signed the liability waiver:\n\n"
            . $list . "\n"
            . "Please collect signed waivers or update their records:\n"
            . SITE_URL . "/admin/student_edit.php",
        'From: ' . DOJO_EMAIL
    );

    echo "ALERT sent: " . count($no_waiver) . " student(s) missing waiver.\n";
    foreach ($no_waiver as $s) {
        echo "  - {$s['last_name']}, {$s['first_name']}\n";
    }
} catch (Exception $e) {
    log_event('error', 'cron', 'Waiver check failed', ['message' => $e->getMessage()]);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
