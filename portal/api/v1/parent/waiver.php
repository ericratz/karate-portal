<?php
// /api/v1/parent/waiver.php?student_id=N — injury waiver for one family member.
// GET returns status + latest submission + prefill values; POST signs it.
// Validation mirrors parent/waiver.php exactly.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/family.php';

api_require_role('parent', 'student', 'guest', 'instructor', 'admin');

$user_id    = (int)current_user_id();
$method     = $_SERVER['REQUEST_METHOD'] ?? '';
$input      = $method === 'POST' ? api_read_json() : [];
$student_id = get_int('student_id') ?: api_int($input, 'student_id');

if (!family_can_access($user_id, $student_id)) {
    api_error('Student not linked to your account', 403);
}

$stmt = db()->prepare('SELECT * FROM students WHERE id = ?');
$stmt->execute([$student_id]);
$student = $stmt->fetch();
if ($student === false) {
    api_error('Student not found', 404);
}

$signed = (bool)$student['injury_waiver'];

$is_minor = false;
if (!empty($student['date_of_birth'])) {
    $is_minor = (new DateTime((string)$student['date_of_birth']))->diff(new DateTime())->y < 18;
}

/** The submission fields a family member may see — never ip_address. */
function waiver_submission_fields(array $sub): array {
    return [
        'print_name'             => (string)$sub['print_name'],
        'signature'              => $sub['signature'] ?? null,
        'signed_date'            => $sub['signed_date'] ?? null,
        'guardian_signature'     => $sub['guardian_signature'] ?? null,
        'guardian_signed_date'   => $sub['guardian_signed_date'] ?? null,
        'date_of_birth'          => $sub['date_of_birth'] ?? null,
        'cell_phone'             => $sub['cell_phone'] ?? null,
        'home_phone'             => $sub['home_phone'] ?? null,
        'email'                  => $sub['email'] ?? null,
        'street_address'         => $sub['street_address'] ?? null,
        'city_state_zip'         => $sub['city_state_zip'] ?? null,
        'mailing_address'        => $sub['mailing_address'] ?? null,
        'mailing_city_state_zip' => $sub['mailing_city_state_zip'] ?? null,
    ];
}

if ($method === 'GET') {
    $submission = null;
    if ($signed) {
        $sub_q = db()->prepare(
            'SELECT * FROM injury_waiver_submissions WHERE student_id = ? ORDER BY submitted_at DESC LIMIT 1'
        );
        $sub_q->execute([$student_id]);
        $sub_row = $sub_q->fetch();
        if (is_array($sub_row)) {
            $submission = waiver_submission_fields($sub_row);
        }
    }

    api_respond([
        'student' => [
            'id'         => (int)$student['id'],
            'first_name' => (string)$student['first_name'],
            'last_name'  => (string)$student['last_name'],
        ],
        'signed'      => $signed,
        'signed_date' => $student['injury_waiver_date'] ?? null,
        'is_minor'    => $is_minor,
        'submission'  => $submission,
        'prefill'     => [
            'print_name'    => trim((string)$student['first_name'] . ' ' . (string)$student['last_name']),
            'date_of_birth' => $student['date_of_birth'] ?? null,
            'cell_phone'    => $student['phone'] ?? null,
            'email'         => $student['email'] ?? null,
            'street_address'=> $student['street_address'] ?? null,
            'city_state_zip'=> $student['city_state_zip'] ?? null,
        ],
    ]);
}

if ($method !== 'POST') {
    api_error('Method not allowed', 405);
}
api_verify_csrf();

if ($signed) {
    api_error('This waiver has already been signed.', 409);
}

$print_name    = trim(api_str($input, 'print_name'));
$signature     = trim(api_str($input, 'signature'));
$signed_date   = trim(api_str($input, 'signed_date', date('Y-m-d')));
$guardian_sig  = trim(api_str($input, 'guardian_signature'));
$guardian_date = trim(api_str($input, 'guardian_signed_date'));
$dob           = trim(api_str($input, 'date_of_birth'));
$cell          = trim(api_str($input, 'cell_phone'));
$home          = trim(api_str($input, 'home_phone'));
$email         = trim(api_str($input, 'email'));
$street        = trim(api_str($input, 'street_address'));
$csz           = trim(api_str($input, 'city_state_zip'));
$mail_addr     = trim(api_str($input, 'mailing_address'));
$mail_csz      = trim(api_str($input, 'mailing_city_state_zip'));
$agreed        = api_bool($input, 'i_agree');

$dob_check = $dob !== '' ? (new DateTime($dob))->diff(new DateTime())->y < 18 : $is_minor;
if ($print_name === '') {
    api_error('Please print the student\'s name.', 422);
} elseif (!$dob_check && $signature === '') {
    api_error('Please provide the student\'s signature.', 422);
} elseif ($dob_check && $guardian_sig === '') {
    api_error('A parent or guardian signature is required for minors.', 422);
} elseif (!$agreed) {
    api_error('You must check the agreement box to submit.', 422);
} elseif ($cell === '' || $email === '' || $street === '' || $csz === '') {
    api_error('Cell phone, email, street address, and city/state/ZIP are required.', 422);
}

$db = db();
$db->prepare(
    'INSERT INTO injury_waiver_submissions
     (student_id, print_name, signature, signed_date,
      guardian_signature, guardian_signed_date,
      date_of_birth, cell_phone, home_phone, email,
      street_address, city_state_zip,
      mailing_address, mailing_city_state_zip, ip_address)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $student_id, $print_name, $signature, $signed_date,
    $guardian_sig !== '' ? $guardian_sig : null, $guardian_date !== '' ? $guardian_date : null,
    $dob !== '' ? $dob : null, $cell, $home !== '' ? $home : null, $email,
    $street, $csz,
    $mail_addr !== '' ? $mail_addr : null, $mail_csz !== '' ? $mail_csz : null,
    $_SERVER['REMOTE_ADDR'] ?? null,
]);

$db->prepare('UPDATE students SET injury_waiver = 1, injury_waiver_date = ? WHERE id = ?')
   ->execute([$signed_date, $student_id]);

api_respond(['signed' => true, 'signed_date' => $signed_date]);
