<?php
// POST /api/v1/parent/subscription.php — auto-pay create/cancel for the SPA.
// JSON body: {"action":"create"|"cancel","student_id":N}.
// create → {"approve_url":...}; the client redirects there, PayPal sends the
// user back through api/paypal_subscription_return.php. cancel → {"cancelled":true}.
// Same family scoping, audit, and logging as the legacy form endpoints
// (api/paypal_subscription_create.php, student/subscription_cancel.php),
// which stay in place for the student-role pages.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/family.php';
require_once __DIR__ . '/../../../includes/paypal.php';

api_require_method('POST');
api_require_role('parent', 'student', 'guest', 'instructor', 'admin');
api_verify_csrf();

$input  = api_read_json();
$action = api_str($input, 'action');

$user_id = (int)current_user_id();
$own     = family_own_student($user_id);
if ($own === null) {
    api_error('No student profile found.', 404);
}

$student_id = api_int($input, 'student_id') ?: (int)$own['id'];
if (!family_can_access($user_id, $student_id)) {
    log_event('warning', 'security', 'API subscription ' . $action . ' attempted for non-family student', [
        'user_id' => $user_id, 'target_student_id' => $student_id,
    ]);
    api_error('Student not linked to your account', 403);
}

if ($action === 'create') {
    $stmt = db()->prepare('SELECT id, first_name, last_name, email FROM students WHERE id = ?');
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    if (!$student) {
        api_error('No student profile found.', 404);
    }

    // Block if an active or pending subscription already exists
    $existing = db()->prepare(
        "SELECT id FROM subscriptions WHERE student_id = ? AND status IN ('active','pending')"
    );
    $existing->execute([$student_id]);
    if ($existing->fetch()) {
        api_error('That family member already has an active monthly auto-pay set up.', 409);
    }

    try {
        // Children often have no email of their own — fall back to the payer's
        $sub = paypal_create_subscription(
            $student['first_name'],
            $student['last_name'],
            $student['email'] ?: ($own['email'] ?? '')
        );

        db()->prepare('INSERT INTO subscriptions (student_id, paypal_subscription_id, status) VALUES (?,?,?)')
           ->execute([$student_id, $sub['id'], 'pending']);

        audit('subscription_create', 'student', $student_id, 'sub=' . $sub['id']);

        api_respond(['approve_url' => $sub['approve_url']]);
    } catch (RuntimeException $e) {
        log_event('error', 'payment', 'PayPal subscription create failed', [
            'message' => $e->getMessage(), 'student_id' => $student_id,
        ]);
        api_error('Something went wrong setting up auto-pay. Please try again or contact Noji.', 502);
    }
}

if ($action === 'cancel') {
    $sub = db()->prepare(
        "SELECT paypal_subscription_id FROM subscriptions WHERE student_id = ? AND status = 'active'"
    );
    $sub->execute([$student_id]);
    $row = $sub->fetch();
    if (!$row) {
        api_error('No active auto-pay found for that family member.', 404);
    }

    try {
        paypal_cancel_subscription($row['paypal_subscription_id']);
        db()->prepare("UPDATE subscriptions SET status='cancelled' WHERE paypal_subscription_id=?")
           ->execute([$row['paypal_subscription_id']]);
        audit('subscription_cancel', 'student', $student_id, 'sub=' . $row['paypal_subscription_id']);
        api_respond(['cancelled' => true]);
    } catch (RuntimeException $e) {
        log_event('error', 'payment', 'PayPal subscription cancel failed', [
            'message' => $e->getMessage(), 'student_id' => $student_id,
        ]);
        api_error('Cancelling with PayPal failed. Please try again or contact Noji.', 502);
    }
}

api_error('Unknown action', 400);
