<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure helper functions in belt_helpers.php
 * (belt_next_rank is DB-backed — see Integration/BeltNextRankTest.php).
 */
class BeltHelpersTest extends TestCase
{
    // ── hw_index_url ──────────────────────────────────────────────────────────

    public function test_hw_index_url_null_dob_is_adult(): void
    {
        $this->assertSame(HW_INDEX_ADULT, hw_index_url(null));
    }

    public function test_hw_index_url_adult_dob(): void
    {
        $dob = (new DateTime('-20 years'))->format('Y-m-d');
        $this->assertSame(HW_INDEX_ADULT, hw_index_url($dob));
    }

    public function test_hw_index_url_youth_dob(): void
    {
        $dob = (new DateTime('-10 years'))->format('Y-m-d');
        $this->assertSame(HW_INDEX_YOUTH, hw_index_url($dob));
    }

    public function test_hw_index_url_boundary_just_under_16_is_youth(): void
    {
        $dob = (new DateTime('-15 years -11 months'))->format('Y-m-d');
        $this->assertSame(HW_INDEX_YOUTH, hw_index_url($dob));
    }

    public function test_hw_index_url_boundary_16_is_adult(): void
    {
        $dob = (new DateTime('-16 years'))->format('Y-m-d');
        $this->assertSame(HW_INDEX_ADULT, hw_index_url($dob));
    }

    // ── _belt_hw_file ─────────────────────────────────────────────────────────

    public function test_belt_hw_file_youth_specific_file(): void
    {
        $this->assertSame('HW-Youth-Test-Kyu-08.pdf', _belt_hw_file('8th Kyu', true));
    }

    public function test_belt_hw_file_adult_specific_file(): void
    {
        $this->assertSame('HW-Adult-Test-Kyu-08.pdf', _belt_hw_file('8th Kyu', false));
    }

    public function test_belt_hw_file_shared_file_across_age_groups(): void
    {
        $this->assertSame('HW-Test-Kyu-10.pdf', _belt_hw_file('10th Kyu', true));
        $this->assertSame('HW-Test-Kyu-10.pdf', _belt_hw_file('10th Kyu', false));
    }

    public function test_belt_hw_file_dan_rank_is_adult_only(): void
    {
        $this->assertNull(_belt_hw_file('1st Dan', true));
        $this->assertSame('HW-Test-Dan-01.pdf', _belt_hw_file('1st Dan', false));
    }

    public function test_belt_hw_file_unknown_rank_returns_null(): void
    {
        $this->assertNull(_belt_hw_file('99th Kyu', false));
    }

    // ── _belt_test_file ───────────────────────────────────────────────────────

    public function test_belt_test_file_known_rank(): void
    {
        $this->assertSame('Test-Kyu-07.pdf', _belt_test_file('7th Kyu'));
    }

    public function test_belt_test_file_dan_rank(): void
    {
        $this->assertSame('Test-Dan-02.pdf', _belt_test_file('2nd Dan'));
    }

    public function test_belt_test_file_unknown_rank_returns_null(): void
    {
        $this->assertNull(_belt_test_file('99th Kyu'));
    }
}
