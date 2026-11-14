<?php
// LOCATION: tests/Unit/DecimalArithmeticTest.php — Phase B: exact signed add / subtract / compare / divide / round / percent.
// STATUS: NOT EXECUTED by its author (no PHP in the authoring environment). The same algorithms were fuzzed in a line-for-line Python port
// against Python's decimal module (6,000 randomised cases, 0 failures: verify/decimal_port_check.py). Run: php artisan test --filter=DecimalArithmeticTest
// The pre-existing tests/Unit/DecimalTest.php is unchanged and still covers norm/mul/roundHalfUp/mulHalfUp/toCents.

namespace Tests\Unit;

use App\Support\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DecimalArithmeticTest extends TestCase
{
    public function test_addition_and_subtraction_are_exact_where_floats_are_not(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2, 'sanity: binary floats really do drift');
        $this->assertSame('0.3', Decimal::add('0.1', '0.2'));
        $this->assertSame('0.01', Decimal::sub('1.00', '0.99'));
        $this->assertSame('0.00', Decimal::add('-0.05', '0.05'));
        $this->assertSame('-0.30', Decimal::sub('0.20', '0.50'));
        $this->assertSame('3.50', Decimal::add('1.5', '2.00'), 'the larger scale is kept; nothing is truncated');
        $this->assertSame('100000000000000000000.00', Decimal::add('99999999999999999999.99', '0.01'));
    }

    public function test_repeated_accumulation_does_not_drift(): void
    {
        $float = 0.0; $exact = '0.00';
        for ($i = 0; $i < 1000; $i++) { $float += 0.1; $exact = Decimal::add($exact, '0.10'); }
        $this->assertNotSame(100.0, $float, 'sanity: 1000 float additions of 0.1 miss 100');
        $this->assertSame('100.00', $exact);
        $this->assertTrue(Decimal::eq('100', Decimal::sum(array_fill(0, 1000, 0.1))), 'sum() of 1000 × 0.1 is exactly 100');
    }

    public function test_comparison_is_exact_and_scale_insensitive(): void
    {
        $this->assertSame(0, Decimal::cmp('1.10', '1.1'));
        $this->assertSame(0, Decimal::cmp('-0', '0'));
        $this->assertSame(-1, Decimal::cmp('4999.99', '5000'));
        $this->assertSame(0, Decimal::cmp('5000.00', '5000'));
        $this->assertSame(1, Decimal::cmp('5000.01', '5000'));
        $this->assertTrue(Decimal::lt('0.009', '0.01'));
        $this->assertTrue(Decimal::gte('100.00', '100'));
        $this->assertTrue(Decimal::isNegative('-0.01'));
        $this->assertFalse(Decimal::isNegative('0.00'));
        $this->assertSame('5', Decimal::max('5', '4.99'));
        $this->assertSame('4.99', Decimal::min('5', '4.99'));
    }

    /** The official rank thresholds are inclusive minimums: exactly the threshold qualifies, one cent less never does. */
    public function test_one_cent_around_every_official_rank_threshold(): void
    {
        foreach (['100', '1000', '5000', '10000', '20000', '50000'] as $min) {
            $this->assertTrue(Decimal::gte($min . '.00', $min), "$min.00 qualifies");
            $this->assertFalse(Decimal::gte(Decimal::sub($min, '0.01'), $min), "one cent below $min does not qualify");
            $this->assertTrue(Decimal::gte(Decimal::add($min, '0.01'), $min));
        }
    }

    public function test_division_rounds_half_up_and_keeps_the_sign(): void
    {
        $this->assertSame('0.33', Decimal::div('1', '3'));
        $this->assertSame('0.67', Decimal::div('2', '3'));
        $this->assertSame('-0.67', Decimal::div('-2', '3'));
        $this->assertSame('0.67', Decimal::div('-2', '-3'));
        $this->assertSame('0.13', Decimal::div('1', '8', 2), '0.125 rounds up');
        $this->assertSame('0.125', Decimal::div('1', '8', 3));
        $this->assertSame('250.00', Decimal::div('1000', '4'));
        $this->assertSame('0.00', Decimal::div('0', '7'));
        $this->assertSame('3', Decimal::div('10', '3', 0));
    }

    public function test_division_by_zero_is_refused_not_silently_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::div('1', '0.00');
    }

    public function test_rounding_is_half_up_away_from_zero_at_the_posting_stage(): void
    {
        $this->assertSame('1.01', Decimal::roundSigned('1.005'));
        $this->assertSame('-1.01', Decimal::roundSigned('-1.005'));
        $this->assertSame('1.00', Decimal::roundSigned('1.004'));
        $this->assertSame('0.00', Decimal::roundSigned('-0.004'), 'a negative that rounds to zero is not "-0.00"');
        $this->assertSame('2.00', Decimal::roundSigned('1.995'));
        $this->assertSame(Decimal::roundHalfUp('1234.565'), Decimal::roundSigned('1234.565'), 'identical to the existing helper for non-negative values');
    }

    public function test_percent_of_is_exact_and_posts_half_up(): void
    {
        $this->assertSame('25.00', Decimal::percentOf('1000', '2.5'));
        $this->assertSame('0.50', Decimal::percentOf('6.60', '7.5'), '6.60 × 7.5% = 0.495 exactly → 0.50');
        $this->assertSame('0.04', Decimal::percentOf('1.40', '2.5'), '0.035 → 0.04');
        $this->assertSame('0.50', Decimal::percentOf('33.33', '1.5'));
        $this->assertSame('0.00', Decimal::percentOf('100', '0'));
        $this->assertSame('1.01', Decimal::percentOf('40.20', '2.5'), '1.005 → 1.01');
    }

    public function test_very_large_and_very_small_valid_amounts(): void
    {
        $this->assertSame('0.01', Decimal::money('0.005'));
        $this->assertSame('0.00', Decimal::money('0.004999'));
        $this->assertSame('999999999999.99', Decimal::money('999999999999.99'));
        $this->assertSame('1000000000000.00', Decimal::add('999999999999.99', '0.01'));
        $this->assertSame('0.000001', Decimal::sub('0.000002', '0.000001'));
    }

    public function test_input_normalisation_never_throws_and_never_exposes_float_noise(): void
    {
        $this->assertSame('0.3', Decimal::fromInput(0.3), 'shortest round-trip form of the float');
        $this->assertSame('0.30', Decimal::money(0.30000000000000004), 'binary noise is removed at posting');
        $this->assertSame('50000000.01', Decimal::fromInput(50000000.01), 'a large float does not print its binary tail');
        $this->assertSame('100', Decimal::fromInput(100.0));
        $this->assertSame('0', Decimal::fromInput('abc'));
        $this->assertSame('0', Decimal::fromInput(null));
        $this->assertSame('0', Decimal::fromInput(''));
        $this->assertSame('1000', Decimal::fromInput('1e3'));
        $this->assertSame('-5.5', Decimal::fromInput('-5.50'));
        $this->assertSame('0.30', Decimal::money(0.1 + 0.2), 'float noise is removed at posting');
        $this->assertSame('1.01', Decimal::money('1.005'));
        $this->assertSame('250.00', Decimal::money(250));
    }

    public function test_the_original_non_negative_helpers_are_unchanged(): void
    {
        $this->assertSame('201.00000', Decimal::mul('300000', '0.00067'));
        $this->assertSame('1.01', Decimal::roundHalfUp('1.005'));
        $this->assertSame(10050, Decimal::toCents('100.50'));
        $this->assertSame('100.50', Decimal::fromCents(10050));
    }
}
