<?php
// React SPA shell for the admin portal — same bundle as the parent, student,
// and instructor shells (frontend/ builds into parent/dist/ with an absolute
// base URL; the admin pages load as their own code-split chunk). Server-side
// role gate matches the old admin pages; the api/v1/admin endpoints re-check
// admin on every request.

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

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
<title>Admin — Shotokan Karate Portal</title>
<?php foreach ($css_files as $css): ?>
<link rel="stylesheet" href="../parent/dist/<?= $css ?>">
<?php endforeach; ?>
</head>
<body>
<div id="root"></div>
<script type="module" src="../parent/dist/<?= $js_file ?>"></script>
</body>
</html>
