<?php
// LOCATION: tests/Feature/Remediation/PolicyPreviewTest.php   (NEW — 19 Nov)
// Read-only policy impact previews (withdrawal fee, referral reward). WRITTEN, NOT EXECUTED. Run: php artisan test --filter=PolicyPreviewTest

namespace Tests\Feature\Remediation;

use App\Models\Deposit;
use App\Models\FinancialAuditLog;
use App\Models\InvestmentPlan;
use App\Models\ReferralPolicy;
use App\Models\WithdrawalFeePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Rank\FinancialTestCase;

class PolicyPreviewTest extends FinancialTestCase
{
    use RefreshDatabase;

    private function fee(array $b, $as = null)
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($as ?? $this->fin, 'sanctum')->postJson('/api/financial/withdrawal-fees/policy/preview', $b);
    }

    private function ref(array $b, $as = null)
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($as ?? $this->fin, 'sanctum')->postJson('/api/financial/referral-policy/preview', $b);
    }

    private function snapshot(): array
    {
        return [WithdrawalFeePolicy::count(), ReferralPolicy::count(), FinancialAuditLog::count(), DB::table('users')->get()->toJson(), DB::table('withdrawals')->count(), DB::table('balance_adjustments')->count()];
    }

    public function test_access_control(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/financial/withdrawal-fees/policy/preview', ['fee_type' => 'none'])->assertUnauthorized();
        $this->fee(['fee_type' => 'none'], $this->investor())->assertForbidden();
        $this->ref(['reward_amount' => 5], $this->investor())->assertForbidden();
        $this->fee(['fee_type' => 'none'])->assertOk();
        $this->ref(['reward_amount' => 5], $this->admin)->assertOk();
    }

    public function test_fee_preview_shows_current_proposed_differences_and_sample_fees(): void
    {
        $j = $this->fee(['fee_type' => 'percentage', 'percentage' => 2, 'min_fee' => 1, 'max_fee' => 50])->assertOk()->json();
        $this->assertTrue($j['valid']);
        $this->assertFalse($j['current']['charging_fees']);
        $this->assertContains('percentage', array_column($j['differences'], 'field'));
        $by = collect($j['impact']['samples'])->keyBy('withdrawal');
        $this->assertSame('0.00', $by['100.00']['current_fee']);
        $this->assertSame('2.00', $by['100.00']['proposed_fee']);
        $this->assertSame('1.00', $by['10.00']['proposed_fee']);      // minimum applied
        $this->assertSame('50.00', $by['5000.00']['proposed_fee']);   // maximum applied
        $this->assertFalse($j['approval']['approved']);
        $this->assertFalse($j['approval']['active']);
        $this->assertStringContainsString('DIFFERENT Administrator', $j['approval']['approver']);
    }

    public function test_fee_preview_reports_validation_failures_without_impact(): void
    {
        $j = $this->fee(['fee_type' => 'combined', 'percentage' => 2])->assertOk()->json();
        $this->assertFalse($j['valid']);
        $this->assertArrayHasKey('fixed_amount', $j['errors']);
        $this->assertSame([], $j['impact']['samples']);
        $k = $this->fee(['fee_type' => 'fixed', 'fixed_amount' => 5, 'min_fee' => 10, 'max_fee' => 1])->json();
        $this->assertArrayHasKey('min_fee', $k['errors']);
        $this->fee(['fee_type' => 'bogus'])->assertStatus(422);
        $this->fee(['fee_type' => 'percentage', 'percentage' => 101])->assertStatus(422);
    }

    public function test_fee_preview_warns_on_high_uncapped_percentages(): void
    {
        $w = implode(' ', $this->fee(['fee_type' => 'percentage', 'percentage' => 15])->json('warnings'));
        $this->assertStringContainsString('unusually high', $w);
        $this->assertStringContainsString('No maximum fee', $w);
    }

    public function test_fee_preview_recent_withdrawals_are_illustrative_and_unchanged(): void
    {
        $u = $this->cash($this->investor(), 500);
        $w = $this->withdrawal($u, 100);
        $before = DB::table('withdrawals')->get()->toJson();
        $j = $this->fee(['fee_type' => 'fixed', 'fixed_amount' => 3])->assertOk()->json();
        $this->assertSame(1, $j['impact']['recent']['withdrawals_considered']);
        $this->assertSame('3.00', $j['impact']['recent']['difference']);
        $this->assertSame($before, DB::table('withdrawals')->get()->toJson());
        $this->assertNull($w->fresh()->fee_amount);
    }

    public function test_previews_write_nothing(): void
    {
        $this->cash($this->investor(), 10);
        $before = $this->snapshot();
        $this->fee(['fee_type' => 'percentage', 'percentage' => 2, 'min_fee' => 1])->assertOk();
        $this->fee(['fee_type' => 'combined', 'percentage' => 2])->assertOk();
        $this->ref(['reward_amount' => 25])->assertOk();
        $this->ref(['reward_amount' => 0])->assertOk();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_referral_preview_difference_errors_and_warnings(): void
    {
        $this->assertFalse($this->ref(['reward_amount' => 0])->json('valid'));
        $this->assertArrayHasKey('reward_amount', $this->ref(['reward_amount' => 0])->json('errors'));
        $j = $this->ref(['reward_amount' => 25])->assertOk()->json();
        $this->assertTrue($j['valid']);
        $this->assertSame('reward_amount', $j['differences'][0]['field']);
        $this->assertSame(0, $j['impact']['rewards_created']);
        $this->assertSame('0.00', $j['impact']['same_count_at_proposed_amount']);
        $this->assertFalse($j['approval']['approved']);
        $this->ref(['reward_amount' => 'abc'])->assertStatus(422);
    }

    // ── FX / payment valuation dry run ───────────────────────────────────────────
    private function pay(Deposit $d, array $b, $as = null)
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($as ?? $this->fin, 'sanctum')->postJson("/api/financial/deposits/{$d->id}/payment-preview", $b);
    }

    public function test_fx_preview_shows_valuation_without_changing_anything(): void
    {
        $u = $this->cash($this->investor(), 0);
        $d = $this->deposit($u, 100);
        $before = [Deposit::find($d->id)->toJson(), DB::table('users')->get()->toJson(), FinancialAuditLog::count(), DB::table('balance_adjustments')->count()];
        $j = $this->pay($d, ['paid_amount' => 100, 'paid_currency' => 'EUR', 'rate' => '1.10', 'rate_date' => now()->toDateString()])->assertOk()->json();
        $this->assertTrue($j['valid']);
        $this->assertEquals(110.0, $j['impact']['computed_usd']);
        $this->assertEquals(10.0, $j['impact']['change_vs_requested_usd']);
        $this->assertFalse($j['impact']['would_need_amount_acknowledgement']);
        $this->assertSame($before, [Deposit::find($d->id)->toJson(), DB::table('users')->get()->toJson(), FinancialAuditLog::count(), DB::table('balance_adjustments')->count()]);
        $this->assertNull(Deposit::find($d->id)->fx_rate);
    }

    public function test_fx_preview_flags_an_approved_amount_beyond_tolerance_and_invalid_input(): void
    {
        $d = $this->deposit($this->investor(), 100);
        $j = $this->pay($d, ['paid_amount' => 100, 'paid_currency' => 'EUR', 'rate' => '1.10', 'rate_date' => now()->toDateString(), 'approved_amount' => 125])->json();
        $this->assertTrue($j['impact']['would_need_amount_acknowledgement']);
        $bad = $this->pay($d, ['paid_amount' => 100, 'paid_currency' => 'EUR'])->assertOk()->json();
        $this->assertFalse($bad['valid']);
        $this->assertArrayHasKey('rate', $bad['errors']);
        $this->assertNull($bad['impact']);
        $this->assertSame(100.0, (float) $this->pay($d, ['paid_amount' => 100, 'paid_currency' => 'USD'])->json('impact.computed_usd'));
    }

    public function test_fx_preview_roles_and_settled_deposits(): void
    {
        $d = $this->deposit($this->investor(), 100);
        $this->pay($d, ['paid_amount' => 1, 'paid_currency' => 'USD'], $this->investor())->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/financial/deposits/{$d->id}/payment-preview", [])->assertUnauthorized();
        $d->forceFill(['status' => 'approved'])->save();
        $w = implode(' ', $this->pay($d, ['paid_amount' => 100, 'paid_currency' => 'USD'])->json('warnings'));
        $this->assertStringContainsString('can no longer be recorded', $w);
    }

    // ── investment plan preview ──────────────────────────────────────────────────
    private function planPreview(array $b, $as = null)
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($as ?? $this->admin, 'sanctum')->postJson('/api/admin/investment-plan-preview', $b);
    }

    public function test_plan_preview_is_admin_only_and_writes_nothing(): void
    {
        $plan = InvestmentPlan::find($this->plan());
        $this->planPreview(['plan_id' => $plan->id, 'profit_percent' => 20], $this->fin)->assertForbidden();
        $this->planPreview(['plan_id' => $plan->id], $this->investor())->assertForbidden();
        $before = [InvestmentPlan::find($plan->id)->toJson(), DB::table('investment_accounts')->get()->toJson(), FinancialAuditLog::count()];
        $j = $this->planPreview(['plan_id' => $plan->id, 'profit_percent' => 20, 'min_amount' => 50])->assertOk()->json();
        $this->assertSame($before, [InvestmentPlan::find($plan->id)->toJson(), DB::table('investment_accounts')->get()->toJson(), FinancialAuditLog::count()]);
        $this->assertTrue($j['valid']);
        $this->assertContains('profit_percent', array_column($j['differences'], 'field'));
        $this->assertFalse($j['approval']['approved']);
        $this->assertStringContainsString('D35', $j['approval']['management_approval']);
    }

    public function test_plan_preview_counts_unchanged_active_investments_and_affected_pending_deposits(): void
    {
        $plan = InvestmentPlan::find($this->plan());
        $u = $this->investor();
        $this->invest($u, 100);
        $this->deposit($u, 100, $plan->id);
        $j = $this->planPreview(['plan_id' => $plan->id, 'profit_percent' => 99])->json();
        $this->assertSame(1, $j['impact']['pending_deposits_affected']);
        $this->assertGreaterThanOrEqual(0, $j['impact']['active_investments_unchanged']);
        $this->assertStringContainsString('WHEN THEY ARE APPROVED', implode(' ', $j['warnings']));
    }

    public function test_plan_preview_validation(): void
    {
        $plan = InvestmentPlan::find($this->plan());
        $j = $this->planPreview(['plan_id' => $plan->id, 'min_amount' => 500, 'max_amount' => 100])->assertOk()->json();
        $this->assertFalse($j['valid']);
        $this->assertArrayHasKey('max_amount', $j['errors']);
        $this->planPreview(['plan_id' => 999999])->assertStatus(422);
        $this->planPreview(['plan_id' => $plan->id, 'profit_percent' => 150])->assertStatus(422);
    }
}
