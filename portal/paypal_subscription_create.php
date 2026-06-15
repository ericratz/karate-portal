<?php
// Creates a PayPal subscription and redirects the student to PayPal for approval.
// Called via form POST from student/pay.php.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/paypal.php';
require_login();
verify_csrf();

$student = db()->prepare(
    'SELECT s.id, s.first_name, s.last_name, s.email FROM students s WHERE s.user_id = ?'
);
$student->execute([current_user_id()]);
$student = $student->fetch();

if (!$student) {
    header('Location: student/pay.php?autopay=no_profile');
    exit;
}

// Block if an active or pending subscription already exists
$existing = db()->prepare(
    "SELECT id FROM subscriptions WHERE student_id = ? AND status IN ('active','pending')"
);
$existing->execute([$student['id']]);
if ($existing->fetch()) {
    header('Location: student/pay.php?autopay=already');
    exit;
}

try {
    $sub = paypal_create_subscription(
        $student['first_name'],
        $student['last_name'],
        $student['email'] ?? ''
    );

    db()->prepare('INSERT INTO subscriptions (student_id, paypal_subscription_id, status) VALUES (?,?,?)')
       ->execute([$student['id'], $sub['id'], 'pending']);

    audit('subscription_create', 'student', $student['id'], 'sub=' . $sub['id']);

    header('Location: ' . $sub['approve_url']);
    exit;
} catch (RuntimeException $e) {
    error_log('Subscription create error: ' . $e->getMessage());
    header('Location: student/pay.php?autopay=error');
    exit;
}

