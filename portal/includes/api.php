<?php
// JSON API plumbing for the api/v1 endpoints (consumed by the React frontend).
//
// Envelope: {"ok":true,"data":...} on success, {"ok":false,"error":"..."} on
// failure. Auth failures answer with JSON 401/403 instead of the login-page
// redirect / plaintext exit the HTML pages use, so fetch() callers can branch
// on status codes. Mutations authenticate the CSRF token from the
// X-CSRF-Token header (same session token the form pages embed).

require_once __DIR__ . '/auth.php';

/** Send a success envelope and stop. */
function api_respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

/** Send an error envelope and stop. */
function api_error(string $message, int $status): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/** Reject any request method other than the given one. */
function api_require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        api_error('Method not allowed', 405);
    }
}

/** require_role() for API endpoints — JSON 401/403 instead of redirect. */
function api_require_role(string ...$roles): void {
    if (empty($_SESSION['user_id'])) {
        api_error('Not logged in', 401);
    }
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        api_error('Access denied', 403);
    }
}

/** CSRF check for fetch()-based mutations — token travels in X-CSRF-Token. */
function api_verify_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token'])
        || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
        log_event('warning', 'security', 'API CSRF token mismatch', ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
        api_error('Invalid request token', 403);
    }
}

/** Decode the JSON request body; anything but a JSON object comes back as []. */
function api_read_json(): array {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw === false ? '' : $raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Scalar accessors for a decoded JSON body — same guard as post_str/post_int
 * in auth.php: a malicious body can put an array/object where a string is
 * expected, which would fatal in trim()/strlen().
 */
function api_str(array $input, string $key, string $default = ''): string {
    $val = $input[$key] ?? $default;
    if (is_string($val)) return $val;
    return is_int($val) || is_float($val) ? (string)$val : $default;
}

function api_int(array $input, string $key, int $default = 0): int {
    $val = $input[$key] ?? $default;
    return is_scalar($val) ? (int)$val : $default;
}

function api_bool(array $input, string $key): bool {
    return (bool)($input[$key] ?? false);
}
