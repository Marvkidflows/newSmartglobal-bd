<?php
// LOCATION: tests/Unit/DecimalTest.php — exact decimal posting (Financial Team §4D: full internal precision, half-up at final posting).
// STATUS: NOT EXECUTED by its author (no PHP available). The algorithm was cross-checked in a line-for-line Python port against Python's decimal module.

namespace Tests\Unit;

use App\Support\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_the_product_is_exact_with_no_float_error(): void
    {
        $this->assertSame('201.00000', Decimal::mul('300000', '0.00067'));
        $this->assertSame('0.000001', Decimal::mul('0.001', '0.001'));
        $this->assertSame('0.3', Decimal::norm(0.1 + 0.2), 'a float sum is normalised to 12 decimals, not shown as 0.30000000000000004');
    }

    public function test_half_up_rounds_the_midpoint_up_at_final_posting(): void
    {
        $this->assertSame('1.01', Decimal::roundHalfUp('1.005', 2));
        $this->assertSame('0.01', Decimal::roundHalfUp('0.005', 2));
        $this->assertSame('1000.00', Decimal::roundHalfUp('999.995', 2));
        $this->assertSame('100.00', Decimal::roundHalfUp('99.995', 2));
    }

    public function test_below_the_midpoint_rounds_down_and_exact_values_are_untouched(): void
    {
        $this->assertSame('1.00', Decimal::roundHalfUp('1.004999', 2));
        $this->assertSame('0.00', Decimal::roundHalfUp('0.004', 2));
        $this->assertSame('7.00', Decimal::roundHalfUp('7', 2));
        $this->assertSame('12.30', Decimal::roundHalfUp('12.3', 2));
    }

    public function test_amount_times_rate_is_rounded_once_at_the_end(): void
    {
        $this->assertSame('0.25', Decimal::mulHalfUp('2', '0.125'));
        $this->assertSame('0.98', Decimal::mulHalfUp('1500.5', '0.00065'));
        $this->assertSame('0.00', Decimal::mulHalfUp('0', '5.5'));
        $this->assertSame('1.01', Decimal::mulHalfUp('100.5', '0.01'));
    }

    public function test_cents_round_trip_exactly(): void
    {
        $this->assertSame(12345, Decimal::toCents('123.45'));
        $this->assertSame('123.45', Decimal::fromCents(12345));
        $this->assertSame(100, Decimal::toCents('0.995'));
    }

    public function test_floats_are_normalised_without_exponent_notation(): void
    {
        $this->assertSame('0.00067', Decimal::norm(0.00067));
        $this->assertSame('1500.5', Decimal::norm(1500.5));
        $this->assertSame('0', Decimal::norm(0.0));
    }

    public function test_invalid_input_is_rejected_rather_than_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::mul('abc', '2');
    }
}
