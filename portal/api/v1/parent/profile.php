<?php
// POST /api/v1/parent/profile.php — update a family member's profile.
// JSON body; same fields, validation, users-table sync, and audit entry as
// the update_profile handler in parent/index.php.

require_once __DIR__ . '/../../../includes/api.php';
require_once __DIR__ . '/../../../includes/family.php';

api_require_method('POST');
api_require_role('parent', 'student', 'guest', 'instructor', 'admin');
api_verify_csrf();

$user_id = (int)current_user_id();
$input   = api_read_json();

$student_id = api_int($input, 'student_id');
if (!family_can_access($user_id, $student_id)) {
    api_error('Student not linked to your account', 403);
}

$first    = trim(api_str($input, 'first_name'));
$last     = trim(api_str($input, 'last_name'));
$dob      = api_str($input, 'date_of_birth');
$phone    = trim(api_str($input, 'phone'));
$email    = trim(api_str($input, 'email'));
$ec_name  = trim(api_str($input, 'emergency_contact_name'));
$ec_phone = trim(api_str($input, 'emergency_contact_phone'));
$street   = trim(api_str($input, 'street_address'));
$csz      = trim(api_str($input, 'city_state_zip'));
$uniform  = trim(api_str($input, 'uniform_size'));
$belt     = trim(api_str($input, 'belt_size'));
$medical  = trim(api_str($input, 'medical_note'));

if ($first === '' || $last === '') {
    api_error('First and last name are required.', 422);
}

db()->prepare(
    'UPDATE students SET first_name=?, last_name=?, date_of_birth=?,
     phone=?, email=?, emergency_contact_name=?, emergency_contact_phone=?,
     street_address=?, city_state_zip=?, uniform_size=?, belt_size=?, medical_note=? WHERE id=?'
)->execute([$first, $last, $dob !== '' ? $dob : null, $phone, $email, $ec_name, $ec_phone,
            $street !== '' ? $street : null, $csz !== '' ? $csz : null,
            $uniform !== '' ? $uniform : null, $belt !== '' ? $belt : null,
            $medical !== '' ? $medical : null, $student_id]);

// Keep the linked user account's identity fields in sync
$lu = db()->prepare('SELECT user_id FROM students WHERE id=?');
$lu->execute([$student_id]);
if ($uid = $lu->fetchColumn()) {
    db()->prepare('UPDATE users SET first_name=?, last_name=?, email=? WHERE id=?')
         ->execute([$first, $last, $email !== '' ? $email : null, (int)$uid]);
}

audit('update_student', 'student', $student_id, 'by_parent_user_id=' . $user_id);

$reload = db()->prepare('SELECT * FROM students WHERE id = ?');
$reload->execute([$student_id]);
$student = $reload->fetch();
if ($student === false) {
    api_error('Student not found', 404);
}

api_respond(['saved' => true, 'student' => family_student_profile($student)]);
