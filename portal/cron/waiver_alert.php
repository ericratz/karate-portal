<?php
// Injury waiver alert — runs daily at 8:00 AM
// Alerts if any student/guest attended in the last 7 days without signing injury waiver
// Cron schedule: 0 8 * * *   (8:00 AM daily)
// Command: php /home/sites/35b/0/049118ce4f/public_html/karate/portal/cron/waiver_alert.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

date_default_timezone_set('America/Denver');

// Find students who attended recently but have no injury waiver
$no_waiver = db()->query(
    'SELECT DISTINCT s.first_name, s.last_name, s.student_type,
            MAX(cs.session_date) AS last_attended
     FROM students s
     JOIN attendance a  ON a.student_id = s.id AND a.present = 1
     JOIN class_sessions cs ON cs.id = a.session_id
     WHERE s.injury_waiver = 0
       AND s.active = 1
       AND cs.session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     GROUP BY s.id
     ORDER BY s.last_name, s.first_name'
)->fetchAll();

if (empty($no_waiver)) {
    exit(0); // Nothing to report
}

$list = '';
foreach ($no_waiver as $s) {
    $type = ucfirst($s['student_type']);
    $list .= "  - {$s['last_name']}, {$s['first_name']} ($type) — last attended "
           . date('M j, Y', strtotime($s['last_attended'])) . "\n";
}

mail(
    DOJO_EMAIL,
    '[Karate Portal] ' . count($no_waiver) . ' attendee(s) missing injury waiver',
    "The following students/guests attended class recently but have not signed the injury waiver:\n\n"
        . $list . "\n"
        . "Please collect signed waivers or update their records:\n"
        . SITE_URL . "/admin/student_edit.php",
    'From: ' . DOJO_EMAIL
);
