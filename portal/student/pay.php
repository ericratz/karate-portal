<?php
// The payment page now lives in the React SPA (app.php#/pay). Stub keeps the
// old URL working and forwards the ?autopay=... flags from the PayPal
// redirect endpoints into the SPA route (HashRouter reads the query string
// inside the hash). The SPA preselects the student's own record.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/family.php';
require_login();

$own = family_own_student((int)current_user_id());
if ($own === null) {
    header('Location: index.php'); exit;
}

$route = 'app.php#/pay/' . (int)$own['id'];

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
