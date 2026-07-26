<?php
// TOTP (RFC 6238) over HOTP (RFC 4226) — the time-based 6-digit codes that
// Google Authenticator, Authy, 1Password and friends generate.
//
// Hand-rolled rather than pulled in as a dependency: the whole algorithm is
// an HMAC and a truncation, it is fully specified, and both RFCs publish test
// vectors — which TwoFactorTest asserts against, so this is verified against
// the standard rather than against itself. Adding a Composer package would
// also mean re-uploading vendor/ by hand on a host with no Composer.
//
// No secrets are logged anywhere in this file.

declare(strict_types=1);

/** Alphabet for RFC 4648 base32 — what authenticator apps expect. */
const TOTP_B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** Encode raw bytes as unpadded base32. */
function base32_encode_raw(string $bytes): string {
    if ($bytes === '') return '';
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        // (int): bindec() is declared int|float — it returns float only past
        // PHP_INT_MAX, unreachable for a 5-bit chunk, but a float string offset
        // would be an error rather than a rounding surprise.
        $out  .= TOTP_B32_ALPHABET[(int)bindec($chunk)];
    }
    return $out;
}

/** Decode base32 back to raw bytes. Returns '' if the input is not valid base32. */
function base32_decode_raw(string $b32): string {
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
    if ($b32 === '') return '';
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $pos = strpos(TOTP_B32_ALPHABET, $b32[$i]);
        if ($pos === false) return '';
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        // A trailing partial group is base32 padding, not data — drop it.
        if (strlen($byte) === 8) $out .= chr((int)bindec($byte));
    }
    return $out;
}

/** A fresh 160-bit secret (the size RFC 4226 recommends), base32-encoded. */
function totp_new_secret(): string {
    return base32_encode_raw(random_bytes(20));
}

/**
 * HOTP — RFC 4226 §5.3. The counter is packed big-endian into 8 bytes, HMACed,
 * then dynamically truncated to `digits` decimal digits.
 *
 * @param string $secret base32
 */
function hotp_code(string $secret, int $counter, int $digits = 6, string $algo = 'sha1'): string {
    $key = base32_decode_raw($secret);
    if ($key === '') return '';

    // pack('J') is 64-bit big-endian, which is exactly the RFC's counter format.
    $hash = hash_hmac($algo, pack('J', $counter), $key, true);

    // Dynamic truncation: the low nibble of the last byte picks the offset.
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $binary = ((ord($hash[$offset])     & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            |  (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string)($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** The time step (counter) a timestamp falls in. RFC 6238 uses 30 seconds. */
function totp_counter(?int $timestamp = null, int $period = 30): int {
    return intdiv($timestamp ?? time(), $period);
}

/** The code for a given moment. */
function totp_code(string $secret, ?int $timestamp = null, int $digits = 6, string $algo = 'sha1', int $period = 30): string {
    return hotp_code($secret, totp_counter($timestamp, $period), $digits, $algo);
}

/**
 * Verify a submitted code, allowing for clock drift between the phone and the
 * server. Returns the matched counter (so the caller can reject a replay of the
 * same code), or null if nothing matched.
 *
 * $window = 1 checks the previous, current and next step — ±30s, the usual
 * tolerance. Comparison is hash_equals to keep it constant-time.
 */
function totp_verify(string $secret, string $code, int $window = 1, ?int $timestamp = null, int $digits = 6, string $algo = 'sha1', int $period = 30): ?int {
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== $digits) return null;

    $current = totp_counter($timestamp, $period);
    for ($i = -$window; $i <= $window; $i++) {
        $candidate = hotp_code($secret, $current + $i, $digits, $algo);
        if ($candidate !== '' && hash_equals($candidate, $code)) {
            return $current + $i;
        }
    }
    return null;
}

/**
 * The otpauth:// URI an authenticator app imports. Opening it on a phone hands
 * the secret straight to the app; on desktop the secret is typed in by hand.
 */
function totp_uri(string $secret, string $account, string $issuer): string {
    return 'otpauth://totp/'
        . rawurlencode($issuer) . ':' . rawurlencode($account)
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/** Group a secret into 4-character blocks so it can be typed without losing place. */
function totp_secret_display(string $secret): string {
    return trim(chunk_split($secret, 4, ' '));
}
