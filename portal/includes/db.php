<?php
// Database connection — credentials loaded from .env (two levels up)
// Fill in .env at project root: DB_HOST, DB_NAME, DB_USER, DB_PASS

// Real environment variables (set by docker-compose) win over the .env file,
// so the same .env can say localhost for native XAMPP while containers use db.
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'] as $_k) {
    $_v = getenv($_k);
    if ($_v !== false && $_v !== '') define($_k, $_v);
}

$_env_file = dirname(__DIR__, 2) . '/.env';
if (file_exists($_env_file)) {
    foreach (file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (strncmp(trim($_line), '#', 1) === 0) continue;
        if (strpos($_line, '=') === false) continue;
        $_parts = explode('=', $_line, 2);
        $_k = trim($_parts[0]); $_v = trim($_parts[1] ?? '');
        // Only DB_* keys here — config.php owns SITE_URL/email/OAuth/PayPal, and
        // defining them here (db.php loads first) would preempt config.php's
        // env-var override for SITE_URL.
        if (strncmp($_k, 'DB_', 3) === 0 && !defined($_k)) define($_k, $_v);
    }
}

// Fallback defaults if .env is missing (localhost dev)
if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    'karate_portal');
if (!defined('DB_USER'))    define('DB_USER',    'root');
if (!defined('DB_PASS'))    define('DB_PASS',    '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

