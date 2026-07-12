<?php
// PayPal redirects here after the student approves a subscription.
// URL contains ?subscription_id=I-XXXX

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

// Parents approve subscriptions for children — send them back to their own pages
$is_parent = has_role('parent');
$pay_url   = SITE_URL . ($is_parent ? '/parent/pay.php' : '/student/pay.php');
$home_url  = SITE_URL . ($is_parent ? '/parent/index.php' : '/student/index.php');

$sub_id = $_GET['subscription_id'] ?? '';

if (!$sub_id) {
    header("Location: $pay_url?autopay=error");
    exit;
}

// Activate the pending subscription row for this student
$stmt = db()->prepare(
    "UPDATE subscriptions SET status = 'active'
     WHERE paypal_subscription_id = ? AND status = 'pending'"
);
$stmt->execute([$sub_id]);

if ($stmt->rowCount() === 0) {
    // Already activated (webhook beat us here) or unknown ID — either way fine
    header("Location: $home_url?autopay=success");
    exit;
}

audit('subscription_activated', 'student', 0, 'sub=' . $sub_id);
header("Location: $home_url?autopay=success");
exit;
