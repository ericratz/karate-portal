<?php
// Admin-only database backup download.
// READ-ONLY — uses SELECT / SHOW queries only. Nothing is modified or deleted.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$pdo    = db();
$dbname = DB_NAME;
$ts     = date('Y-m-d_His');
$fname  = $dbname . '_' . $ts . '.sql';

// Stream straight to the browser as a file download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache, no-store');
header('Pragma: no-cache');

// Allow plenty of time and memory for large databases
set_time_limit(300);
ini_set('memory_limit', '256M');

// Flush output as we go so the browser receives data immediately
@ob_end_clean();
ob_implicit_flush(true);

// ── Header ────────────────────────────────────────────────────────────────────
echo "-- ============================================================\n";
echo "-- Database backup: {$dbname}\n";
echo "-- Generated:       " . date('D d M Y g:i a') . "\n";
echo "-- PHP export (read-only — no data was modified or deleted)\n";
echo "-- ============================================================\n\n";
echo "SET NAMES utf8mb4;\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

// ── Tables ────────────────────────────────────────────────────────────────────
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $safe = '`' . str_replace('`', '``', $table) . '`';

    // CREATE TABLE
    $row = $pdo->query("SHOW CREATE TABLE {$safe}")->fetch(PDO::FETCH_NUM);
    echo "-- ------------------------------------------------------------\n";
    echo "-- Table: {$table}\n";
    echo "-- ------------------------------------------------------------\n";
    echo "DROP TABLE IF EXISTS {$safe};\n";
    echo $row[1] . ";\n\n";

    // Row data — fetch in chunks to keep memory flat on large tables
    $stmt = $pdo->query("SELECT * FROM {$safe}");
    $first = true;
    $cols  = null;

    while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($first) {
            $cols  = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($data)));
            $first = false;
        }
        $vals = array_map(function ($v) use ($pdo) {
            return $v === null ? 'NULL' : $pdo->quote($v);
        }, array_values($data));

        echo "INSERT INTO {$safe} ({$cols}) VALUES (" . implode(', ', $vals) . ");\n";
    }

    echo "\n";
}

// ── Footer ────────────────────────────────────────────────────────────────────
echo "SET FOREIGN_KEY_CHECKS = 1;\n";
echo "-- End of backup\n";
exit;
