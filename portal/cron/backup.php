<?php
// Weekly database backup — runs every Sunday at 7:00 AM Utah.
// Cron schedule: 0 14 * * 0   (2:00 PM London = 7:00 AM Utah)
// Command: <php binary> <portal path>/cron/backup.php — see cron/CRON_SETUP.txt
//
// Pure PHP/PDO export — no mysqldump binary required.

// ── CLI only ──────────────────────────────────────────────────────────────
// This job runs unattended with no authentication check, so it must never be
// triggerable over HTTP. The root .htaccess blocks portal/cron/ — but that is
// webserver configuration, and it is silently inert anywhere AllowOverride is
// not set (as it was in the Docker dev/CI container until V5.0). This guard
// does not depend on the server being configured correctly.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script is CLI-only.\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

date_default_timezone_set('America/Denver');

define('KEEP_BACKUPS', 8);  // keep 8 weeks of backups

// ── Where backups are written ─────────────────────────────────────────────────
// Comes from .env (which is gitignored and deployed separately), not from this
// file. Two reasons: the destination is server-specific, so hardcoding it meant
// this script could only ever run on one machine; and it names a directory full
// of complete database dumps, which is not something to publish in source.
//
// There is deliberately NO fallback. A default would most likely land somewhere
// under the web root, quietly writing every dump to a fetchable path — failing
// loudly is the safer wrong answer.
if (!defined('BACKUP_DIR') || trim((string)BACKUP_DIR) === '') {
    $msg = 'BACKUP FAILED: BACKUP_DIR is not set. Add it to .env, pointing at a '
         . 'directory OUTSIDE the web root.';
    log_event('critical', 'cron', 'Backup failed: BACKUP_DIR not configured');
    echo $msg . "\n";
    mail(DOJO_EMAIL, '[Karate Portal] BACKUP FAILED — ' . date('j M Y'), $msg, 'From: ' . DOJO_EMAIL);
    exit(1);
}

// Normalise the trailing slash once. Everything below concatenates a filename
// straight onto this, so a value entered without one would write
// ".../backupskarate_2026-07-27.sql" into the PARENT directory — and the
// retention glob would then never match its own output, so old backups would
// pile up forever. Accepting either form removes that class of mistake.
$backup_dir = rtrim(trim((string)BACKUP_DIR), "/\\") . '/';

// ── Setup ─────────────────────────────────────────────────────────────────────
if (!is_dir($backup_dir)) {
    if (!mkdir($backup_dir, 0750, true)) {
        $msg = 'BACKUP FAILED: Could not create backup directory: ' . $backup_dir;
        log_event('critical', 'cron', 'Backup failed: cannot create directory', ['dir' => $backup_dir]);
        echo $msg . "\n";
        mail(DOJO_EMAIL, '[Karate Portal] BACKUP FAILED — ' . date('j M Y'), $msg, 'From: ' . DOJO_EMAIL);
        exit(1);
    }
}

$filename = $backup_dir . 'karate_' . date('Y-m-d') . '.sql';
$pdo      = db();
$dbname   = DB_NAME;

// ── Write SQL export ──────────────────────────────────────────────────────────
$fh = fopen($filename, 'w');
if (!$fh) {
    $msg = 'BACKUP FAILED: Could not open file for writing: ' . $filename;
    log_event('critical', 'cron', 'Backup failed: cannot open file', ['file' => $filename]);
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
        $safe = '`' . str_replace('`', '``', (string)$table) . '`';

        $row = $pdo->query("SHOW CREATE TABLE {$safe}")->fetch(PDO::FETCH_NUM);
        fwrite($fh, "-- Table: {$table}\n");
        fwrite($fh, "DROP TABLE IF EXISTS {$safe};\n");
        fwrite($fh, $row[1] . ";\n\n");

        $stmt  = $pdo->query("SELECT * FROM {$safe}");
        $first = true;
        $cols  = null;

        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($first) {
                $cols  = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', strval($c)) . '`', array_keys($data)));
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
    if (is_resource($fh)) fclose($fh);
    @unlink($filename);
    $msg = 'BACKUP FAILED: ' . $e->getMessage();
    log_event('error', 'cron', 'Backup failed: export error', ['message' => $e->getMessage()]);
    echo $msg . "\n";
    mail(DOJO_EMAIL, '[Karate Portal] BACKUP FAILED — ' . date('j M Y'), $msg, 'From: ' . DOJO_EMAIL);
    exit(1);
}

// ── Prune old backups ─────────────────────────────────────────────────────────
$files = glob($backup_dir . 'karate_*.sql');
$pruned = 0;
if ($files) {
    usort($files, fn($a, $b) => (int) filemtime($b) - (int) filemtime($a));
    foreach (array_slice($files, KEEP_BACKUPS) as $old) {
        unlink($old);
        $pruned++;
    }
}

// ── Report — StackCP emails this output ──────────────────────────────────────
$size_kb = round((int) filesize($filename) / 1024, 1);
$tables_count = count($tables);
log_event('info', 'cron', 'Backup completed', [
    'tables' => $tables_count, 'rows' => $row_total, 'size_kb' => $size_kb,
]);
echo "Backup complete: {$filename}\n";
echo "Tables: {$tables_count} | Rows: {$row_total} | Size: {$size_kb} KB\n";
if ($pruned > 0) echo "Pruned {$pruned} old backup(s). Keeping last " . KEEP_BACKUPS . ".\n";
echo "Time: " . date('D j M Y g:i a T') . "\n";
