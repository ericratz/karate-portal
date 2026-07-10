<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for mask_email() (registration.php).
 * find_matches() is DB-backed — see Integration/FindMatchesTest.php.
 */
class RegistrationHelpersTest extends TestCase
{
    public function test_mask_email_keeps_first_char_and_domain(): void
    {
        $this->assertSame('j***@doe.com', mask_email('john@doe.com'));
    }

    public function test_mask_email_single_char_local_part(): void
    {
        $this->assertSame('a***@b.com', mask_email('a@b.com'));
    }

    public function test_mask_email_without_at_sign_returns_placeholder(): void
    {
        $this->assertSame('***', mask_email('not-an-email'));
    }

    public function test_mask_email_preserves_domain_case(): void
    {
        $this->assertSame('j***@Example.COM', mask_email('john@Example.COM'));
    }
}
