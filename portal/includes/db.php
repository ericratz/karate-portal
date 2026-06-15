<?php
// Database connection — credentials loaded from .env (two levels up)
// Fill in .env at project root: DB_HOST, DB_NAME, DB_USER, DB_PASS

$_env_file = dirname(__DIR__, 2) . '/.env';
if (file_exists($_env_file)) {
    foreach (file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (strncmp(trim($_line), '#', 1) === 0) continue;
        if (strpos($_line, '=') === false) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_k = trim($_k); $_v = trim($_v);
        if (!defined($_k)) define($_k, $_v);
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
        $pdo->exec("CREATE TABLE IF NOT EXISTS link_requests (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            user_id       INT NOT NULL,
            request_type  ENUM('new_guest','existing_student','parent') NOT NULL,
            notes         TEXT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved      TINYINT(1) NOT NULL DEFAULT 0,
            resolved_at   TIMESTAMP NULL,
            resolved_by   INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    return $pdo;
}

