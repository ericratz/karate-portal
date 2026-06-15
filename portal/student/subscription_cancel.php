<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/paypal.php';
require_login();
verify_csrf();

$student = db()->prepare('SELECT id FROM students WHERE user_id = ?');
$student->execute([current_user_id()]);
$student_id = $student->fetchColumn();

if (!$student_id) {
    header('Location: index.php');
    exit;
}

$sub = db()->prepare(
    "SELECT paypal_subscription_id FROM subscriptions WHERE student_id=? AND status='active'"
);
$sub->execute([$student_id]);
$row = $sub->fetch();

if ($row) {
    try {
        paypal_cancel_subscription($row['paypal_subscription_id']);
        db()->prepare("UPDATE subscriptions SET status='cancelled' WHERE paypal_subscription_id=?")
           ->execute([$row['paypal_subscription_id']]);
        audit('subscription_cancel', 'student', $student_id, 'sub=' . $row['paypal_subscription_id']);
    } catch (RuntimeException $e) {
        error_log('Subscription cancel error: ' . $e->getMessage());
    }
}

header('Location: profile_edit.php?autopay=cancelled');
exit;

