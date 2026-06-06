<?php
// Auto-inactivation — runs daily at 6:00 AM
// Sets active=0 for students with no attendance in the last 3 months (active_override IS NULL)
// Sets active=1 for students who have attended within the last 3 months (active_override IS NULL)
// Students with active_override set are never touched by this script.
//
// Cron schedule: 0 6 * * *   (6:00 AM daily)
// Command: php /path/to/karate/portal/cron/auto_inactive.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auto_inactive.php';

date_default_timezone_set('America/Denver');

$before_active   = (int)db()->query('SELECT COUNT(*) FROM students WHERE active = 1')->fetchColumn();
apply_auto_inactive();
$after_active    = (int)db()->query('SELECT COUNT(*) FROM students WHERE active = 1')->fetchColumn();

$deactivated = max(0, $before_active - $after_active);
$reactivated = max(0, $after_active  - $before_active);

// ── Report ───────────────────────────────────────────────────
if ($deactivated > 0 || $reactivated > 0) {
    $body = "Daily auto-inactivation report:\n\n"
          . "  Deactivated (no attendance in 3 months): $deactivated\n"
          . "  Reactivated (returned to class):         $reactivated\n\n"
          . "Review the roster:\n"
          . SITE_URL . "/admin/\n";

    mail(
        DOJO_EMAIL,
        '[Karate Portal] Student active status updated',
        $body,
        'From: ' . DOJO_EMAIL
    );
}
