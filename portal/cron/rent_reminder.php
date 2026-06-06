<?php
// Rent reminder — runs every Saturday at 7:00 AM
// Sends alert only on the FIRST Saturday of the month
// Cron schedule: 0 7 * * 6   (7:00 AM every Saturday)
// Command: php /home/sites/35b/0/049118ce4f/public_html/karate/portal/cron/rent_reminder.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

date_default_timezone_set('America/Denver');

// Only act on the 1st Saturday of the month
// Day of month <= 7 means it's the first occurrence of that weekday
if ((int)date('j') > 7) {
    exit(0);
}

$month     = date('F Y');
$month_sql = date('Y-m');

// Check if rent has already been recorded this month
$already_paid = db()->prepare(
    "SELECT COUNT(*) FROM expenses
     WHERE expense_type = 'rent'
       AND DATE_FORMAT(expense_date, '%Y-%m') = ?"
);
$already_paid->execute([$month_sql]);
$count = (int)$already_paid->fetchColumn();

if ($count > 0) {
    exit(0); // Rent already recorded — no need to alert
}

mail(
    DOJO_EMAIL,
    '[Karate Portal] Rent reminder — ' . $month,
    "This is your monthly reminder to pay and record the studio rent for $month.\n\n"
        . "Record the payment here:\n"
        . SITE_URL . "/admin/expenses.php\n\n"
        . "If rent has already been paid, please mark it as paid in the expenses log.",
    'From: ' . DOJO_EMAIL
);
