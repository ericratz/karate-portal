<?php
// React SPA shell for the parent portal. Same server-side role gate as the
// PHP pages; all data flows through api/v1. The Vite build (cd frontend &&
// npm run build) emits hashed bundles + a manifest into dist/, and this page
// reads the manifest so the script/css tags always point at the current hash.
// The bundle loads as an external module from 'self', so the nonce-only CSP
// from auth.php applies unchanged.

require_once __DIR__ . '/../includes/auth.php';
require_role('parent', 'instructor', 'admin');

$manifest_file = __DIR__ . '/dist/.vite/manifest.json';
$manifest_raw  = is_file($manifest_file) ? file_get_contents($manifest_file) : false;
$manifest      = $manifest_raw !== false ? json_decode($manifest_raw, true) : null;

$entry = null;
if (is_array($manifest) && isset($manifest['index.html']) && is_array($manifest['index.html'])) {
    $entry = $manifest['index.html'];
}

if ($entry === null || !isset($entry['file']) || !is_string($entry['file'])) {
    http_response_code(503);
    exit('Frontend bundle not built — run: cd frontend && npm install && npm run build');
}

$js_file   = htmlspecialchars($entry['file']);
$css_files = [];
if (isset($entry['css']) && is_array($entry['css'])) {
    foreach ($entry['css'] as $css) {
        if (is_string($css)) $css_files[] = htmlspecialchars($css);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Family — Shotokan Karate Portal</title>
<?php foreach ($css_files as $css): ?>
<link rel="stylesheet" href="dist/<?= $css ?>">
<?php endforeach; ?>
</head>
<body>
<div id="root"></div>
<script type="module" src="dist/<?= $js_file ?>"></script>
</body>
</html>
