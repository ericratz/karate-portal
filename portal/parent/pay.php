<?php
// The payment page now lives in the React SPA (app.php#/pay/N). Stub keeps
// the old URL working: same role gate, same ?student_id= deep link, and the
// ?autopay=... flags the PayPal redirect endpoints attach ride along into the
// SPA route (HashRouter reads the query string inside the hash).

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/family.php';
require_role('parent', 'instructor', 'admin');

$route = 'app.php#/pay';

$student_id = get_int('student_id');
if ($student_id && family_can_access((int)current_user_id(), $student_id)) {
    $route .= '/' . $student_id;
}

// Fixed flag vocabulary — forward only known values (also keeps the
// user-supplied string out of the Location header entirely)
$autopay_flags = [
    'success'    => 'success',
    'already'    => 'already',
    'error'      => 'error',
    'no_profile' => 'no_profile',
    'cancelled'  => 'cancelled',
];
$autopay = get_str('autopay');
if (isset($autopay_flags[$autopay])) {
    $route .= '?autopay=' . $autopay_flags[$autopay];
}

header('Location: ' . $route);
exit;
