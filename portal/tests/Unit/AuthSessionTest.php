<?php

use PHPUnit\Framework\TestCase;

class AuthSessionTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    // ── has_role ──────────────────────────────────────────────────────────────

    public function test_has_role_matches_single_role(): void
    {
        $_SESSION['role'] = 'admin';
        $this->assertTrue(has_role('admin'));
    }

    public function test_has_role_no_match(): void
    {
        $_SESSION['role'] = 'student';
        $this->assertFalse(has_role('admin'));
    }

    public function test_has_role_matches_any_of_multiple_roles(): void
    {
        $_SESSION['role'] = 'instructor';
        $this->assertTrue(has_role('admin', 'instructor'));
        $this->assertFalse(has_role('admin', 'student'));
    }

    public function test_has_role_returns_false_with_no_session(): void
    {
        $this->assertFalse(has_role('admin'));
    }

    // ── current_user_id ───────────────────────────────────────────────────────

    public function test_current_user_id_returns_cast_int(): void
    {
        $_SESSION['user_id'] = '42';
        $this->assertSame(42, current_user_id());
    }

    public function test_current_user_id_returns_null_when_not_set(): void
    {
        $this->assertNull(current_user_id());
    }

    // ── dashboard_url ─────────────────────────────────────────────────────────

    public function test_dashboard_url_admin(): void
    {
        $this->assertStringEndsWith('/admin/', dashboard_url('admin'));
    }

    public function test_dashboard_url_instructor(): void
    {
        $this->assertStringEndsWith('/instructor/', dashboard_url('instructor'));
    }

    public function test_dashboard_url_parent(): void
    {
        $this->assertStringEndsWith('/parent/', dashboard_url('parent'));
    }

    public function test_dashboard_url_student(): void
    {
        $this->assertStringEndsWith('/student/', dashboard_url('student'));
    }

    public function test_dashboard_url_guest_falls_back_to_student(): void
    {
        $this->assertStringEndsWith('/student/', dashboard_url('guest'));
    }

    public function test_dashboard_url_unknown_role_falls_back_to_student(): void
    {
        $this->assertStringEndsWith('/student/', dashboard_url('something_unknown'));
    }
}
