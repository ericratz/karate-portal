<?php
// Called via fetch() after the user approves the payment in the PayPal popup
// Captures the payment and logs it to the database

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/paypal.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF check for fetch()-based requests
$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request token']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true);
$order_id = $input['orderID'] ?? '';

// Validate against session — prevents a user from submitting a foreign order ID
$pending = $_SESSION['pending_payment'] ?? null;
if (!$pending || $pending['order_id'] !== $order_id || $pending['user_id'] !== current_user_id()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired payment session']);
    exit;
}

try {
    $capture = paypal_capture_order($order_id);
    $status  = $capture['status'] ?? '';

    if ($status !== 'COMPLETED') {
        echo json_encode(['success' => false, 'error' => 'Payment not completed (status: ' . $status . ')']);
        exit;
    }

    // Verify the captured amount matches what we set (fraud check)
    $captured_amount = paypal_captured_amount($capture);
    if (abs($captured_amount - $pending['total']) > 0.01) {
        log_event('critical', 'payment', 'PayPal amount mismatch', [
            'expected' => $pending['total'], 'captured' => $captured_amount, 'order_id' => $order_id,
        ]);
        echo json_encode(['success' => false, 'error' => 'Payment amount mismatch — contact the instructor']);
        exit;
    }

    // Use the pre-validated student_id stored at order-creation time.
    // This supports parents paying for a child as well as the standard student flow.
    $student_id  = $pending['student_id'] ?? null;
    $student_row = null;
    if ($student_id) {
        $stmt = db()->prepare('SELECT id, first_name, last_name, email FROM students WHERE id = ?');
        $stmt->execute([$student_id]);
        $student_row = $stmt->fetch();
        if (!$student_row) $student_id = null;
    }

    // Pull PayPal transaction ID from capture response
    $txn_id = $capture['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
    $note   = $pending['note'] ?? null;

    $user_priced = ['other', 'donation'];

    // Record each item to its respective table
    $insert_payment = db()->prepare(
        'INSERT INTO payments
         (student_id, amount, payment_type, payment_method, transaction_id, month_covered, notes)
         VALUES (?,?,?,?,?,?,?)'
    );
    $insert_donation = db()->prepare(
        'INSERT INTO donations (student_id, amount, payment_method, payment_date, donor_name, notes)
         VALUES (?, ?, ?, CURDATE(), ?, ?)'
    );

    foreach ($pending['items'] as $item) {
        $amount = in_array($item['type'], $user_priced)
            ? (float)$item['amount']
            : fee_for_type($item['type']);

        if ($item['type'] === 'donation') {
            // Anonymous donations carry no student link or donor name
            $anonymous  = !empty($item['anonymous']);
            $donor_name = (!$anonymous && $student_row)
                ? trim($student_row['first_name'] . ' ' . $student_row['last_name'])
                : null;
            $dnotes = $txn_id ? "PayPal: $txn_id" : null;
            $insert_donation->execute([
                $anonymous ? null : $student_id,
                $amount, 'paypal', $donor_name, $dnotes,
            ]);
        } else {
            $insert_payment->execute([
                $student_id,
                $amount,
                $item['type'],
                'paypal',
                $txn_id,
                $item['type'] === 'monthly_tuition' ? ($item['month_covered'] ?? date('Y-m-01')) : null,
                $note ?: ($item['reason'] ?? null),
            ]);
            // Auto-promote guest to student on registration fee payment
            if ($item['type'] === 'registration') {
                db()->prepare("UPDATE students SET student_type='student' WHERE id=?")
                     ->execute([$student_id]);
            }
        }
    }

    // Send payment receipt email
    if ($student_row && !empty($student_row['email'])) {
        $receipt_items = array_map(function($item) use ($user_priced) {
            return [
                'type'   => $item['type'],
                'amount' => in_array($item['type'], $user_priced)
                    ? (float)$item['amount']
                    : fee_for_type($item['type']),
            ];
        }, $pending['items']);
        $student_name = trim($student_row['first_name'] . ' ' . $student_row['last_name']);
        send_payment_receipt($student_row['email'], $student_name, $receipt_items, $captured_amount, 'paypal', $txn_id);
    }

    // Clear pending payment from session
    unset($_SESSION['pending_payment']);

    echo json_encode([
        'success'        => true,
        'amount'         => $captured_amount,
        'transaction_id' => $txn_id,
    ]);

} catch (RuntimeException $e) {
    log_event('error', 'payment', 'PayPal capture exception', ['message' => $e->getMessage(), 'order_id' => $order_id]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Payment capture failed — contact the instructor']);
}
