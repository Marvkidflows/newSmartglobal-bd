<?php
// Task 5 - pure fee arithmetic. STATUS: written, NOT executed by the author (no PHP in the authoring environment) - see docs/task5/TEST_EXECUTION_REPORT.md.
namespace Tests\Unit\Task5;

use App\Models\WithdrawalFeePolicy;
use App\Services\Task5\WithdrawalFeeCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WithdrawalFeeCalculatorTest extends TestCase
{
    private function policy(array $a = []): WithdrawalFeePolicy
    {
        $p = new WithdrawalFeePolicy();
        $p->forceFill($a + ['fee_type' => 'none', 'is_active' => true, 'policy_approved' => true, 'currency' => 'USD', 'effective_from' => now()->subDay()]);
        $p->id = 1;
        return $p;
    }
    private function calc(mixed $gross, ?WithdrawalFeePolicy $p): array { return (new WithdrawalFeeCalculator())->calculate($gross, $p); }

    public function test_fixed_fee_is_applied_once(): void
    {
        $r = $this->calc('100', $this->policy(['fee_type' => 'fixed', 'fixed_amount' => '5.00']));
        $this->assertSame(['100.00', '5.00', '95.00'], [$r['gross'], $r['fee'], $r['net']]);
        $this->assertTrue($r['applied']);
    }

    public function test_percentage_fee_uses_the_gross_amount_as_base(): void
    {
        $r = $this->calc('200.00', $this->policy(['fee_type' => 'percentage', 'percentage' => '2.5']));
        $this->assertSame(['200.00', '5.00', '195.00'], [$r['gross'], $r['fee'], $r['net']]);
    }

    public function test_combined_fee_is_fixed_once_plus_percentage_of_gross(): void
    {
        $r = $this->calc('100.00', $this->policy(['fee_type' => 'combined', 'fixed_amount' => '1.00', 'percentage' => '2']));
        $this->assertSame('3.00', $r['fee']);
        $this->assertSame('97.00', $r['net']);
    }

    public function test_rounding_is_half_up_on_the_exact_value_not_on_floats(): void
    {
        // 1% of 100.50 = 1.005 exactly -> 1.01 (a float implementation can give 1.00)
        $this->assertSame('1.01', $this->calc('100.50', $this->policy(['fee_type' => 'percentage', 'percentage' => '1']))['fee']);
        // 2.5% of 33.33 = 0.83325 -> 0.83
        $this->assertSame('0.83', $this->calc('33.33', $this->policy(['fee_type' => 'percentage', 'percentage' => '2.5']))['fee']);
        // combined rounds ONCE at the end: 0.10 + 0.005 (=0.5% of 1.00)... 0.105 -> 0.11
        $this->assertSame('0.11', $this->calc('1.00', $this->policy(['fee_type' => 'combined', 'fixed_amount' => '0.10', 'percentage' => '0.5']))['fee']);
    }

    public function test_gross_fee_net_always_reconcile_exactly(): void
    {
        foreach (['10', '10.01', '99.99', '1234.56', '0.10'] as $g) {
            $r = $this->calc($g, $this->policy(['fee_type' => 'percentage', 'percentage' => '1.75']));
            $this->assertSame($r['gross'], \App\Support\Decimal::add($r['fee'], $r['net']), "gross {$g}");
        }
    }

    public function test_no_policy_inactive_unapproved_none_or_future_policies_charge_nothing(): void
    {
        foreach ([null, $this->policy(['fee_type' => 'fixed', 'fixed_amount' => '5', 'is_active' => false]), $this->policy(['fee_type' => 'fixed', 'fixed_amount' => '5', 'policy_approved' => false]),
            $this->policy(['fee_type' => 'none']), $this->policy(['fee_type' => 'fixed', 'fixed_amount' => '5', 'effective_from' => now()->addDay()])] as $p) {
            $r = $this->calc('100', $p);
            $this->assertSame('0.00', $r['fee']);
            $this->assertSame('100.00', $r['net']);
            $this->assertFalse($r['applied']);
        }
    }

    public function test_min_and_max_fee_clamp_a_non_zero_fee(): void
    {
        $p = ['fee_type' => 'percentage', 'percentage' => '1', 'min_fee' => '2.00', 'max_fee' => '10.00'];
        $this->assertSame('2.00', $this->calc('50', $this->policy($p))['fee']);     // 0.50 -> min 2.00
        $this->assertSame('10.00', $this->calc('5000', $this->policy($p))['fee']);  // 50.00 -> max 10.00
        $this->assertSame('5.00', $this->calc('500', $this->policy($p))['fee']);
    }

    public function test_fee_may_not_equal_or_exceed_the_amount(): void
    {
        $this->expectException(ValidationException::class);
        $this->calc('5.00', $this->policy(['fee_type' => 'fixed', 'fixed_amount' => '5.00']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badAmounts')]
    public function test_invalid_amounts_are_refused(mixed $bad): void
    {
        $this->expectException(ValidationException::class);
        $this->calc($bad, $this->policy(['fee_type' => 'fixed', 'fixed_amount' => '1']));
    }
    public static function badAmounts(): array { return [['0'], ['-10'], [''], ['abc'], [null], [true], ['10.123'], ['1e2x']]; }

    // Regression for the array-union defect: a charged fee must be reported (and frozen) with its real type and parameters, not 'none'/null.
    public function test_a_charged_fee_reports_its_real_type_and_parameters(): void
    {
        $q = $this->calc('100.00', $this->policy(['fee_type' => 'combined', 'fixed_amount' => '1.00', 'percentage' => '2.5']));
        $this->assertSame(['combined', '1.00', '2.5'], [$q['fee_type'], $q['fixed_amount'], $q['percentage']]);
        $q = $this->calc('100.00', $this->policy(['fee_type' => 'fixed', 'fixed_amount' => '5.00']));
        $this->assertSame(['fixed', '5.00', null, '5.00', '95.00', true], [$q['fee_type'], $q['fixed_amount'], $q['percentage'], $q['fee'], $q['net'], $q['applied']]);
    }
}
