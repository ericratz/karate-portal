<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for fee_for_type() (paypal.php) — the only pure function in that
 * file. Everything else there makes real network calls to PayPal and can't
 * be reliably tested locally (see README "Cannot Test Locally").
 */
class PaypalFeeTest extends TestCase
{
    public function test_monthly_tuition_fee(): void
    {
        $this->assertSame(MONTHLY_FEE, fee_for_type('monthly_tuition'));
    }

    public function test_registration_fee(): void
    {
        $this->assertSame(REG_FEE, fee_for_type('registration'));
    }

    public function test_belt_test_fee(): void
    {
        $this->assertSame(TEST_FEE, fee_for_type('belt_test'));
    }

    public function test_slc_training_fee(): void
    {
        $this->assertSame(SLC_FEE, fee_for_type('slc_training'));
    }

    public function test_seminar_fee(): void
    {
        $this->assertSame(SEMINAR_FEE, fee_for_type('seminar'));
    }

    public function test_unknown_type_returns_zero(): void
    {
        $this->assertSame(0.0, fee_for_type('not_a_real_type'));
    }
}
