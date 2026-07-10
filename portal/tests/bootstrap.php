<?php
// Provide superglobals expected by auth.php in a CLI context
if (!isset($_SERVER['REMOTE_ADDR']))    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
if (!isset($_SERVER['REQUEST_METHOD'])) $_SERVER['REQUEST_METHOD'] = 'GET';
if (!isset($_SERVER['REQUEST_URI']))    $_SERVER['REQUEST_URI']    = '/';

// Suppress all PHP errors while loading auth.php:
//   1. Prevents PHP warnings (e.g. session_regenerate_id in CLI) from appearing in
//      PHPUnit output or the system error log.
//   2. Prevents config.php's set_error_handler from logging to the DB error_log table,
//      since that handler checks error_reporting() before writing.
$_prev_reporting = error_reporting(0);
set_error_handler(function(): bool { return true; });

require_once __DIR__ . '/../includes/auth.php';

// config.php pushed its own handler on top — restore it away first, then ours.
restore_error_handler(); // removes config.php's db-logging handler
restore_error_handler(); // removes the blanket suppressor above

// Restore caller's error_reporting level for the actual test run.
error_reporting($_prev_reporting);
