<?php
// Weekly database backup — runs every Sunday at 7:00 AM server time
// Cron schedule: 0 7 * * 0
// Command: /usr/php74/usr/bin/php /home/sites/35b/0/049118ce4f/public_html/karate/portal/cron/backup.php
//
// Pure PHP/PDO export — no mysqldump binary required.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

date_default_timezone_set('America/Denver');

define('BACKUP_DIR',   '/home/sites/35b/0/049118ce4f/backups/karate/');
define('KEEP_BACKUPS', 8);  // keep 8 weeks of backups

// ── Setup ─────────────────────────────────────────────────────────────────────
if (!is_dir(BACKUP_DIR)) {
    if (!mkdir(BACKUP_DIR, 0750, true)) {
        $msg = 'BACKUP FAILED: Could not create backup directory: ' . BACKUP_DIR;
        echo $msg . "\n";
        mail(DOJO_EMAIL, '[Karate Portal] BACKUP FAILED — ' . date('j M Y'), $msg, 'From: ' . DOJO_EMAIL);
        exit(1);
    }
}

$filename = BACKUP_DIR . 'karate_' . date('Y-m-d') . '.sql';
$pdo      = db();
$dbname   = DB_NAME;

// ── Write SQL export ──────────────────────────────────────────────────────────
$fh = fopen($filename, 'w');
if (!$fh) {
    $msg = 'BACKUP FAILED: Could not open file for writing: ' . $filename;
    echo $msg . "\n";
    mail(DOJO_EMAIL, '[Karate Portal] BACKUP FAILED — ' . date('j M Y'), $msg, 'From: ' . DOJO_EMAIL);
    exit(1);
}

try {
    fwrite($fh, "-- ============================================================\n");
    fwrite($fh, "-- Database backup: {$dbname}\n");
    fwrite($fh, "-- Generated: " . date('D j M Y g:i a T') . "\n");
    fwrite($fh, "-- Pure PHP export (read-only)\n");
    fwrite($fh, "-- ============================================================\n\n");
    fwrite($fh, "SET NAMES utf8mb4;\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $row_total = 0;

    foreach ($tables as $table) {
        $safe = '`' . str_replace('`', '``', $table) . '`';

        $row = $pdo->query("SHOW CREATE TABLE {$safe}")->fetch(PDO::FETCH_NUM);
        fwrite($fh, "-- Table: {$table}\n");
        fwrite($fh, "DROP TABLE IF EXISTS {$safe};\n");
        fwrite($fh, $row[1] . ";\n\n");

        $stmt  = $pdo->query("SELECT * FROM {$safe}");
        $first = true;
        $cols  = null;

        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($first) {
                $cols  = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($data)));
                $first = false;
            }
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($data));
            fwrite($fh, "INSERT INTO {$safe} ({$cols}) VALUES (" . implode(', ', $vals) . ");\n");
            $row_total++;
        }
        fwrite($fh, "\n");
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fwrite($fh, "-- End of backup\n");
    fclose($fh);

} catch (Exception $e) {
    fclose($fh);
    @unlink($filename);
    $msg = 'BACKUP FAILED: ' . $e->getMessage();
    echo $msg . "\n";
    mail(DOJO_EMAIL, '[Karate Portal] BACKUP FAILED — ' . date('j M Y'), $msg, 'From: ' . DOJO_EMAIL);
    exit(1);
}

// ── Prune old backups ─────────────────────────────────────────────────────────
$files = glob(BACKUP_DIR . 'karate_*.sql');
$pruned = 0;
if ($files) {
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($files, KEEP_BACKUPS) as $old) {
        unlink($old);
        $pruned++;
    }
}

// ── Report — StackCP emails this output ──────────────────────────────────────
$size_kb = round(filesize($filename) / 1024, 1);
$tables_count = count($tables);
echo "Backup complete: {$filename}\n";
echo "Tables: {$tables_count} | Rows: {$row_total} | Size: {$size_kb} KB\n";
if ($pruned > 0) echo "Pruned {$pruned} old backup(s). Keeping last " . KEEP_BACKUPS . ".\n";
echo "Time: " . date('D j M Y g:i a T') . "\n";
