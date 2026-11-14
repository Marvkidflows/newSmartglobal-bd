<?php
// LOCATION: tests/Feature/Remediation/ManagementDefaultsTest.php
// The documented safe defaults are in force, none claims Management approval, and a deviation is detected. STATUS: written, NOT executed.
namespace Tests\Feature\Remediation;

use App\Support\ManagementDefaults;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ManagementDefaultsTest extends TestCase
{
    public function test_every_default_is_in_force_with_a_clean_configuration(): void
    {
        $this->assertSame(0, Artisan::call('ssi:defaults-report', ['--check' => true]), Artisan::output());
        $rows = ManagementDefaults::register();
        $this->assertCount(12, $rows);
        foreach ($rows as $r) $this->assertTrue($r['ok'], "default #{$r['id']} ({$r['topic']}) differs: {$r['effective']}");
    }

    public function test_the_register_never_claims_management_approval(): void
    {
        $this->assertSame('NOT APPROVED BY MANAGEMENT', ManagementDefaults::APPROVAL);
        $this->assertStringNotContainsStringIgnoringCase('approved by management', str_replace('NOT APPROVED BY MANAGEMENT', '', json_encode(ManagementDefaults::register())));
        Artisan::call('ssi:defaults-report');
        $this->assertStringContainsString('is NOT an approval', Artisan::output());
        foreach (ManagementDefaults::register() as $r) {
            $this->assertContains($r['classification'], ['preserved_existing_rule', 'temporary_technical_safeguard', 'documented_risk']);
            $this->assertContains($r['confirmation'], ['required', 'optional']);
        }
    }

    public function test_the_concrete_values_match_the_instruction(): void
    {
        $this->assertSame(24, config('financial_controls.balance_cumulative_window_hours'));                                   // 1
        $this->assertSame('', (string) config('financial_controls.balance_cumulative_threshold_usd'));                          // 1: not invented
        $this->assertSame('', (string) config('financial_controls.profit_percentage_cap'));                                     // 2: no new limit
        $this->assertSame(['admin'], config('financial_controls.approver_roles.profit_adjust'));                                // 3
        $this->assertSame(['admin'], config('financial_controls.approver_roles.profit_reverse'));
        $this->assertFalse((bool) config('financial.separate_deposit_approval'));                                               // 4
        $this->assertSame('legacy_float', config('financial.deposit_valuation_mode'));                                          // 5
        $this->assertFalse((bool) config('privacy.email_external_images'));                                                     // 6
        $this->assertSame('', (string) config('financial_controls.approval_expiry_hours'));                                     // 8
        $this->assertTrue((bool) config('financial_controls.require_approval.investment_correction'));                          // 9
        $this->assertSame(['active'], \App\Support\StaffAccountGuard::ALLOWED_STATUSES);                                        // 12
    }

    public function test_cumulative_limit_is_disabled_when_nothing_is_configured_and_never_invented(): void
    {
        $svc = app(\App\Services\Financial\BalanceAdjustmentService::class);
        config(['financial_controls.balance_threshold_usd' => '', 'financial_controls.balance_cumulative_threshold_usd' => '']);
        $this->assertNull($svc->cumulativeLimit());                                                       // item 1: disabled, no value invented
        config(['financial_controls.balance_threshold_usd' => '10000.00']);
        $this->assertSame('10000.00', $svc->cumulativeLimit());                                           // existing conservative threshold reused
        config(['financial_controls.balance_cumulative_threshold_usd' => '25000']);
        $this->assertSame('25000.00', $svc->cumulativeLimit());                                           // explicit configuration wins
    }

    public function test_a_deviation_is_detected_and_fails_the_check(): void
    {
        config(['financial.deposit_valuation_mode' => 'exact_decimal', 'privacy.email_external_images' => true]);
        $this->assertSame(1, Artisan::call('ssi:defaults-report', ['--check' => true]));
        $json = json_decode((function () { Artisan::call('ssi:defaults-report', ['--json' => true]); return Artisan::output(); })(), true);
        $this->assertContains(5, $json['deviations']);
        $this->assertContains(6, $json['deviations']);
    }
}
