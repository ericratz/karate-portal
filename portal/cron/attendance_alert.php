<?php
// Attendance reminder — runs every Sunday at 7:00 AM
// Alerts if no attendance was recorded for the most recent Saturday class
// Cron schedule: 0 7 * * 0   (7:00 AM every Sunday)
// Command: php /home/sites/35b/0/049118ce4f/public_html/karate/portal/cron/attendance_alert.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

date_default_timezone_set('America/Denver');

// Find last Saturday
$last_saturday = date('Y-m-d', strtotime('last saturday'));

$session = db()->prepare(
    'SELECT id FROM class_sessions WHERE session_date = ?'
);
$session->execute([$last_saturday]);
$session = $session->fetch();

if (!$session) {
    mail(
        DOJO_EMAIL,
        '[Karate Portal] Attendance not recorded — ' . date('j M Y', strtotime($last_saturday)),
        "No attendance was recorded for the class on "
            . date('l, F j, Y', strtotime($last_saturday)) . ".\n\n"
            . "Please log in and record attendance:\n"
            . SITE_URL . "/instructor/attendance.php?date=$last_saturday",
        'From: ' . DOJO_EMAIL
    );
}

