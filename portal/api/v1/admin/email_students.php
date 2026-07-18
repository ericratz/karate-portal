<?php
// /api/v1/admin/email_students.php — the bulk-email page.
// GET: every student with a usable email address (student email, falling
//      back to their login email) for the recipient table.
// POST {subject, body, send_to:[ids]}: sends one personalised plain-text
//      email per recipient via log_email(), same headers/wording as the old
//      admin/email_students.php, and reports sent/failed counts.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/config.php';

api_require_role('admin');

function email_recipients(): array {
    return db()->query(
        'SELECT s.id, s.first_name, s.last_name, s.email, s.student_type, s.active,
                u.email AS login_email
         FROM students s
         LEFT JOIN users u ON u.id = s.user_id
         ORDER BY s.first_name, s.last_name'
    )->fetchAll();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input   = api_read_json();
    $subject = trim(api_str($input, 'subject'));
    $body    = trim(api_str($input, 'body'));
    $send_to = $input['send_to'] ?? [];
    $send_to = array_filter(array_map('intval', is_array($send_to) ? $send_to : []));

    if (!$subject || !$body) {
        api_error('Subject and message body are required.', 422);
    }
    if (empty($send_to)) {
        api_error('Please select at least one recipient.', 422);
    }

    $by_id  = array_column(email_recipients(), null, 'id');
    $sent   = 0;
    $failed = 0;
    foreach ($send_to as $sid) {
        $t = $by_id[$sid] ?? null;
        if (!$t) continue;
        $to = $t['email'] ?: $t['login_email'];
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $failed++; continue; }
        $name          = $t['first_name'] . ' ' . $t['last_name'];
        $personal_body = "Dear $name,\n\n$body\n\n— " . SITE_NAME;
        $headers       = "From: " . DOJO_EMAIL . "\r\n"
                       . "Reply-To: " . ADMIN_EMAIL . "\r\n"
                       . "Content-Type: text/plain; charset=UTF-8\r\n";
        log_email($to, $subject, $personal_body, $headers, 'bulk') ? $sent++ : $failed++;
    }

    api_respond(['sent' => $sent, 'failed' => $failed]);
}

api_require_method('GET');

$recipients = [];
foreach (email_recipients() as $s) {
    $email = $s['email'] ?: $s['login_email'];
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    $recipients[] = [
        'id'           => (int)$s['id'],
        'name'         => trim($s['first_name'] . ' ' . $s['last_name']),
        'email'        => (string)$email,
        'student_type' => (string)$s['student_type'],
    ];
}

api_respond(['recipients' => $recipients]);
