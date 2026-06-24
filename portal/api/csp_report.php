<?php
// Receives CSP violation reports from browsers (no auth required — called by browser directly)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    exit;
}

$data   = json_decode($raw, true);
$report = $data['csp-report'] ?? null;
if (!$report) {
    http_response_code(400);
    exit;
}

$directive = $report['violated-directive'] ?? 'unknown';

log_event('warning', 'csp', 'CSP violation: ' . substr($directive, 0, 100), [
    'document_uri' => substr($report['document-uri']       ?? '', 0, 200),
    'blocked_uri'  => substr($report['blocked-uri']        ?? '', 0, 200),
    'source_file'  => substr($report['source-file']        ?? '', 0, 200),
    'line'         => $report['line-number'] ?? null,
]);

http_response_code(204);
