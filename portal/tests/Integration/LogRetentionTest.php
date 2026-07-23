<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for apply_log_retention() (log_retention.php):
 *   error_log:    1 month retention
 *   email_log:    6 month retention
 *   activity_log: 6 month retention
 * Seeds rows explicitly on both sides of each cutoff, runs the sweep once,
 * and asserts only the old rows were removed.
 */
class LogRetentionTest extends TestCase
{
    private const CHANNEL = 'phpunit_retention';
    private const TYPE    = 'phpunit_retention';
    private const ACTION  = 'phpunit_retention';

    #[\Override]
    protected function tearDown(): void
    {
        db()->prepare('DELETE FROM error_log WHERE channel = ?')->execute([self::CHANNEL]);
        db()->prepare('DELETE FROM email_log WHERE type = ?')->execute([self::TYPE]);
        db()->prepare('DELETE FROM activity_log WHERE action = ?')->execute([self::ACTION]);
    }

    public function test_error_log_older_than_one_month_is_purged(): void
    {
        db()->prepare(
            "INSERT INTO error_log (logged_at, level, channel, message) VALUES (?, 'info', ?, 'old')"
        )->execute([date('Y-m-d H:i:s', (int) strtotime('-2 months')), self::CHANNEL]);
        db()->prepare(
            "INSERT INTO error_log (logged_at, level, channel, message) VALUES (?, 'info', ?, 'new')"
        )->execute([date('Y-m-d H:i:s', (int) strtotime('-1 day')), self::CHANNEL]);

        apply_log_retention();

        $rows = db()->prepare('SELECT message FROM error_log WHERE channel = ?');
        $rows->execute([self::CHANNEL]);
        $messages = $rows->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['new'], $messages);
    }

    public function test_email_log_older_than_six_months_is_purged(): void
    {
        db()->prepare(
            "INSERT INTO email_log (sent_at, to_email, subject, type, status) VALUES (?, 'x@test.invalid', 'old', ?, 'sent')"
        )->execute([date('Y-m-d H:i:s', (int) strtotime('-7 months')), self::TYPE]);
        db()->prepare(
            "INSERT INTO email_log (sent_at, to_email, subject, type, status) VALUES (?, 'x@test.invalid', 'new', ?, 'sent')"
        )->execute([date('Y-m-d H:i:s', (int) strtotime('-1 day')), self::TYPE]);

        apply_log_retention();

        $rows = db()->prepare('SELECT subject FROM email_log WHERE type = ?');
        $rows->execute([self::TYPE]);
        $subjects = $rows->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['new'], $subjects);
    }

    public function test_activity_log_older_than_six_months_is_purged(): void
    {
        db()->prepare(
            "INSERT INTO activity_log (action, created_at) VALUES (?, ?)"
        )->execute([self::ACTION, date('Y-m-d H:i:s', (int) strtotime('-7 months'))]);
        db()->prepare(
            "INSERT INTO activity_log (action, created_at) VALUES (?, ?)"
        )->execute([self::ACTION, date('Y-m-d H:i:s', (int) strtotime('-1 day'))]);

        apply_log_retention();

        $stmt = db()->prepare('SELECT COUNT(*) FROM activity_log WHERE action = ?');
        $stmt->execute([self::ACTION]);

        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function test_rows_just_inside_retention_window_are_kept(): void
    {
        // 3 weeks old — inside the 1-month error_log window, must survive.
        db()->prepare(
            "INSERT INTO error_log (logged_at, level, channel, message) VALUES (?, 'info', ?, 'recent')"
        )->execute([date('Y-m-d H:i:s', (int) strtotime('-3 weeks')), self::CHANNEL]);

        apply_log_retention();

        $stmt = db()->prepare('SELECT COUNT(*) FROM error_log WHERE channel = ?');
        $stmt->execute([self::CHANNEL]);

        $this->assertSame(1, (int)$stmt->fetchColumn());
    }
}
