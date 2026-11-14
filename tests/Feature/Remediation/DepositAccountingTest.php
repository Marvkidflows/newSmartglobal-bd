<?php
// LOCATION: tests/Feature/Remediation/DepositAccountingTest.php
// F-02: duplicate-approval protection, accounting separation (flag ON), partial principal redemption linkage and the READ-ONLY reconciliation report.
// The legacy double-representation is DOCUMENTED here by a characterisation test, not changed: the cutover is behind financial.separate_deposit_approval
// and needs written Management + Financial Team approval. STATUS: written, NOT executed by the author.

namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\InvestmentAccount;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Rank\FinancialTestCase;

class DepositAccountingTest extends FinancialTestCase
{
    protected function inv(): User { return User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '0.00']); }
    protected function bal(User $u): string { return number_format((float) $u->fresh()->balance, 2, '.', ''); }

    public function test_approving_the_same_deposit_twice_credits_and_invests_once(): void
    {
        $u = $this->inv(); $plan = $this->plan(1, 30, 10);
        $d = $this->deposit($u, 500, $plan);
        $this->approveDeposit($d)->assertOk();
        $this->approveDeposit($d)->assertStatus(422);
        $this->assertSame('500.00', $this->bal($u));
        $this->assertSame(1, InvestmentAccount::where('source_deposit_id', $d->id)->count());
        $this->assertSame(1, BalanceAdjustment::where('user_id', $u->id)->count());
    }

    public function test_legacy_flow_characterisation_the_same_500_is_cash_and_active_principal(): void
    {
        config(['financial.separate_deposit_approval' => false]);
        $u = $this->inv(); $d = $this->deposit($u, 500, $this->plan(1, 30, 10));
        $this->approveDeposit($d)->assertOk();
        $this->assertSame('500.00', $this->bal($u), 'cash credited');
        $this->assertSame('500.00', number_format((float) InvestmentAccount::where('user_id', $u->id)->where('status', 'active')->sum('amount'), 2, '.', ''), 'AND active principal (F-02, known; flag-gated fix)');
    }

    public function test_with_the_separated_flow_on_a_deposit_is_wallet_cash_only(): void
    {
        $this->financialRulesOn();
        $u = $this->inv(); $d = $this->deposit($u, 500, $this->plan(1, 30, 10));
        $this->approveDeposit($d)->assertOk();
        $this->assertSame('500.00', $this->bal($u));
        $this->assertSame(0, InvestmentAccount::where('user_id', $u->id)->count(), 'no investment is created by approval');
        $this->assertSame(1, DB::table('wallet_ledger_entries')->where('deposit_id', $d->id)->where('classification', 'cleared_wallet_funds')->count());

        // the investor then allocates cash: wallet debit and position are ONE linked movement
        $this->investFromWallet($u->fresh(), $d->investment_plan_id, 300)->assertStatus(201);   // fresh(): the approval credited the DB row, not this in-memory model
        $this->assertSame('200.00', $this->bal($u));
        $pos = $this->latestPosition($u);
        $this->assertSame(1, DB::table('investment_allocations')->where('investment_id', $pos->id)->count());
        $this->assertSame(1, DB::table('wallet_ledger_entries')->where('investment_id', $pos->id)->where('classification', 'wallet_allocation_debit')->count());
        $this->assertSame(1, DB::table('wallet_ledger_entries')->where('investment_id', $pos->id)->where('classification', 'allocated_investment_principal')->count());
    }

    public function test_withdrawal_approval_and_rejection_with_the_separated_flow(): void
    {
        $this->financialRulesOn();
        $u = $this->inv(); $this->cash($u, 100);
        $w1 = $this->withdrawal($u, 40); $this->approveWithdrawal($w1)->assertOk();
        $this->assertSame('60.00', $this->bal($u));
        $w2 = $this->withdrawal($u, 30);
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/withdrawals/{$w2->id}/reject", ['reason' => 'Documents missing'])->assertOk();
        $this->assertSame('60.00', $this->bal($u), 'a rejected withdrawal never touches the wallet');
        $this->approveWithdrawal($w1)->assertStatus(422);        // cannot be approved twice
        $this->assertSame('60.00', $this->bal($u));
    }

    public function test_the_reconciliation_report_is_read_only_and_lists_active_legacy_dual_positions(): void
    {
        config(['financial.separate_deposit_approval' => false]);
        $u = $this->inv(); $d = $this->deposit($u, 500, $this->plan(1, 30, 10));
        $this->approveDeposit($d)->assertOk();

        $snap = ['users' => DB::table('users')->orderBy('id')->get()->toJson(), 'inv' => DB::table('investment_accounts')->orderBy('id')->get()->toJson(),
                 'dep' => DB::table('deposits')->orderBy('id')->get()->toJson(), 'adj' => DB::table('balance_adjustments')->count()];
        $out = sys_get_temp_dir() . '/ssi-recon-' . uniqid() . '.csv';
        $this->assertSame(0, Artisan::call('ssi:reconcile-legacy-deposits', ['--out' => $out]));

        $csv = array_map('str_getcsv', file($out, FILE_IGNORE_NEW_LINES));
        $this->assertSame('LEGACY_DUAL_ACTIVE_REVIEW', $csv[1][12]);
        $this->assertSame('500.00', $csv[1][10], 'potential overlap = min(wallet 500.00, active principal 500.00)');
        $this->assertSame($snap, ['users' => DB::table('users')->orderBy('id')->get()->toJson(), 'inv' => DB::table('investment_accounts')->orderBy('id')->get()->toJson(),
                                   'dep' => DB::table('deposits')->orderBy('id')->get()->toJson(), 'adj' => DB::table('balance_adjustments')->count()], 'nothing was modified');
        @unlink($out);
    }

    public function test_the_reconciliation_report_never_overwrites_without_force_and_is_owner_only(): void
    {
        $out = sys_get_temp_dir() . '/ssi-recon-keep-' . uniqid() . '.csv';
        file_put_contents($out, 'PRECIOUS');
        $this->assertSame(1, Artisan::call('ssi:reconcile-legacy-deposits', ['--out' => $out]));
        $this->assertSame('PRECIOUS', file_get_contents($out), 'an existing report must be left untouched');

        $this->assertSame(0, Artisan::call('ssi:reconcile-legacy-deposits', ['--out' => $out, '--force' => true]));
        $this->assertStringStartsWith('user_id,', file_get_contents($out));
        if (DIRECTORY_SEPARATOR === '/') $this->assertSame('0600', substr(sprintf('%o', fileperms($out)), -4));
        @unlink($out);
    }
}
