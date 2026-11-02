<?php
// LOCATION: tests/Feature/Rank/MoneyFlowCharacterizationTest.php
//
// CHARACTERIZATION of today's money flows with the Financial feature flags OFF (the shipped defaults). It does not assert that this
// behaviour is "right" — it LOCKS it, so the Financial Team's flagged changes can never alter existing behaviour by accident, and so the
// before/after difference is explicit. Two behaviours here are exactly the ones the Financial Team's decisions replace when the flags are
// on: deposit approval with a plan credits the wallet AND creates an active investment, and the wallet ledger does not exist.
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php artisan test --filter=MoneyFlowCharacterizationTest

namespace Tests\Feature\Rank;

use App\Models\InvestmentAccount;
use App\Models\WalletLedgerEntry;
use App\Services\InvestmentSettlementService;
use Illuminate\Support\Facades\DB;

class MoneyFlowCharacterizationTest extends FinancialTestCase
{
    public function test_the_shipped_defaults_leave_every_financial_flag_off(): void
    {
        foreach (['separate_deposit_approval', 'ledger_enabled', 'crypto_rank_valuation_enabled', 'live_fx_enabled', 'account_level_redemption_fallback'] as $flag) {
            $this->assertFalse((bool) config("financial.$flag"), "$flag must default to false");
        }
    }

    public function test_wallet_investing_legacy_debits_the_wallet_and_creates_an_active_position_with_no_ledger_rows(): void
    {
        $u = $this->cash($this->investor(), 1000);
        $plan = $this->plan(1, 30, 10);

        $this->investFromWallet($u, $plan, 400)->assertSuccessful();

        $this->assertSame(600.0, $this->balance($u));
        $p = $this->latestPosition($u);
        $this->assertSame('active', $p->status);
        $this->assertEquals(400.00, $p->amount);
        $this->assertSame(0, WalletLedgerEntry::count(), 'the ledger is a no-op while the flag is off');
    }

    public function test_legacy_deposit_approval_with_a_plan_credits_the_wallet_and_also_creates_an_active_investment(): void
    {
        // The behaviour the Financial Team's Option A replaces (flag financial.separate_deposit_approval).
        $u = $this->cash($this->investor(), 0);
        $d = $this->deposit($u, 800, $this->plan());

        $this->approveDeposit($d)->assertOk();

        $this->assertSame(800.0, $this->balance($u), 'wallet credited');
        $this->assertSame(1, InvestmentAccount::where('user_id', $u->id)->count(), 'an investment was also created for the same 800');
        $this->assertSame('active', $this->latestPosition($u)->status);
    }

    public function test_legacy_deposit_approval_without_a_plan_only_credits_the_wallet(): void
    {
        $u = $this->cash($this->investor(), 0);
        $this->approveDeposit($this->deposit($u, 300))->assertOk();

        $this->assertSame(300.0, $this->balance($u));
        $this->assertSame(0, InvestmentAccount::where('user_id', $u->id)->count());
    }

    public function test_legacy_withdrawal_approval_debits_the_wallet_only_and_touches_no_investment(): void
    {
        $u = $this->cash($this->investor(), 500);
        $pos = $this->invest($u, 700, 30, 'active');
        $w = $this->withdrawal($u, 200);

        $this->approveWithdrawal($w)->assertSuccessful();

        $this->assertSame(300.0, $this->balance($u));
        $this->assertSame('approved', $w->fresh()->status);
        $this->assertEquals(700.00, $pos->fresh()->amount);
        $this->assertSame(0, DB::table('principal_movements')->count());
    }

    public function test_legacy_settlement_credits_profit_only_and_never_the_principal(): void
    {
        $u = $this->cash($this->investor(), 0);
        $plan = $this->plan(1, 30, 10);
        $pos = InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $plan, 'amount' => 1000, 'profit_percentage' => 10, 'expected_profit' => 100,
            'total_return' => 1100, 'status' => 'active', 'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDay()->toDateString()]);

        $r = app(InvestmentSettlementService::class)->complete($pos, 'admin', $this->admin->id);

        $this->assertTrue($r['completed']);
        $this->assertSame(100.0, $this->balance($u), 'only the profit reached the wallet; the principal was not credited');
        $this->assertSame('completed', $pos->fresh()->status);
    }

    public function test_evaluating_ranks_never_changes_a_balance_or_an_investment(): void
    {
        $u = $this->cash($this->investorWith(5000, 3), 250);
        $before = [$this->balance($u), DB::table('investment_accounts')->get()->toJson(), DB::table('deposits')->count(), DB::table('withdrawals')->count()];

        app(\App\Services\Rank\RankEvaluationService::class)->evaluateUser($u->id, 'test');

        $this->assertSame($before, [$this->balance($u), DB::table('investment_accounts')->get()->toJson(), DB::table('deposits')->count(), DB::table('withdrawals')->count()]);
    }
}
