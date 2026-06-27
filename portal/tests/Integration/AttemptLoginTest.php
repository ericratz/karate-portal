<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for attempt_login() and is_rate_limited().
 * Requires a running MySQL instance (reads credentials from .env).
 * Creates and tears down a dedicated test user — never touches real accounts.
 */
class AttemptLoginTest extends TestCase
{
    private const USER = 'phpunit_test_user';
    private const PASS = 'PHPUnit_Test_Pass_123!';

    public static function setUpBeforeClass(): void
    {
        self::cleanup(); // remove any leftover from a previous crashed run

        $hash = password_hash(self::PASS, PASSWORD_BCRYPT);
        db()->prepare(
            'INSERT INTO users (username, email, password_hash, is_admin, active) VALUES (?,?,?,0,1)'
        )->execute([self::USER, 'phpunit_test@localhost.invalid', $hash]);

        $uid = (int)db()->lastInsertId();
        db()->prepare(
            'INSERT INTO students (user_id, first_name, last_name, email, student_type, registration_date)
             VALUES (?,?,?,?,?,NOW())'
        )->execute([$uid, 'PHPUnit', 'Test', 'phpunit_student@localhost.invalid', 'student']);
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanup();
    }

    private static function cleanup(): void
    {
        $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([self::USER]);
        $uid = (int)$stmt->fetchColumn();
        if ($uid) {
            db()->prepare('DELETE FROM students WHERE user_id = ?')->execute([$uid]);
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        }
        db()->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([self::USER]);
    }

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR']    = '127.0.0.1'; // localhost is never rate-limited
    }

    // ── attempt_login ─────────────────────────────────────────────────────────

    public function test_valid_credentials_return_ok(): void
    {
        $result = attempt_login(self::USER, self::PASS);

        $this->assertSame('ok', $result);
        $this->assertArrayHasKey('user_id', $_SESSION);
        $this->assertSame(self::USER, $_SESSION['username']);
        $this->assertSame('student', $_SESSION['role']);
    }

    public function test_wrong_password_returns_invalid(): void
    {
        $result = attempt_login(self::USER, 'WrongPassword!');
        $this->assertSame('invalid', $result);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_unknown_username_returns_invalid(): void
    {
        $result = attempt_login('no_such_user_xyz_phpunit', 'anypassword');
        $this->assertSame('invalid', $result);
    }

    public function test_inactive_account_returns_inactive(): void
    {
        db()->prepare('UPDATE users SET active = 0 WHERE username = ?')
             ->execute([self::USER]);

        $result = attempt_login(self::USER, self::PASS);
        $this->assertSame('inactive', $result);

        db()->prepare('UPDATE users SET active = 1 WHERE username = ?')
             ->execute([self::USER]);
    }

    public function test_successful_login_sets_session_role(): void
    {
        attempt_login(self::USER, self::PASS);
        $this->assertSame('student', $_SESSION['role']);
    }

    // ── is_rate_limited ───────────────────────────────────────────────────────

    public function test_localhost_ipv4_never_rate_limited(): void
    {
        $this->assertFalse(is_rate_limited(self::USER, '127.0.0.1'));
    }

    public function test_localhost_ipv6_never_rate_limited(): void
    {
        $this->assertFalse(is_rate_limited(self::USER, '::1'));
    }
}
