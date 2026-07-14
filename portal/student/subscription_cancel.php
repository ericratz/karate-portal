<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/paypal.php';
require_login();
verify_csrf();

$student = db()->prepare('SELECT id FROM students WHERE user_id = ?');
$student->execute([current_user_id()]);
$own_id = (int)$student->fetchColumn();

if (!$own_id) {
    header('Location: index.php');
    exit;
}

// Target defaults to the caller's own record; parents may cancel a linked child's
$student_id = post_int('student_id') ?: $own_id;
if ($student_id !== $own_id) {
    $ch = db()->prepare(
        'SELECT 1 FROM student_guardians WHERE parent_student_id = ? AND child_student_id = ?'
    );
    $ch->execute([$own_id, $student_id]);
    if (!$ch->fetchColumn()) {
        log_event('warning', 'security', 'Subscription cancel attempted for non-family student', [
            'user_id' => current_user_id(), 'target_student_id' => $student_id,
        ]);
        header('Location: index.php');
        exit;
    }
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
        log_event('error', 'payment', 'PayPal subscription cancel failed', [
            'message' => $e->getMessage(), 'student_id' => $student_id,
        ]);
    }
}

header('Location: ' . (has_role('parent')
    ? SITE_URL . '/parent/pay.php?autopay=cancelled'
    : 'profile_edit.php?autopay=cancelled'));
exit;

