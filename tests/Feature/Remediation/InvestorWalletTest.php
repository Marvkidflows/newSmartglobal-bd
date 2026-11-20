<?php
// LOCATION: tests/Feature/Remediation/InvestorWalletTest.php   (NEW — investor wallet/transactions)
// Read-only wallet summary and transaction history: own records only, figures come from the backend, pending money never reduces the available
// balance, deposit/withdrawal adjustment rows are not double counted, no internal notes leak, nothing is written.
// WRITTEN, NOT EXECUTED (no vendor/). Run: php artisan test --filter=InvestorWalletTest

namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvestorWalletTest extends TestCase
{
    use RefreshDatabase;

    private User $a;
    private User $b;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        $make = fn (string $n) => User::factory()->create(['role' => 'investor', 'name' => $n, 'status' => 'active', 'registration_stage' => 4, 'registration_completed' => true,
            'balance' => 250.50, 'email' => Str::lower(Str::random(8)) . '@ssi-test.test', 'email_verified_at' => now()]);
        $this->a = $make('Investor A');
        $this->b = $make('Investor B');
        $this->planId = DB::table('investment_plans')->insertGetId(['name' => 'Plan ' . uniqid(), 'min_amount' => 1, 'duration_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function asA() { $this->app['auth']->forgetGuards(); return $this->actingAs($this->a, 'sanctum'); }

    private function inv(User $u, string $status, $amount, $profit): InvestmentAccount
    {
        return InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $this->planId, 'amount' => $amount, 'profit_percentage' => 10, 'expected_profit' => $profit,
            'total_return' => $amount + $profit, 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(), 'remaining_days' => 30, 'status' => $status]);
    }

    public function test_summary_reports_backend_figures_for_the_signed_in_investor_only(): void
    {
        $this->inv($this->a, 'active', 1000, 100);
        $this->inv($this->a, 'completed', 500, 50);
        $this->inv($this->b, 'active', 9999, 999);   // someone else's: must not appear
        Deposit::create(['user_id' => $this->a->id, 'amount' => 300, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-W1', 'status' => 'pending']);
        Withdrawal::create(['user_id' => $this->a->id, 'amount' => 40, 'method' => 'bitcoin', 'account_details' => '{}', 'status' => 'pending']);

        $w = $this->asA()->getJson('/api/investor-investment/investor/wallet')->assertOk()->json('wallet');
        $this->assertSame('250.50', $w['available_balance']);       // pending withdrawal does NOT reduce it
        $this->assertSame('1000.00', $w['active_invested']);
        $this->assertSame(1, $w['active_investments']);
        $this->assertSame(1, $w['completed_investments']);
        $this->assertSame('50.00', $w['profit_credited']);
        $this->assertSame('300.00', $w['pending_deposits']);
        $this->assertSame('40.00', $w['pending_withdrawals']);
    }

    public function test_transactions_are_own_only_and_do_not_double_count_deposit_adjustments(): void
    {
        $dep = Deposit::create(['user_id' => $this->a->id, 'amount' => 300, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-W2', 'status' => 'approved']);
        BalanceAdjustment::create(['user_id' => $this->a->id, 'admin_id' => $this->b->id, 'type' => 'add', 'amount' => 300, 'balance_before' => 0, 'balance_after' => 300, 'reason' => "Deposit #{$dep->id} approved"]);
        BalanceAdjustment::create(['user_id' => $this->a->id, 'admin_id' => $this->b->id, 'type' => 'add', 'amount' => 5, 'balance_before' => 300, 'balance_after' => 305, 'reason' => 'INTERNAL SECRET NOTE']);
        Deposit::create(['user_id' => $this->b->id, 'amount' => 77, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-OTHER', 'status' => 'pending']);

        $r = $this->asA()->getJson('/api/investor-investment/investor/transactions')->assertOk();
        $rows = collect($r->json('transactions'));
        $this->assertSame(1, $rows->where('type', 'deposit')->count());
        $this->assertSame(1, $rows->where('type', 'adjustment')->count(), 'the deposit-approval adjustment must not be listed a second time');
        $this->assertFalse($rows->contains('reference', 'DEP-OTHER'));
        $this->assertStringNotContainsString('INTERNAL SECRET NOTE', $r->getContent());
    }

    public function test_hold_is_shown_as_pending_and_endpoints_require_login_and_are_get_only(): void
    {
        Deposit::create(['user_id' => $this->a->id, 'amount' => 10, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-W3', 'status' => 'hold']);
        $rows = collect($this->asA()->getJson('/api/investor-investment/investor/transactions?type=deposit')->assertOk()->json('transactions'));
        $this->assertSame('pending', $rows->first()['status']);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/investor-investment/investor/wallet')->assertUnauthorized();
        $this->asA()->postJson('/api/investor-investment/investor/wallet', [])->assertStatus(405);
    }

    public function test_reading_the_wallet_changes_nothing(): void
    {
        $before = $this->a->fresh()->balance;
        $this->asA()->getJson('/api/investor-investment/investor/wallet')->assertOk();
        $this->asA()->getJson('/api/investor-investment/investor/transactions')->assertOk();
        $this->assertEquals($before, $this->a->fresh()->balance);
        $this->assertSame(0, BalanceAdjustment::count());
    }
}
