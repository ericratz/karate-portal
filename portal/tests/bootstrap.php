<?php
// Provide superglobals expected by auth.php in a CLI context
if (!isset($_SERVER['REMOTE_ADDR']))    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
if (!isset($_SERVER['REQUEST_METHOD'])) $_SERVER['REQUEST_METHOD'] = 'GET';
if (!isset($_SERVER['REQUEST_URI']))    $_SERVER['REQUEST_URI']    = '/';

// session_regenerate_id() warns in CLI because there are no real HTTP headers;
// it has no effect on test correctness so suppress it here.
set_error_handler(function(int $no, string $str): bool {
    return strpos($str, 'session_regenerate_id') !== false;
}, E_WARNING);

require_once __DIR__ . '/../includes/auth.php';

restore_error_handler();
