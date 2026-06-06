<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/paypal.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF check for fetch()-based requests
$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request token']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$items = $input['items'] ?? [];
$total = (float)($input['total'] ?? 0);
$note  = $input['note'] ?? '';

// Validate student has a profile
$student = db()->prepare('SELECT id FROM students WHERE user_id = ?');
$student->execute([current_user_id()]);
if (!$student->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'No student profile linked to this account']);
    exit;
}

if (empty($items) || $total <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'No items selected']);
    exit;
}

// Validate total server-side — client cannot manipulate amounts
$valid_types  = ['monthly_tuition','registration','belt_test','slc_training','seminar','other'];
$server_total = 0;
foreach ($items as $item) {
    if (!in_array($item['type'], $valid_types, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payment type']);
        exit;
    }
    $server_total += ($item['type'] === 'other')
        ? (float)$item['amount']
        : fee_for_type($item['type']);
}
$server_total = round($server_total, 2);

if (abs($server_total - $total) > 0.01) {
    http_response_code(400);
    echo json_encode(['error' => 'Amount mismatch']);
    exit;
}

$labels = array_map(function($i) {
    return $i['type'] === 'other'
        ? ($i['reason'] ?? 'Other')
        : ucwords(str_replace('_', ' ', $i['type']));
}, $items);
$description = 'Shotokan Karate — ' . implode(', ', $labels);

try {
    $order_id = paypal_create_order($server_total, $description);

    $_SESSION['pending_payment'] = [
        'order_id' => $order_id,
        'items'    => $items,
        'total'    => $server_total,
        'note'     => $note,
        'user_id'  => current_user_id(),
    ];

    echo json_encode(['id' => $order_id]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
