<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for belt_next_rank() — reads the real `ranks` table,
 * so this can't be a pure unit test. Read-only, no cleanup needed.
 */
class BeltNextRankTest extends TestCase
{
    public function test_unranked_adult_starts_at_8th_kyu(): void
    {
        $next = belt_next_rank(null, null);
        $this->assertSame('8th Kyu', $next['kyu_dan']);
    }

    public function test_unranked_youth_starts_at_10th_kyu(): void
    {
        $dob  = (new DateTime('-10 years'))->format('Y-m-d');
        $next = belt_next_rank(null, $dob);
        $this->assertSame('10th Kyu', $next['kyu_dan']);
    }

    public function test_next_rank_after_8th_kyu_is_7th_kyu(): void
    {
        $next = belt_next_rank('8th Kyu', null);
        $this->assertSame('7th Kyu', $next['kyu_dan']);
        $this->assertSame('Orange Belt', $next['name']);
    }

    public function test_highest_rank_returns_null(): void
    {
        $this->assertNull(belt_next_rank('3rd Dan', null));
    }

    public function test_unknown_current_rank_returns_null(): void
    {
        $this->assertNull(belt_next_rank('not a real rank', null));
    }

    public function test_next_rank_includes_hw_and_test_urls(): void
    {
        $next = belt_next_rank('9th Kyu', null); // adult -> 8th Kyu
        $this->assertSame('8th Kyu', $next['kyu_dan']);
        $this->assertStringContainsString('HW-Adult-Test-Kyu-08.pdf', $next['hw_url']);
        $this->assertStringContainsString('Test-Kyu-08.pdf', $next['test_url']);
    }
}
