<?php

use PHPUnit\Framework\TestCase;

/**
 * api_str/api_int/api_bool guard JSON bodies the same way post_str/post_int
 * guard form bodies: a non-scalar where a scalar is expected collapses to the
 * default instead of fataling in trim()/strlen().
 */
class ApiInputTest extends TestCase
{
    public function test_api_str_returns_strings(): void
    {
        $this->assertSame('hello', api_str(['k' => 'hello'], 'k'));
    }

    public function test_api_str_casts_numbers(): void
    {
        $this->assertSame('5', api_str(['k' => 5], 'k'));
        $this->assertSame('2.5', api_str(['k' => 2.5], 'k'));
    }

    public function test_api_str_collapses_non_scalars_to_default(): void
    {
        $this->assertSame('', api_str(['k' => ['nested']], 'k'));
        $this->assertSame('fb', api_str(['k' => ['nested']], 'k', 'fb'));
        $this->assertSame('fb', api_str([], 'k', 'fb'));
        $this->assertSame('fb', api_str(['k' => null], 'k', 'fb'));
        $this->assertSame('fb', api_str(['k' => true], 'k', 'fb'));
    }

    public function test_api_int_casts_scalars(): void
    {
        $this->assertSame(5, api_int(['k' => '5'], 'k'));
        $this->assertSame(5, api_int(['k' => 5.9], 'k'));
        $this->assertSame(0, api_int(['k' => 'abc'], 'k'));
    }

    public function test_api_int_collapses_non_scalars_to_default(): void
    {
        $this->assertSame(0, api_int(['k' => ['nested']], 'k'));
        $this->assertSame(-1, api_int(['k' => ['nested']], 'k', -1));
        $this->assertSame(-1, api_int([], 'k', -1));
    }

    public function test_api_bool(): void
    {
        $this->assertTrue(api_bool(['k' => true], 'k'));
        $this->assertTrue(api_bool(['k' => 1], 'k'));
        $this->assertFalse(api_bool(['k' => false], 'k'));
        $this->assertFalse(api_bool(['k' => 0], 'k'));
        $this->assertFalse(api_bool([], 'k'));
    }
}
