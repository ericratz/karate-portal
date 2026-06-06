<?php
// Shared helper — run the 3-month inactivation rule immediately.
// Called from any page that displays the student roster so statuses
// are always current without waiting for the nightly cron.
// Only touches students where active_override IS NULL.

function apply_auto_inactive(): void {
    // Deactivate: no attendance in the last 3 months (includes never-attended).
    db()->exec(
        'UPDATE students
         SET active = 0
         WHERE active_override IS NULL
           AND active = 1
           AND id NOT IN (
               SELECT DISTINCT a.student_id
               FROM attendance a
               JOIN class_sessions cs ON cs.id = a.session_id
               WHERE a.present = 1
                 AND cs.session_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
           )'
    );

    // Reactivate: attended within last 3 months
    db()->exec(
        'UPDATE students
         SET active = 1
         WHERE active_override IS NULL
           AND active = 0
           AND id IN (
               SELECT DISTINCT a.student_id
               FROM attendance a
               JOIN class_sessions cs ON cs.id = a.session_id
               WHERE a.present = 1
                 AND cs.session_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
           )'
    );
}
