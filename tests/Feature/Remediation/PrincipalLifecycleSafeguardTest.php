<?php
// LOCATION: tests/Feature/Remediation/PrincipalLifecycleSafeguardTest.php   (NEW — 19 Nov)
// Safeguards that stop NEW legacy/target principal inconsistencies without changing treatment of any existing position.
// WRITTEN, NOT EXECUTED (no vendor/). Run: php artisan test --filter=PrincipalLifecycleSafeguardTest

namespace Tests\Feature\Remediation;

use App\Models\InvestmentAccount;
use App\Services\Ledger\PrincipalService;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Rank\FinancialTestCase;

class PrincipalLifecycleSafeguardTest extends FinancialTestCase
{
    use RefreshDatabase;

    public function test_default_allows_deposit_with_plan_approval_exactly_as_before(): void
    {
        $this->assertSame('allow', config('financial.legacy_deposit_with_plan'));
        $u = $this->investor();
        $d = $this->deposit($u, 100, $this->plan());
        $this->approveDeposit($d)->assertOk();
        $this->assertSame('approved', $d->fresh()->status);
    }

    public function test_block_mode_refuses_deposit_with_plan_and_changes_nothing(): void
    {
        config(['financial.legacy_deposit_with_plan' => 'block']);
        $u = $this->cash($this->investor(), 10);
        $d = $this->deposit($u, 100, $this->plan());
        $this->approveDeposit($d)->assertStatus(422);
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertSame(10.0, $this->balance($u));
        $this->assertSame(0, InvestmentAccount::where('user_id', $u->id)->count());
    }

    public function test_block_mode_still_approves_plain_deposits(): void
    {
        config(['financial.legacy_deposit_with_plan' => 'block']);
        $u = $this->investor();
        $d = $this->deposit($u, 50);
        $this->approveDeposit($d)->assertOk();
        $this->assertSame(50.0, $this->balance($u));
    }

    public function test_block_mode_does_not_apply_when_target_model_flags_are_on(): void
    {
        config(['financial.legacy_deposit_with_plan' => 'block']);
        $this->financialRulesOn();
        $u = $this->investor();
        $d = $this->deposit($u, 50, $this->plan());
        $this->assertStringNotContainsString('frozen by Management', (string) $this->approveDeposit($d)->getContent());
    }

    public function test_return_to_wallet_is_refused_for_a_legacy_dual_position_and_balance_is_unchanged(): void
    {
        $this->financialRulesOn();
        $u = $this->cash($this->investor(), 0);
        $dep = $this->deposit($u, 100, null, 'bank_transfer', 'approved');
        $pos = $this->invest($u, 100, 30, 'completed');
        DB::table('investment_accounts')->where('id', $pos->id)->update(['source_deposit_id' => $dep->id]);
        try {
            app(PrincipalService::class)->returnToWallet($pos->fresh(), 100, 'idem-legacy-dual-1', 'REF-LEGACY-DUAL-1', 'Evidence text long enough for the test', $this->fin);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('D1', $e->errors()['principal'][0]);
        }
        $this->assertSame(0.0, $this->balance($u));
        $this->assertSame(0, DB::table('principal_movements')->where('investment_id', $pos->id)->count());
    }

    public function test_funding_source_classification(): void
    {
        $u = $this->investor();
        $a = $this->invest($u, 10);
        $this->assertSame(FundingSource::WALLET_LEGACY, FundingSource::classify($a, false));
        $a->source_deposit_id = 5;
        $this->assertSame(FundingSource::LEGACY_DEPOSIT, FundingSource::classify($a, false));
        $this->assertSame(FundingSource::ALLOCATION, FundingSource::classify($a, true));
    }

    public function test_mode_reports_legacy_by_default_and_target_only_when_both_flags_on(): void
    {
        $this->assertSame('legacy', FundingSource::mode()['mode']);
        config(['financial.separate_deposit_approval' => true]);
        $this->assertSame('legacy', FundingSource::mode()['mode']);
        $this->financialRulesOn();
        $this->assertSame('target', FundingSource::mode()['mode']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSettings')]
    public function test_invalid_setting_fails_safe_to_allow_and_is_reported(mixed $bad): void
    {
        config(['financial.legacy_deposit_with_plan' => $bad]);
        $p = FundingSource::legacyDepositPolicy();
        $this->assertSame('allow', $p['value']);
        $this->assertFalse($p['valid']);
        $this->assertFalse(FundingSource::mode()['legacy_deposit_with_plan_config_valid']);
        $u = $this->investor();
        $d = $this->deposit($u, 100, $this->plan());
        $this->approveDeposit($d)->assertOk();                       // a typo must not freeze deposits
    }

    public static function invalidSettings(): array
    {
        return [['blok'], [''], [true], [1], [['block']], ['yes']];
    }

    public function test_setting_is_normalised_for_case_and_whitespace(): void
    {
        config(['financial.legacy_deposit_with_plan' => '  BLOCK ']);
        $this->assertSame(['value' => 'block', 'valid' => true, 'raw' => 'block'], FundingSource::legacyDepositPolicy());
    }

    public function test_freeze_affects_only_new_deposit_with_plan_approvals_not_already_approved_ones(): void
    {
        $u = $this->investor();
        $d = $this->deposit($u, 100, $this->plan());
        $this->approveDeposit($d)->assertOk();
        $posBefore = InvestmentAccount::where('user_id', $u->id)->count();
        $bal = $this->balance($u);
        config(['financial.legacy_deposit_with_plan' => 'block']);
        $this->approveDeposit($d)->assertStatus(422);                // already approved: refused for the normal reason, nothing changes
        $this->assertSame($posBefore, InvestmentAccount::where('user_id', $u->id)->count());
        $this->assertSame($bal, $this->balance($u));
    }

    public function test_freeze_helper_truth_table(): void
    {
        config(['financial.legacy_deposit_with_plan' => 'block', 'financial.separate_deposit_approval' => false]);
        $this->assertTrue(FundingSource::freezesLegacyDepositApproval(5));
        $this->assertFalse(FundingSource::freezesLegacyDepositApproval(null));     // plain deposit
        config(['financial.separate_deposit_approval' => true]);
        $this->assertFalse(FundingSource::freezesLegacyDepositApproval(5));        // target-model deposit-only workflow
        config(['financial.separate_deposit_approval' => false, 'financial.legacy_deposit_with_plan' => 'allow']);
        $this->assertFalse(FundingSource::freezesLegacyDepositApproval(5));
    }

    public function test_target_model_deposit_with_plan_creates_no_position_even_with_block(): void
    {
        config(['financial.legacy_deposit_with_plan' => 'block']);
        $this->financialRulesOn();
        $u = $this->cash($this->investor(), 0);
        $d = $this->deposit($u, 100, $this->plan());
        $this->approveDeposit($d);
        $this->assertSame(0, InvestmentAccount::where('user_id', $u->id)->count());   // funds stay wallet cash; no position, no overlap
    }

    public function test_principal_cannot_be_returned_twice_and_never_exceeds_the_position(): void
    {
        $this->financialRulesOn();
        $u = $this->cash($this->investor(), 0);
        $pos = $this->invest($u, 100, 30, 'completed');
        $svc = app(PrincipalService::class);
        $svc->release($pos, 'Cycle completed; principal eligible (test)', $this->fin);
        $a = $svc->returnToWallet($pos->fresh(), 100, 'idem-A', 'REF-RETURN-A-001', 'Evidence for the first return in this test', $this->fin);
        $again = $svc->returnToWallet($pos->fresh(), 100, 'idem-A', 'REF-RETURN-A-001', 'Evidence for the first return in this test', $this->fin);
        $this->assertSame($a->id, $again->id);                         // same idempotency key: original returned, no second credit
        $this->assertSame(100.0, $this->balance($u));
        try { $svc->returnToWallet($pos->fresh(), 100, 'idem-B', 'REF-RETURN-B-002', 'Evidence for a second attempt in this test', $this->fin); $this->fail('second return allowed'); }
        catch (ValidationException $e) { $this->assertStringContainsString('exceeds', $e->errors()['principal'][0]); }
        $this->assertSame(100.0, $this->balance($u));
    }
}
