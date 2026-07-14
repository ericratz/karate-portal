<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

verify_csrf();

$message = trim(post_str('feedback_message'));
if ($message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please enter a message before sending.']);
    exit;
}

$user_id = current_user_id();
$user = db()->prepare('SELECT u.email, u.username, s.first_name, s.last_name
                       FROM users u
                       LEFT JOIN students s ON s.user_id = u.id
                       WHERE u.id = ?');
$user->execute([$user_id]);
$user = $user->fetch();

$name     = ($user && $user['first_name']) ? $user['first_name'] . ' ' . $user['last_name'] : ($user['username'] ?? 'Unknown');
$subject  = "Portal Message from $name";
$body     = "Message from: $name\n\n" . $message;
$reply_to = ($user && $user['email']) ? $user['email'] : ADMIN_EMAIL;
$headers  = "From: " . DOJO_EMAIL . "\r\nReply-To: " . $reply_to . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";

if (log_email(ADMIN_EMAIL, $subject, $body, $headers, 'feedback')) {
    echo json_encode(['ok' => true]);
} else {
    log_event('error', 'email', 'Feedback email failed to send', ['from_user_id' => $user_id]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong sending your message. Please try again.']);
}
