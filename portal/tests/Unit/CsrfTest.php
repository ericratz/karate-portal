<?php

use PHPUnit\Framework\TestCase;

class CsrfTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $_SESSION   = [];
        $_POST      = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    // ── csrf_token ────────────────────────────────────────────────────────────

    public function test_csrf_token_is_64_char_hex(): void
    {
        $token = csrf_token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_csrf_token_is_stable_within_session(): void
    {
        $this->assertSame(csrf_token(), csrf_token());
    }

    public function test_csrf_token_differs_across_fresh_sessions(): void
    {
        $first = csrf_token();
        $_SESSION = []; // clear session → new token on next call
        $second = csrf_token();
        $this->assertNotSame($first, $second);
    }

    // ── csrf_input ────────────────────────────────────────────────────────────

    public function test_csrf_input_renders_hidden_field_with_token(): void
    {
        $token = csrf_token();
        $html  = csrf_input();

        $this->assertStringContainsString('<input type="hidden"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('value="' . htmlspecialchars($token) . '"', $html);
    }

    // ── verify_csrf ───────────────────────────────────────────────────────────

    public function test_verify_csrf_is_noop_on_get(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        verify_csrf(); // must not exit
        $this->assertTrue(true);
    }

    public function test_verify_csrf_passes_with_correct_post_token(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token']       = csrf_token();
        verify_csrf(); // must not exit
        $this->assertTrue(true);
    }

    public function test_verify_csrf_passes_with_correct_header_token(): void
    {
        $_SERVER['REQUEST_METHOD']        = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN']     = csrf_token();
        // No POST body token — should fall back to header
        verify_csrf();
        $this->assertTrue(true);
    }
}
