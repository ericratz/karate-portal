<?php
// Shared helper — purge old log rows on the same opportunistic schedule as
// apply_auto_inactive(): run on admin dashboard load rather than a real cron.
// error_log:    1 month retention  (debug/operational noise)
// email_log:    6 month retention
// activity_log: 6 month retention

function apply_log_retention(): void {
    db()->exec("DELETE FROM error_log    WHERE logged_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
    db()->exec("DELETE FROM email_log    WHERE sent_at    < DATE_SUB(NOW(), INTERVAL 6 MONTH)");
    db()->exec("DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)");
}
