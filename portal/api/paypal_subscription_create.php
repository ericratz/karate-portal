<?php
// Creates a PayPal subscription and redirects to PayPal for approval.
// Called via form POST from student/pay.php (own record) or parent/pay.php
// (own record or a linked child, selected via the student_id field).

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/paypal.php';
require_login();
verify_csrf();

$back = SITE_URL . (has_role('parent') ? '/parent/pay.php' : '/student/pay.php');

$own = db()->prepare(
    'SELECT s.id, s.first_name, s.last_name, s.email FROM students s WHERE s.user_id = ?'
);
$own->execute([current_user_id()]);
$own = $own->fetch();

if (!$own) {
    header("Location: $back?autopay=no_profile");
    exit;
}

// Target defaults to the caller's own record; parents may pass a linked child
$target_id = post_int('student_id') ?: (int)$own['id'];

$allowed_ids = [(int)$own['id']];
$ch = db()->prepare('SELECT child_student_id FROM student_guardians WHERE parent_student_id = ?');
$ch->execute([(int)$own['id']]);
foreach ($ch->fetchAll(PDO::FETCH_COLUMN) as $cid) $allowed_ids[] = (int)$cid;

if (!in_array($target_id, $allowed_ids, true)) {
    log_event('warning', 'security', 'Subscription create attempted for non-family student', [
        'user_id' => current_user_id(), 'target_student_id' => $target_id,
    ]);
    header("Location: $back?autopay=error");
    exit;
}

$student = db()->prepare('SELECT id, first_name, last_name, email FROM students WHERE id = ?');
$student->execute([$target_id]);
$student = $student->fetch();

if (!$student) {
    header("Location: $back?autopay=no_profile");
    exit;
}

// Block if an active or pending subscription already exists
$existing = db()->prepare(
    "SELECT id FROM subscriptions WHERE student_id = ? AND status IN ('active','pending')"
);
$existing->execute([$student['id']]);
if ($existing->fetch()) {
    header("Location: $back?autopay=already");
    exit;
}

try {
    // Children often have no email of their own — fall back to the payer's
    $sub = paypal_create_subscription(
        $student['first_name'],
        $student['last_name'],
        $student['email'] ?: ($own['email'] ?? '')
    );

    db()->prepare('INSERT INTO subscriptions (student_id, paypal_subscription_id, status) VALUES (?,?,?)')
       ->execute([$student['id'], $sub['id'], 'pending']);

    audit('subscription_create', 'student', $student['id'], 'sub=' . $sub['id']);

    header('Location: ' . $sub['approve_url']);
    exit;
} catch (RuntimeException $e) {
    log_event('error', 'payment', 'PayPal subscription create failed', ['message' => $e->getMessage(), 'student_id' => $student['id']]);
    header("Location: $back?autopay=error");
    exit;
}
