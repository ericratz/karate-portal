<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/vendor/autoload.php';

if (!isset($_GET['code'])) {
    header('Location: /karate/portal/login.php?error=google_failed');
    exit;
}

$client = new Google\Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

if (isset($token['error'])) {
    header('Location: /karate/portal/login.php?error=google_failed');
    exit;
}

$client->setAccessToken($token);

$oauth2 = new Google\Service\Oauth2($client);
$guser  = $oauth2->userinfo->get();
$email  = $guser->email         ?? '';
$first  = $guser->givenName     ?? '';
$last   = $guser->familyName    ?? '';

if ($email === '') {
    header('Location: /karate/portal/login.php?error=google_failed');
    exit;
}

// ── Existing account — log in directly ───────────────────────
$stmt = db()->prepare('SELECT id, username, role, active FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user) {
    if (!$user['active']) {
        header('Location: /karate/portal/login.php?error=inactive');
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['username'] = $user['username'];

    $stype = db()->prepare('SELECT student_type FROM students WHERE user_id = ? LIMIT 1');
    $stype->execute([$user['id']]);
    $_SESSION['student_type'] = $stype->fetchColumn() ?: $user['role'];

    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    audit('google_login_success');

    header('Location: ' . dashboard_url($user['role']));
    exit;
}

// ── New user — hand off to registration page ──────────────────
$_SESSION['google_pending'] = [
    'email' => $email,
    'first' => $first,
    'last'  => $last,
];

header('Location: /karate/portal/google-register.php');
exit;

