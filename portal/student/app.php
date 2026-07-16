<?php
// React SPA shell for the student portal — same bundle the parent portal
// serves (frontend/ builds into parent/dist/ with an absolute base URL, so
// the hashed assets load correctly from this page too). Students see a
// single-member family: the api/v1 endpoints scope every query through
// family_can_access(), which resolves to just their own student record.
// Gate matches the old student pages: any logged-in user.

require_once __DIR__ . '/../includes/auth.php';
require_login();

$manifest_file = __DIR__ . '/../parent/dist/.vite/manifest.json';
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
<title>My Dashboard — Shotokan Karate Portal</title>
<?php foreach ($css_files as $css): ?>
<link rel="stylesheet" href="../parent/dist/<?= $css ?>">
<?php endforeach; ?>
</head>
<body>
<div id="root"></div>
<script type="module" src="../parent/dist/<?= $js_file ?>"></script>
</body>
</html>
