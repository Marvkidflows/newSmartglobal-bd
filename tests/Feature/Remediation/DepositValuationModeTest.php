<?php
// LOCATION: tests/Feature/Remediation/DepositValuationModeTest.php
// R6: the deposit USD valuation mode is explicit. Default = the ORIGINAL float calculation; 'exact_decimal' is opt-in pending Finance approval.
// STATUS: written, NOT executed by the author.
namespace Tests\Feature\Remediation;

use App\Services\DepositPaymentService;
use Tests\TestCase;

class DepositValuationModeTest extends TestCase
{
    protected function convert(string $paid, string $rate): float
    {
        $svc = app(DepositPaymentService::class);
        $m = new \ReflectionMethod($svc, 'convertUsd');
        $m->setAccessible(true);
        return $m->invoke($svc, $paid, $rate);
    }

    public function test_default_mode_is_the_legacy_float_calculation_exactly(): void
    {
        $this->assertSame('legacy_float', config('financial.deposit_valuation_mode'));
        foreach ([['68.10', '1.95'], ['96.46', '1.75'], ['17.74', '0.25'], ['1.00', '1.005'], ['100.50', '0.01'], ['1087364.50', '2.11']] as [$p, $r]) {
            $this->assertSame(round(round((float) $p, 2) * (float) $r, 2), $this->convert($p, $r), "$p × $r");
        }
    }

    public function test_exact_decimal_mode_is_half_up_on_the_exact_product(): void
    {
        config(['financial.deposit_valuation_mode' => 'exact_decimal']);
        $this->assertSame(132.80, $this->convert('68.10', '1.95'));        // 132.795 exactly → half-up
        $this->assertSame(168.81, $this->convert('96.46', '1.75'));
        $this->assertSame(4.44,   $this->convert('17.74', '0.25'));
        $this->assertSame(1.01,   $this->convert('1.00', '1.005'));
        $this->assertSame(0.10,   $this->convert('10.05', '0.01'));        // 0.1005 → 0.10
    }
}
