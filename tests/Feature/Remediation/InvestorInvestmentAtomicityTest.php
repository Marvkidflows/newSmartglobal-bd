<?php
// LOCATION: tests/Feature/Remediation/InvestorInvestmentAtomicityTest.php
// F-01: the legacy wallet → investment path must be atomic, locked, decimal-exact and idempotent. STATUS: written, NOT executed by the author
// (no PHP in the authoring environment). These run on SQLite by default; row-lock/parallel behaviour is proved ONLY by MySqlConcurrencyTest.

namespace Tests\Feature\Remediation;

use App\Models\InvestmentAccount;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Rank\FinancialTestCase;

class InvestorInvestmentAtomicityTest extends FinancialTestCase
{
    protected function walletInvestor(string $balance): User
    {
        return User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => $balance]);
    }

    protected function bal(User $u): string
    {
        return number_format((float) $u->fresh()->balance, 2, '.', '');
    }

    public function test_insufficient_balance_is_rejected_and_nothing_is_written(): void
    {
        $u = $this->walletInvestor('50.00'); $plan = $this->plan(1);
        $this->investFromWallet($u, $plan, 50.01)->assertStatus(422)->assertJsonPath('message', 'Insufficient balance. Please make a deposit first.');
        $this->assertSame(0, InvestmentAccount::where('user_id', $u->id)->count());
        $this->assertSame('50.00', $this->bal($u));
    }

    public function test_exact_balance_invests_everything_and_balance_is_exactly_zero(): void
    {
        $u = $this->walletInvestor('100.00'); $plan = $this->plan(1, 30, 10);
        $this->investFromWallet($u, $plan, 100.00)->assertStatus(201);
        $this->assertSame('0.00', $this->bal($u));
        $pos = $this->latestPosition($u);
        $this->assertSame('100.00', (string) $pos->amount);
        $this->assertSame('10.00', (string) $pos->expected_profit);
        $this->assertSame('110.00', (string) $pos->total_return);
    }

    public function test_cent_boundary_uses_exact_decimals_not_floats(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite stores DECIMAL as binary floating point, so 3.30 - 1.10 - 1.10 is 1.0999999999999996 there. This must be run on MySQL (DECIMAL is exact).');
        }
        $u = $this->walletInvestor('3.30'); $plan = $this->plan(1);
        $this->investFromWallet($u, $plan, 1.10)->assertStatus(201);
        $this->investFromWallet($u, $plan, 1.10)->assertStatus(201);
        $this->investFromWallet($u, $plan, 1.10)->assertStatus(201);
        $this->assertSame('0.00', $this->bal($u), '3.30 - 3 x 1.10 must be exactly 0.00 (float arithmetic leaves a residue)');
        $this->investFromWallet($u, $plan, 1.00)->assertStatus(422);
    }

    public function test_sequential_overspend_never_makes_the_wallet_negative(): void
    {
        $u = $this->walletInvestor('100.00'); $plan = $this->plan(1);
        $this->investFromWallet($u, $plan, 60)->assertStatus(201);
        $this->investFromWallet($u, $plan, 60)->assertStatus(422);
        $this->assertSame('40.00', $this->bal($u));
        $this->assertSame(1, InvestmentAccount::where('user_id', $u->id)->count());
    }

    public function test_a_failure_after_the_insert_rolls_back_both_the_investment_and_the_debit(): void
    {
        $u = $this->walletInvestor('100.00'); $plan = $this->plan(1);
        Event::listen('eloquent.created: ' . InvestmentAccount::class, function () { throw new \RuntimeException('simulated failure after insert'); });
        try {
            $this->investFromWallet($u, $plan, 40)->assertStatus(500);
        } finally {
            Event::forget('eloquent.created: ' . InvestmentAccount::class);
        }
        $this->assertSame(0, InvestmentAccount::where('user_id', $u->id)->count(), 'no orphaned investment');
        $this->assertSame('100.00', $this->bal($u), 'no orphaned debit');
    }

    public function test_plan_limits_are_enforced(): void
    {
        $u = $this->walletInvestor('1000.00'); $plan = $this->plan(100);
        $this->investFromWallet($u, $plan, 99.99)->assertStatus(422);
        $this->assertSame('1000.00', $this->bal($u));
    }

    public function test_a_repeated_idempotency_key_cannot_create_a_second_position(): void
    {
        $u = $this->walletInvestor('100.00'); $plan = $this->plan(1);
        $h = ['Idempotency-Key' => 'inv-key-0001-abcdef'];
        $url = '/api/investor-investment/investor/investments';
        $this->actingAs($u, 'sanctum')->postJson($url, ['plan_id' => $plan, 'amount' => 30], $h)->assertStatus(201);
        $this->actingAs($u->fresh(), 'sanctum')->postJson($url, ['plan_id' => $plan, 'amount' => 30], $h)->assertStatus(409);
        $this->assertSame(1, InvestmentAccount::where('user_id', $u->id)->count());
        $this->assertSame('70.00', $this->bal($u), 'debited once only');
    }

    public function test_a_failed_attempt_releases_the_key_so_a_corrected_retry_works(): void
    {
        $u = $this->walletInvestor('10.00'); $plan = $this->plan(1);
        $h = ['Idempotency-Key' => 'inv-key-0002-abcdef'];
        $url = '/api/investor-investment/investor/investments';
        $this->actingAs($u, 'sanctum')->postJson($url, ['plan_id' => $plan, 'amount' => 50], $h)->assertStatus(422);
        $this->cash($u, 100.00);
        $this->actingAs($u->fresh(), 'sanctum')->postJson($url, ['plan_id' => $plan, 'amount' => 50], $h)->assertStatus(201);
        $this->assertSame('50.00', $this->bal($u));
    }
}
