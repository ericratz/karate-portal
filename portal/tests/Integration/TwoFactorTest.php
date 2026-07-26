<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Two-factor authentication.
 *
 * The TOTP half is checked against the test vectors published in RFC 4226 and
 * RFC 6238, so the implementation is verified against the standard rather than
 * against itself — the point of hand-rolling it instead of taking a dependency.
 */
final class TwoFactorTest extends TestCase
{
    /** RFC 4226 Appendix D — the shared secret is the ASCII string below. */
    private const RFC4226_SECRET_ASCII = '12345678901234567890';
    private const FAKE_HASH = 'phpunit-not-a-real-hash';

    private int $uid = 0;

    #[\Override]
    protected function setUp(): void
    {
        $db = db();
        $db->prepare(
            'INSERT INTO users (username, password_hash, email, is_admin, active) VALUES (?,?,?,1,1)'
        )->execute(['phpunit_2fa', self::FAKE_HASH, 'phpunit_2fa@example.com']);
        $this->uid = (int)$db->lastInsertId();
        $_COOKIE = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        // ON DELETE CASCADE clears backup codes and trusted devices with it.
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$this->uid]);
        $_COOKIE = [];
    }

    private function rfcSecretBase32(): string
    {
        return base32_encode_raw(self::RFC4226_SECRET_ASCII);
    }

    // ── Base32 ────────────────────────────────────────────────────────────

    public function testBase32RoundTrips(): void
    {
        foreach (['', 'a', 'ab', 'abc', 'abcd', 'abcde', self::RFC4226_SECRET_ASCII] as $raw) {
            self::assertSame($raw, base32_decode_raw(base32_encode_raw($raw)), "round trip: '$raw'");
        }
    }

    /** RFC 4648 §10 test vectors. */
    public function testBase32MatchesRfc4648Vectors(): void
    {
        // Unpadded, which is what authenticator apps expect.
        self::assertSame('MY',           base32_encode_raw('f'));
        self::assertSame('MZXQ',         base32_encode_raw('fo'));
        self::assertSame('MZXW6',        base32_encode_raw('foo'));
        self::assertSame('MZXW6YQ',      base32_encode_raw('foob'));
        self::assertSame('MZXW6YTB',     base32_encode_raw('fooba'));
        self::assertSame('MZXW6YTBOI',   base32_encode_raw('foobar'));
    }

    public function testBase32DecodeRejectsGarbage(): void
    {
        // '1' and '8' are not in the base32 alphabet.
        self::assertSame('', base32_decode_raw('1'));
        self::assertSame('', base32_decode_raw('8'));
    }

    // ── HOTP: RFC 4226 Appendix D ─────────────────────────────────────────

    public function testHotpMatchesRfc4226Vectors(): void
    {
        $expected = [
            0 => '755224', 1 => '287082', 2 => '359152', 3 => '969429', 4 => '338314',
            5 => '254676', 6 => '287922', 7 => '162583', 8 => '399871', 9 => '520489',
        ];
        $secret = $this->rfcSecretBase32();
        foreach ($expected as $counter => $code) {
            self::assertSame($code, hotp_code($secret, $counter), "HOTP counter $counter");
        }
    }

    // ── TOTP: RFC 6238 Appendix B ─────────────────────────────────────────

    public function testTotpMatchesRfc6238Vectors(): void
    {
        // The RFC's SHA-1 rows. It prints 8 digits; the app uses 6, but the
        // truncation is the same operation, so 8 exercises it more strictly.
        $expected = [
            59          => '94287082',
            1111111109  => '07081804',
            1111111111  => '14050471',
            1234567890  => '89005924',
            2000000000  => '69279037',
            20000000000 => '65353130',
        ];
        $secret = $this->rfcSecretBase32();
        foreach ($expected as $time => $code) {
            self::assertSame($code, totp_code($secret, $time, 8), "TOTP at t=$time");
        }
    }

    public function testTotpCounterAdvancesEveryThirtySeconds(): void
    {
        self::assertSame(0, totp_counter(0));
        self::assertSame(0, totp_counter(29));
        self::assertSame(1, totp_counter(30));
        self::assertSame(1, totp_counter(59));
        self::assertSame(2, totp_counter(60));
    }

    // ── Verification window ───────────────────────────────────────────────

    public function testVerifyAcceptsCurrentCodeAndReturnsItsCounter(): void
    {
        $secret = totp_new_secret();
        $now    = 1_700_000_000;
        $code   = totp_code($secret, $now);

        self::assertSame(totp_counter($now), totp_verify($secret, $code, 1, $now));
    }

    public function testVerifyToleratesOneStepOfDriftEitherWay(): void
    {
        $secret = totp_new_secret();
        $now    = 1_700_000_000;

        // A phone 30s behind, and one 30s ahead, both still work.
        self::assertNotNull(totp_verify($secret, totp_code($secret, $now - 30), 1, $now));
        self::assertNotNull(totp_verify($secret, totp_code($secret, $now + 30), 1, $now));
    }

    public function testVerifyRejectsCodesOutsideTheWindow(): void
    {
        $secret = totp_new_secret();
        $now    = 1_700_000_000;

        self::assertNull(totp_verify($secret, totp_code($secret, $now - 120), 1, $now));
        self::assertNull(totp_verify($secret, totp_code($secret, $now + 120), 1, $now));
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $secret = totp_new_secret();
        foreach (['', '12345', '1234567', 'abcdef', '  '] as $bad) {
            self::assertNull(totp_verify($secret, $bad), "should reject '$bad'");
        }
    }

    public function testGeneratedSecretsAreDistinctAndDecodeTo160Bits(): void
    {
        $a = totp_new_secret();
        $b = totp_new_secret();
        self::assertNotSame($a, $b);
        self::assertSame(20, strlen(base32_decode_raw($a)));
    }

    // ── otpauth:// URI ────────────────────────────────────────────────────

    public function testUriCarriesTheSecretAndIssuer(): void
    {
        $secret = totp_new_secret();
        $uri    = totp_uri($secret, 'Noji', 'Shotokan Karate');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=' . $secret, $uri);
        self::assertStringContainsString('issuer=Shotokan%20Karate', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    public function testSecretIsDisplayedInReadableGroups(): void
    {
        self::assertSame('ABCD EFGH IJKL', totp_secret_display('ABCDEFGHIJKL'));
    }

    // ── Enrolment state ───────────────────────────────────────────────────

    public function testTwoFactorIsOffUntilEnabled(): void
    {
        self::assertFalse(twofa_enabled($this->uid));
        self::assertSame('', twofa_secret($this->uid));
    }

    public function testEnableThenDisableClearsEverything(): void
    {
        $secret = totp_new_secret();
        twofa_enable($this->uid, $secret);
        self::assertTrue(twofa_enabled($this->uid));
        self::assertSame($secret, twofa_secret($this->uid));

        twofa_generate_backup_codes($this->uid);
        twofa_trust_this_device($this->uid);

        twofa_disable($this->uid);
        self::assertFalse(twofa_enabled($this->uid));
        self::assertSame(0, twofa_backup_codes_remaining($this->uid));
        self::assertCount(0, twofa_list_devices($this->uid));
    }

    // ── Who gets challenged ───────────────────────────────────────────────

    public function testNonAdminsAreNeverChallenged(): void
    {
        twofa_enable($this->uid, totp_new_secret());
        // Enrolled, but not an admin — the policy is admin-only.
        self::assertFalse(twofa_challenge_required($this->uid, false));
    }

    public function testAdminWithoutEnrolmentIsNotChallenged(): void
    {
        self::assertFalse(twofa_challenge_required($this->uid, true));
    }

    public function testEnrolledAdminIsChallengedOnAnUntrustedBrowser(): void
    {
        twofa_enable($this->uid, totp_new_secret());
        self::assertTrue(twofa_challenge_required($this->uid, true));
    }

    // ── Trusted devices ───────────────────────────────────────────────────

    /** Mirror what the browser would send back, since setcookie() is a no-op in CLI. */
    private function presentCookieForNewestDevice(string $validatorPlain, string $selector): void
    {
        $_COOKIE[TWOFA_COOKIE] = $selector . ':' . $validatorPlain;
    }

    public function testTrustedDeviceSkipsTheChallenge(): void
    {
        twofa_enable($this->uid, totp_new_secret());
        twofa_trust_this_device($this->uid);

        // Re-derive what the cookie would have been: the selector is stored, the
        // validator is not, so read the selector and re-issue with a known pair.
        $rows = db()->query('SELECT selector FROM trusted_devices ORDER BY id DESC LIMIT 1')->fetchAll();
        self::assertCount(1, $rows);

        // Replace the row with a validator we know, to stand in for the cookie
        // the browser would be holding.
        $validator = bin2hex(random_bytes(32));
        db()->prepare('UPDATE trusted_devices SET validator_hash = ? WHERE selector = ?')
            ->execute([hash('sha256', $validator), $rows[0]['selector']]);
        $this->presentCookieForNewestDevice($validator, (string)$rows[0]['selector']);

        self::assertTrue(twofa_device_is_trusted($this->uid));
        self::assertFalse(twofa_challenge_required($this->uid, true));
    }

    public function testWrongValidatorIsRejectedAndBurnsTheRecord(): void
    {
        twofa_enable($this->uid, totp_new_secret());
        twofa_trust_this_device($this->uid);
        $sel = (string)db()->query('SELECT selector FROM trusted_devices ORDER BY id DESC LIMIT 1')->fetchColumn();

        $_COOKIE[TWOFA_COOKIE] = $sel . ':' . bin2hex(random_bytes(32));
        self::assertFalse(twofa_device_is_trusted($this->uid));

        // The record is destroyed, so a guessing attempt cannot be retried
        // against the same selector.
        self::assertCount(0, twofa_list_devices($this->uid));
    }

    public function testMalformedDeviceCookieIsIgnored(): void
    {
        twofa_enable($this->uid, totp_new_secret());
        foreach (['', 'nocolon', 'a:b:c', ':', 'x:'] as $bad) {
            $_COOKIE[TWOFA_COOKIE] = $bad;
            self::assertFalse(twofa_device_is_trusted($this->uid), "should ignore '$bad'");
        }
    }

    public function testExpiredDeviceDoesNotCount(): void
    {
        twofa_enable($this->uid, totp_new_secret());
        twofa_trust_this_device($this->uid);

        $sel       = (string)db()->query('SELECT selector FROM trusted_devices ORDER BY id DESC LIMIT 1')->fetchColumn();
        $validator = bin2hex(random_bytes(32));
        db()->prepare(
            'UPDATE trusted_devices SET validator_hash = ?, expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE selector = ?'
        )->execute([hash('sha256', $validator), $sel]);
        $_COOKIE[TWOFA_COOKIE] = $sel . ':' . $validator;

        self::assertFalse(twofa_device_is_trusted($this->uid));
    }

    public function testRevokeAllRemovesEveryDevice(): void
    {
        twofa_enable($this->uid, totp_new_secret());
        twofa_trust_this_device($this->uid);
        twofa_trust_this_device($this->uid);
        self::assertCount(2, twofa_list_devices($this->uid));

        self::assertSame(2, twofa_revoke_all_devices($this->uid));
        self::assertCount(0, twofa_list_devices($this->uid));
    }

    // ── Backup codes ──────────────────────────────────────────────────────

    public function testBackupCodesAreIssuedAndCounted(): void
    {
        $codes = twofa_generate_backup_codes($this->uid);
        self::assertCount(TWOFA_BACKUP_CODES, $codes);
        self::assertSame(TWOFA_BACKUP_CODES, twofa_backup_codes_remaining($this->uid));
        // Readable off paper: xxxxx-xxxxx
        foreach ($codes as $c) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{5}-[0-9a-f]{5}$/', $c);
        }
        self::assertSame(count($codes), count(array_unique($codes)));
    }

    public function testBackupCodeWorksExactlyOnce(): void
    {
        $codes = twofa_generate_backup_codes($this->uid);
        $code  = $codes[0];

        self::assertTrue(twofa_consume_backup_code($this->uid, $code));
        self::assertSame(TWOFA_BACKUP_CODES - 1, twofa_backup_codes_remaining($this->uid));

        // Replay must fail.
        self::assertFalse(twofa_consume_backup_code($this->uid, $code));
    }

    public function testBackupCodesAreNotStoredInPlaintext(): void
    {
        $codes = twofa_generate_backup_codes($this->uid);
        $q = db()->prepare('SELECT code_hash FROM user_backup_codes WHERE user_id = ?');
        $q->execute([$this->uid]);
        $hashes = $q->fetchAll(PDO::FETCH_COLUMN);

        foreach ($hashes as $h) {
            self::assertNotContains($h, $codes);
            self::assertStringStartsWith('$2y$', (string)$h);
        }
    }

    public function testRegeneratingInvalidatesTheOldSet(): void
    {
        $old = twofa_generate_backup_codes($this->uid);
        twofa_generate_backup_codes($this->uid);
        self::assertFalse(twofa_consume_backup_code($this->uid, $old[0]));
    }

    public function testUnknownBackupCodeIsRejected(): void
    {
        twofa_generate_backup_codes($this->uid);
        self::assertFalse(twofa_consume_backup_code($this->uid, 'aaaaa-bbbbb'));
        self::assertFalse(twofa_consume_backup_code($this->uid, ''));
    }

    // ── Replay protection ─────────────────────────────────────────────────

    public function testAcceptedCodeCannotBeUsedTwice(): void
    {
        $secret = totp_new_secret();
        twofa_enable($this->uid, $secret);
        $code = totp_code($secret);

        self::assertTrue(twofa_verify_totp($this->uid, $code));
        // Same code, still inside its own validity window — must now be refused,
        // otherwise a code read over a shoulder is reusable for ~90 seconds.
        self::assertFalse(twofa_verify_totp($this->uid, $code));
    }

    public function testVerifyFailsWhenTwoFactorIsNotEnabled(): void
    {
        self::assertFalse(twofa_verify_totp($this->uid, '123456'));
    }
}
