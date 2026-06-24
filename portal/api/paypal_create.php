<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/paypal.php';
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

// Determine the student this payment is for.
// Parents may specify a student_id from their family; everyone else uses their own record.
$role             = $_SESSION['role'] ?? 'student';
$input_student_id = (int)($input['student_id'] ?? 0);

if (in_array($role, ['parent', 'instructor'], true) && $input_student_id) {
    // Build the allowed list for this parent
    $allowed_ids = [];
    $own = db()->prepare('SELECT id FROM students WHERE user_id = ?');
    $own->execute([current_user_id()]);
    if ($own_row = $own->fetch()) {
        $own_sid = (int)$own_row['id'];
        $allowed_ids[] = $own_sid;
        $ch = db()->prepare('SELECT child_student_id FROM student_guardians WHERE parent_student_id = ?');
        $ch->execute([$own_sid]);
        foreach ($ch->fetchAll() as $r) $allowed_ids[] = (int)$r['child_student_id'];
    }

    if (!in_array($input_student_id, $allowed_ids, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Student not linked to your account']);
        exit;
    }
    $validated_student_id = $input_student_id;
} else {
    // Student / instructor / admin — use their own linked student record
    $stmt = db()->prepare('SELECT id FROM students WHERE user_id = ?');
    $stmt->execute([current_user_id()]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(403);
        echo json_encode(['error' => 'No student profile linked to this account']);
        exit;
    }
    $validated_student_id = (int)$row['id'];
}

if (empty($items) || $total <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'No items selected']);
    exit;
}

// Validate total server-side — client cannot manipulate amounts
$valid_types       = ['monthly_tuition','registration','belt_test','slc_training','seminar','other','donation'];
$user_priced_types = ['other', 'donation'];
$server_total = 0;
foreach ($items as $item) {
    if (!in_array($item['type'], $valid_types, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payment type']);
        exit;
    }
    $server_total += in_array($item['type'], $user_priced_types, true)
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
    if ($i['type'] === 'other')    return $i['reason'] ?? 'Other';
    if ($i['type'] === 'donation') return 'Donation';
    return ucwords(str_replace('_', ' ', $i['type']));
}, $items);
$description = 'Shotokan Karate — ' . implode(', ', $labels);

try {
    $order_id = paypal_create_order($server_total, $description);

    $_SESSION['pending_payment'] = [
        'order_id'   => $order_id,
        'items'      => $items,
        'total'      => $server_total,
        'note'       => $note,
        'user_id'    => current_user_id(),
        'student_id' => $validated_student_id,
    ];

    echo json_encode(['id' => $order_id]);
} catch (RuntimeException $e) {
    log_event('error', 'payment', 'PayPal order create failed', ['message' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
