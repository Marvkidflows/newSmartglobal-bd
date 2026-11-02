<?php
// LOCATION: tests/Feature/Rank/FinancialTestCase.php
// Shared fixtures for the Financial Team decision tests. All data is created inside the test database (isolated, in-memory SQLite per
// phpunit.xml): no real investor balance or financial record is ever touched.

namespace Tests\Feature\Rank;

use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerReaders;
use Illuminate\Support\Facades\DB;

abstract class FinancialTestCase extends RankTestCase
{
    protected User $fin;

    protected function setUp(): void
    {
        parent::setUp();
        LedgerReaders::forget();
        $this->fin = User::factory()->create(['role' => 'financial', 'status' => 'active']);
    }

    /** Switch the Financial Team's decisions on (staging behaviour). */
    protected function financialRulesOn(): void
    {
        config(['financial.separate_deposit_approval' => true, 'financial.ledger_enabled' => true]);
    }

    protected function plan(float $min = 1, int $days = 30, float $pct = 10): int
    {
        return DB::table('investment_plans')->insertGetId(['name' => 'Fin plan ' . uniqid(), 'min_amount' => $min, 'duration_days' => $days, 'profit_percentage' => $pct, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function cash(User $u, float $amount): User
    {
        $u->forceFill(['balance' => $amount])->save();
        return $u->fresh();
    }

    protected function balance(User $u): float
    {
        return round((float) $u->fresh()->balance, 2);
    }

    protected function deposit(User $u, float $amount, ?int $planId = null, string $method = 'bank_transfer', string $status = 'pending'): Deposit
    {
        return Deposit::create(['user_id' => $u->id, 'amount' => $amount, 'payment_method' => $method, 'transaction_reference' => 'DEP-T-' . uniqid(), 'status' => $status, 'investment_plan_id' => $planId]);
    }

    protected function approveDeposit(Deposit $d)
    {
        return $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/deposits/{$d->id}/approve", ['confirm_usd' => true, 'reason' => 'Received and cleared in USD (test attestation)']);
    }

    protected function withdrawal(User $u, float $amount, string $status = 'pending'): Withdrawal
    {
        return Withdrawal::create(['user_id' => $u->id, 'amount' => $amount, 'method' => 'bank_transfer', 'account_details' => json_encode(['bank_name' => 'Test']), 'status' => $status]);
    }

    protected function approveWithdrawal(Withdrawal $w)
    {
        return $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/withdrawals/{$w->id}/approve");
    }

    /** The investor invests wallet cash through the real endpoint. */
    protected function investFromWallet(User $u, int $planId, float $amount)
    {
        return $this->actingAs($u, 'sanctum')->postJson('/api/investor-investment/investor/investments', ['plan_id' => $planId, 'amount' => $amount]);
    }

    protected function latestPosition(User $u): ?InvestmentAccount
    {
        return InvestmentAccount::where('user_id', $u->id)->orderByDesc('id')->first();
    }

    protected function facts(User $u, bool $breakdown = false): array
    {
        return app(\App\Services\Rank\RankInvestmentLedger::class)->forUsers([$u->id], $breakdown)[$u->id];
    }

    protected function controls(): static
    {
        return $this->actingAs($this->fin, 'sanctum');
    }

    /** A completed, matured, VERIFIED cycle whose principal is no longer an active position. */
    protected function cycle(User $u, float $amount = 100): InvestmentAccount
    {
        return $this->investCompleted($u, $amount);
    }
}
