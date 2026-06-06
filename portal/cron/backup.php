<?php
// Weekly database backup — runs every Wednesday via cPanel cron
// Cron schedule: 0 2 * * 3   (2:00 AM every Wednesday)
// Command: php /home/sites/35b/0/049118ce4f/public_html/karate/portal/cron/backup.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

define('BACKUP_DIR',   '/home/sites/35b/0/049118ce4f/backups/karate/');
define('KEEP_BACKUPS', 8);  // keep 8 weeks of backups

date_default_timezone_set('America/Denver');

// Create backup directory if it doesn't exist (outside public_html for security)
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0750, true);
}

$filename  = BACKUP_DIR . 'karate_' . date('Y-m-d') . '.sql.gz';
$mysqldump = '/usr/bin/mysqldump';

$cmd = sprintf(
    '%s --host=%s --user=%s --password=%s --single-transaction --routines %s | gzip > %s 2>&1',
    escapeshellcmd($mysqldump),
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    escapeshellarg(DB_PASS),
    escapeshellarg(DB_NAME),
    escapeshellarg($filename)
);

exec($cmd, $output, $exit_code);

if ($exit_code !== 0 || !file_exists($filename)) {
    $detail = implode("\n", $output);
    mail(
        DOJO_EMAIL,
        '[Karate Portal] BACKUP FAILED — ' . date('Y-m-d'),
        "The weekly database backup failed.\n\nExit code: $exit_code\n\nOutput:\n$detail",
        'From: ' . DOJO_EMAIL
    );
    exit(1);
}

// Prune old backups — keep only the most recent KEEP_BACKUPS files
$files = glob(BACKUP_DIR . 'karate_*.sql.gz');
if ($files) {
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($files, KEEP_BACKUPS) as $old) {
        unlink($old);
    }
}
