<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DB-write helpers: record_failed_login, audit, log_event.
 * Each test cleans up its own rows in tearDown.
 */
class DbWriteTest extends TestCase
{
    // Sentinel values that won't collide with real data
    private const FAIL_USER = 'phpunit_fail_user';
    private const FAIL_IP   = '192.0.2.1'; // TEST-NET — never a real client IP
    private const AUDIT_ACT = 'phpunit_test_audit';
    private const LOG_CHAN  = 'phpunit';

    protected function setUp(): void
    {
        $_SESSION = [
            'user_id'  => null,
            'username' => 'phpunit_dbwrite',
        ];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        db()->prepare('DELETE FROM login_attempts WHERE identifier IN (?,?)')
             ->execute([self::FAIL_USER, self::FAIL_IP]);
        db()->prepare("DELETE FROM activity_log WHERE action = ?")->execute([self::AUDIT_ACT]);
        db()->prepare("DELETE FROM error_log WHERE channel = ?")->execute([self::LOG_CHAN]);
    }

    // ── record_failed_login ───────────────────────────────────────────────────

    public function test_record_failed_login_inserts_username_and_ip(): void
    {
        record_failed_login(self::FAIL_USER, self::FAIL_IP);

        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier IN (?,?)'
        );
        $stmt->execute([self::FAIL_USER, self::FAIL_IP]);
        $this->assertSame(2, (int)$stmt->fetchColumn());
    }

    public function test_record_failed_login_is_idempotent_across_calls(): void
    {
        record_failed_login(self::FAIL_USER, self::FAIL_IP);
        record_failed_login(self::FAIL_USER, self::FAIL_IP);

        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier IN (?,?)'
        );
        $stmt->execute([self::FAIL_USER, self::FAIL_IP]);
        $this->assertSame(4, (int)$stmt->fetchColumn());
    }

    // ── audit ─────────────────────────────────────────────────────────────────

    public function test_audit_inserts_row_with_action(): void
    {
        audit(self::AUDIT_ACT, 'student', 99, 'phpunit detail');

        $stmt = db()->prepare(
            'SELECT action, target_type, target_id, detail
             FROM activity_log WHERE action = ? LIMIT 1'
        );
        $stmt->execute([self::AUDIT_ACT]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame(self::AUDIT_ACT, $row['action']);
        $this->assertSame('student', $row['target_type']);
        $this->assertSame(99, (int)$row['target_id']);
        $this->assertSame('phpunit detail', $row['detail']);
    }

    public function test_audit_records_session_username(): void
    {
        $_SESSION['username'] = 'phpunit_dbwrite';
        audit(self::AUDIT_ACT);

        $stmt = db()->prepare('SELECT username FROM activity_log WHERE action = ? LIMIT 1');
        $stmt->execute([self::AUDIT_ACT]);
        $this->assertSame('phpunit_dbwrite', $stmt->fetchColumn());
    }

    public function test_audit_accepts_null_target(): void
    {
        audit(self::AUDIT_ACT); // no target_type or target_id
        $stmt = db()->prepare('SELECT COUNT(*) FROM activity_log WHERE action = ?');
        $stmt->execute([self::AUDIT_ACT]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    // ── log_event ─────────────────────────────────────────────────────────────

    public function test_log_event_inserts_row(): void
    {
        log_event('warning', self::LOG_CHAN, 'test message', ['key' => 'val']);

        $stmt = db()->prepare(
            'SELECT level, channel, message, context FROM error_log WHERE channel = ? LIMIT 1'
        );
        $stmt->execute([self::LOG_CHAN]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame('warning', $row['level']);
        $this->assertSame(self::LOG_CHAN, $row['channel']);
        $this->assertSame('test message', $row['message']);
        $this->assertStringContainsString('"key"', $row['context']);
    }

    public function test_log_event_invalid_level_falls_back_to_info(): void
    {
        log_event('nonsense', self::LOG_CHAN, 'fallback test');

        $stmt = db()->prepare('SELECT level FROM error_log WHERE channel = ? LIMIT 1');
        $stmt->execute([self::LOG_CHAN]);
        $this->assertSame('info', $stmt->fetchColumn());
    }

    public function test_log_event_without_context_stores_null(): void
    {
        log_event('info', self::LOG_CHAN, 'no context');

        $stmt = db()->prepare('SELECT context FROM error_log WHERE channel = ? LIMIT 1');
        $stmt->execute([self::LOG_CHAN]);
        $this->assertNull($stmt->fetchColumn());
    }
}
