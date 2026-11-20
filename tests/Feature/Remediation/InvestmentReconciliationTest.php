<?php
// LOCATION: tests/Feature/Remediation/InvestmentReconciliationTest.php   (NEW — 19 Nov)
// Read-only active-investment reconciliation report. WRITTEN, NOT EXECUTED. Run: php artisan test --filter=InvestmentReconciliationTest

namespace Tests\Feature\Remediation;

use App\Models\InvestmentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Rank\FinancialTestCase;

class InvestmentReconciliationTest extends FinancialTestCase
{
    use RefreshDatabase;

    private function report(string $q = '', $as = null)
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($as ?? $this->fin, 'sanctum')->getJson('/api/financial/investment-reconciliation' . $q);
    }

    public function test_access_control(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/financial/investment-reconciliation')->assertUnauthorized();
        $this->report('', $this->investor())->assertForbidden();
        $this->report()->assertOk();
        $this->report('', $this->admin)->assertOk();
    }

    public function test_classification_flags_and_summary(): void
    {
        $u = $this->investor();
        $dep = $this->deposit($u, 100, null, 'bank_transfer', 'approved');
        $legacy = $this->invest($u, 100);
        DB::table('investment_accounts')->where('id', $legacy->id)->update(['source_deposit_id' => $dep->id]);
        $walletLegacy = $this->invest($u, 40);
        $alloc = $this->invest($u, 25);
        DB::table('investment_allocations')->insert(['user_id' => $u->id, 'investment_plan_id' => $alloc->investment_plan_id, 'investment_id' => $alloc->id, 'amount' => 25, 'plan_terms' => '{}', 'entry_group' => 'T-1']);
        $matured = $this->invest($u, 5);
        DB::table('investment_accounts')->where('id', $matured->id)->update(['end_date' => now()->subDay()->toDateString()]);
        $differs = $this->invest($u, 60);
        $pend = $this->deposit($u, 70, null, 'bank_transfer', 'pending');
        DB::table('investment_accounts')->where('id', $differs->id)->update(['source_deposit_id' => $pend->id]);
        $this->invest($u, 9, 30, 'completed');   // not active: excluded

        $j = $this->report()->assertOk()->json();
        $by = collect($j['positions'])->keyBy('investment_id');
        $this->assertSame('legacy_deposit_with_plan', $by[$legacy->id]['funding_source']);
        $this->assertSame('wallet_funded_legacy', $by[$walletLegacy->id]['funding_source']);
        $this->assertSame('wallet_funded_allocation', $by[$alloc->id]['funding_source']);
        $this->assertContains('legacy_dual_overlap', array_column($by[$legacy->id]['exceptions'], 'key'));
        $this->assertContains('matured_unsettled', array_column($by[$matured->id]['exceptions'], 'key'));
        $keys = array_column($by[$differs->id]['exceptions'], 'key');
        $this->assertContains('source_deposit_not_approved', $keys);
        $this->assertContains('source_deposit_amount_differs', $keys);
        $this->assertSame(5, $j['summary']['active_positions']);
        $this->assertSame('230.00', $j['summary']['active_principal']);
        $this->assertTrue($j['read_only']);
        $this->assertSame('legacy', $j['mode']['mode']);
    }

    public function test_filters_pagination_and_mode_banner(): void
    {
        $u = $this->investor();
        for ($n = 0; $n < 3; $n++) $this->invest($u, 10 + $n);
        $j = $this->report('?per_page=2&page=2&source=wallet_funded_legacy')->assertOk()->json();
        $this->assertSame(3, $j['meta']['total']);
        $this->assertCount(1, $j['positions']);
        $this->assertSame(2, $j['meta']['last_page']);
        $this->assertCount(0, $this->report('?source=wallet_funded_allocation')->json('positions'));
        $this->financialRulesOn();
        $this->assertSame('target', $this->report()->json('mode.mode'));
    }

    public function test_report_changes_nothing(): void
    {
        $u = $this->cash($this->investor(), 77);
        $this->invest($u, 10);
        $before = [DB::table('investment_accounts')->get()->toJson(), DB::table('users')->get()->toJson(), DB::table('balance_adjustments')->count(), DB::table('financial_audit_logs')->count()];
        $this->report()->assertOk();
        $after = [DB::table('investment_accounts')->get()->toJson(), DB::table('users')->get()->toJson(), DB::table('balance_adjustments')->count(), DB::table('financial_audit_logs')->count()];
        $this->assertSame($before, $after);
    }

    public function test_completed_positions_are_excluded_by_default_and_shown_with_scope(): void
    {
        $u = $this->investor();
        $this->invest($u, 10);
        $c = $this->invest($u, 30, 30, 'completed');
        $this->invest($u, 99, 30, 'cancelled');
        $j = $this->report()->assertOk()->json();
        $this->assertSame('active', $j['scope']);
        $this->assertStringContainsString('COMPLETED positions', $j['scope_note']);
        $this->assertSame([], array_values(array_filter($j['positions'], fn ($p) => $p['investment_id'] === $c->id)));
        $all = $this->report('?scope=all')->json();
        $this->assertSame(2, $all['summary']['positions']);
        $this->assertSame('40.00', $all['summary']['principal']);
        $this->assertSame(1, $all['summary']['active_positions']);
        $row = collect($all['positions'])->firstWhere('investment_id', $c->id);
        $this->assertSame('completed', $row['status']);
        $this->assertContains('completed_principal_untracked', array_column($row['exceptions'], 'key'));   // ledger off => cannot be known
        $this->report('?scope=bogus')->assertStatus(422);
    }

    public function test_completed_position_with_outstanding_principal_is_flagged_when_ledger_is_on(): void
    {
        $this->financialRulesOn();
        $u = $this->investor();
        $c = $this->invest($u, 30, 30, 'completed');
        $row = collect($this->report('?scope=completed')->json('positions'))->firstWhere('investment_id', $c->id);
        $this->assertContains('completed_principal_outstanding', array_column($row['exceptions'], 'key'));
        $this->assertStringContainsString('principal remaining 30.00 of 30.00', $row['principal_return_status']);
    }

    public function test_duplicate_funding_records_are_flagged(): void
    {
        $u = $this->investor();
        $dep = $this->deposit($u, 10, null, 'bank_transfer', 'approved');
        $a = $this->invest($u, 10); $b = $this->invest($u, 10);
        // The unique index (2026_10_21_100001) now prevents this state; legacy rows created before it can still exist, so simulate them.
        \Illuminate\Support\Facades\Schema::table('investment_accounts', fn ($t) => $t->dropUnique('investment_accounts_source_deposit_id_unique'));
        DB::table('investment_accounts')->whereIn('id', [$a->id, $b->id])->update(['source_deposit_id' => $dep->id]);
        $c = $this->invest($u, 5);
        foreach ([1, 2] as $_) DB::table('investment_allocations')->insert(['user_id' => $u->id, 'investment_plan_id' => $c->investment_plan_id, 'investment_id' => $c->id, 'amount' => 5, 'plan_terms' => '{}', 'entry_group' => 'T-' . $_]);
        $by = collect($this->report()->json('positions'))->keyBy('investment_id');
        $this->assertContains('duplicate_source_deposit', array_column($by[$a->id]['exceptions'], 'key'));
        $this->assertContains('duplicate_source_deposit', array_column($by[$b->id]['exceptions'], 'key'));
        $this->assertContains('duplicate_allocation', array_column($by[$c->id]['exceptions'], 'key'));
    }

    public function test_exception_filter_and_summary_counts_agree(): void
    {
        $u = $this->investor();
        $dep = $this->deposit($u, 10, null, 'bank_transfer', 'approved');
        $a = $this->invest($u, 10);
        DB::table('investment_accounts')->where('id', $a->id)->update(['source_deposit_id' => $dep->id]);
        $this->invest($u, 7);
        $j = $this->report('?exception=legacy_dual_overlap')->json();
        $this->assertSame(1, $j['meta']['total']);
        $this->assertSame(1, $j['summary']['exceptions']['legacy_dual_overlap']);
        $this->assertSame(2, $j['summary']['positions']);   // summary is not narrowed by the filter
    }

    public function test_non_get_methods_do_not_exist(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $m) $this->assertContains($this->actingAs($this->fin, 'sanctum')->{$m . 'Json'}('/api/financial/investment-reconciliation')->getStatusCode(), [404, 405]);
    }
}
