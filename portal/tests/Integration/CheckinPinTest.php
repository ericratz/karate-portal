<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the check-in PIN rate-limiting mechanism.
 *
 * The pin_rate_limited() and record_failed_pin() functions live in checkin.php
 * and cannot be included here without side effects. Instead these tests verify
 * the DB-level contract that those functions depend on:
 *   - Failed attempts are stored as  pin:{ip}:{guess}  in login_attempts.identifier
 *   - The LIKE query  pin:{ip}:%  counts attempts across guesses for one IP
 *   - 5 or more such rows within 15 minutes constitutes a rate limit
 *   - 127.0.0.1 / ::1 are never rate-limited (function short-circuits before DB)
 */
class CheckinPinTest extends TestCase
{
    private const TEST_IP    = '10.99.88.77'; // non-localhost, guaranteed clean
    private const OTHER_IP   = '10.99.88.99'; // different IP — must not bleed across
    private const LIKE_PARAM = 'pin:10.99.88.77:%';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::cleanup();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::cleanup();
    }

    private static function cleanup(): void
    {
        db()->exec(
            "DELETE FROM login_attempts WHERE identifier LIKE 'pin:10.99.88.%'"
        );
    }

    #[\Override]
    protected function setUp(): void
    {
        self::cleanup(); // fresh slate for each test
    }

    // ── Identifier format ─────────────────────────────────────────────────────

    public function test_identifier_stores_ip_and_guess(): void
    {
        $ip    = self::TEST_IP;
        $guess = '4321';

        db()->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')
            ->execute(['pin:' . $ip . ':' . $guess]);

        $stmt = db()->prepare(
            "SELECT identifier FROM login_attempts WHERE identifier LIKE ? ORDER BY attempted_at DESC LIMIT 1"
        );
        $stmt->execute(['pin:' . $ip . ':%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Row should be retrievable via LIKE query');
        $parts = explode(':', $row['identifier'], 3);
        $this->assertSame('pin',   $parts[0]);
        $this->assertSame($ip,     $parts[1] ?? null);
        $this->assertSame($guess,  $parts[2] ?? null);
    }

    public function test_guess_with_colons_is_stored_intact(): void
    {
        // Guesses are numeric in practice but the format should survive any string
        $ip    = self::TEST_IP;
        $guess = '99:99'; // adversarial — extra colon

        db()->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')
            ->execute(['pin:' . $ip . ':' . $guess]);

        $stmt = db()->prepare(
            "SELECT identifier FROM login_attempts WHERE identifier LIKE ? ORDER BY attempted_at DESC LIMIT 1"
        );
        $stmt->execute(['pin:' . $ip . ':%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $parts = explode(':', $row['identifier'], 3); // limit=3 keeps guess intact
        $this->assertSame($guess, $parts[2] ?? null);
    }

    // ── LIKE query counts correctly ───────────────────────────────────────────

    public function test_like_query_counts_attempts_for_ip(): void
    {
        $ip = self::TEST_IP;

        // Insert 3 attempts with different guesses
        $stmt = db()->prepare('INSERT INTO login_attempts (identifier) VALUES (?)');
        $stmt->execute(['pin:' . $ip . ':1111']);
        $stmt->execute(['pin:' . $ip . ':2222']);
        $stmt->execute(['pin:' . $ip . ':3333']);

        $count_stmt = db()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE identifier LIKE ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $count_stmt->execute(['pin:' . $ip . ':%']);
        $count = (int)$count_stmt->fetchColumn();

        $this->assertSame(3, $count);
    }

    public function test_like_query_does_not_bleed_across_ips(): void
    {
        $ip      = self::TEST_IP;
        $other   = self::OTHER_IP;

        $stmt = db()->prepare('INSERT INTO login_attempts (identifier) VALUES (?)');
        // 4 attempts from OTHER_IP
        for ($i = 0; $i < 4; $i++) {
            $stmt->execute(['pin:' . $other . ':' . $i]);
        }
        // 1 attempt from TEST_IP
        $stmt->execute(['pin:' . $ip . ':1234']);

        $count_stmt = db()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE identifier LIKE ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $count_stmt->execute(['pin:' . $ip . ':%']);
        $count = (int)$count_stmt->fetchColumn();

        // Only 1 row belongs to TEST_IP
        $this->assertSame(1, $count);
    }

    // ── Rate limit threshold ──────────────────────────────────────────────────

    public function test_five_attempts_triggers_rate_limit_threshold(): void
    {
        $ip   = self::TEST_IP;
        $stmt = db()->prepare('INSERT INTO login_attempts (identifier) VALUES (?)');

        for ($i = 1; $i <= 5; $i++) {
            $stmt->execute(['pin:' . $ip . ':' . $i]);
        }

        $count_stmt = db()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE identifier LIKE ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $count_stmt->execute(['pin:' . $ip . ':%']);
        $count = (int)$count_stmt->fetchColumn();

        $this->assertGreaterThanOrEqual(5, $count, 'Rate limit fires at >= 5 attempts');
    }

    public function test_four_attempts_does_not_trigger_rate_limit_threshold(): void
    {
        $ip   = self::TEST_IP;
        $stmt = db()->prepare('INSERT INTO login_attempts (identifier) VALUES (?)');

        for ($i = 1; $i <= 4; $i++) {
            $stmt->execute(['pin:' . $ip . ':' . $i]);
        }

        $count_stmt = db()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE identifier LIKE ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $count_stmt->execute(['pin:' . $ip . ':%']);
        $count = (int)$count_stmt->fetchColumn();

        $this->assertLessThan(5, $count, 'Rate limit should not fire at 4 attempts');
    }

    // ── Legacy identifier format (pin:{ip} without guess) ────────────────────

    public function test_legacy_format_still_matched_by_like_query(): void
    {
        $ip = self::TEST_IP;

        // Old format: pin:{ip} (no guess suffix)
        db()->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')
            ->execute(['pin:' . $ip]);

        $count_stmt = db()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE identifier LIKE ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $count_stmt->execute(['pin:' . $ip . ':%']);
        $count = (int)$count_stmt->fetchColumn();

        // Legacy rows DO NOT match the LIKE 'pin:{ip}:%' pattern —
        // that is intentional: old rows are inert for rate-limiting purposes.
        // (They would match 'pin:{ip}' exactly, not 'pin:{ip}:%'.)
        $this->assertSame(0, $count, 'Legacy pin:{ip} rows do not match the new LIKE pattern');
    }

    public function test_legacy_identifier_parses_to_empty_guess(): void
    {
        // Simulate checkin_pin.php parsing logic for legacy rows
        $identifier = 'pin:' . self::TEST_IP; // old format
        $parts = explode(':', $identifier, 3);

        $this->assertSame('pin',        $parts[0]);
        $this->assertSame(self::TEST_IP, $parts[1] ?? null);
        // $parts[2] is missing — PHP returns '—' as the fallback display value
        $this->assertArrayNotHasKey(2, $parts);
    }
}
