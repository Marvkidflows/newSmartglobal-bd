<?php
// LOCATION: tests/MySql/MySqlWithdrawalRoundsTest.php
//
// Withdrawal / balance concurrency — REPEATED rounds against REAL MySQL (real parallel OS processes). WRITTEN, NOT EXECUTED by its author
// (no PHP/MySQL was available). SQLite cannot prove any of this. Run (isolated *_test database only; see docs/integration/MIGRATION_AND_TEST_GUIDE.md §6):
//   vendor\bin\pest --configuration=phpunit.mysql.xml tests\MySql\MySqlWithdrawalRoundsTest.php
//
// WHY A SEPARATE FILE: MySqlApprovalConcurrencyTest::test_a_balance_deduction_and_a_withdrawal_approval_cannot_overdraw runs the race once, and its
// outcome depends on which racer wins. Here the same race is repeated, and every ledger entry is classified so nothing is mis-attributed:
//   * ADMINISTRATOR deduction   = BalanceAdjustment, type deduct, admin_id = the administrator
//   * WITHDRAWAL approval debit = BalanceAdjustment, type deduct, reason "Withdrawal #<id> approved" (admin_id = the approving Financial user)
//   * the payout ledger entry   = WalletLedgerEntry entry_key "withdrawal:<id>:payout"
// Invariants (not a fixed winner): never a negative balance; the two debits cannot both happen (80 + 50 > 100); the balance always equals
// start - 80 x (approval debits) - 50 x (administrator deductions); every adjustment row is arithmetically consistent; no unexplained rows.

namespace Tests\MySql;

use App\Models\BalanceAdjustment;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Models\Withdrawal;
use App\Models\WithdrawalFeeEvent;
use App\Models\WithdrawalFeePolicy;
use Illuminate\Support\Facades\Hash;

class MySqlWithdrawalRoundsTest extends MySqlTestCase
{
    private const ROUNDS = 5;

    protected function setUp(): void
    {
        // The wallet ledger is OFF by default; the child PHP processes inherit this environment variable, so payout ledger entries exist in every race.
        putenv('FINANCIAL_LEDGER_ENABLED=true'); $_ENV['FINANCIAL_LEDGER_ENABLED'] = 'true'; $_SERVER['FINANCIAL_LEDGER_ENABLED'] = 'true';
        parent::setUp();
        config(['financial.ledger_enabled' => true]);
    }

    protected function staff(string $role): User { return User::factory()->create(['role' => $role, 'status' => 'active']); }
    protected function investor(string $balance, bool $pin = false): User
    {
        return User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => $balance] + ($pin ? ['withdrawal_pin' => Hash::make('1234')] : []));
    }
    protected function job(User $u, string $method, string $uri, array $body = [], array $headers = []): array
    {
        return ['token' => $u->createToken('t')->plainTextToken, 'method' => $method, 'uri' => $uri, 'body' => $body, 'headers' => $headers];
    }
    protected function pendingWithdrawal(User $u, int $amount = 80): Withdrawal
    {
        return Withdrawal::create(['user_id' => $u->id, 'amount' => $amount, 'method' => 'bank_transfer', 'account_details' => json_encode(['bank_name' => 'T']), 'status' => 'pending']);
    }

    public function test_repeated_rounds_of_admin_deduction_vs_withdrawal_approval_never_overdraw_and_every_entry_is_classified(): void
    {
        $outcomes = ['approval_won' => 0, 'deduction_won' => 0];
        foreach (range(1, self::ROUNDS) as $round) {
            $admin = $this->staff('admin'); $fin = $this->staff('financial'); $u = $this->investor('100.00');
            $w = $this->pendingWithdrawal($u);

            $st = $this->fireTogether([
                $this->job($admin, 'POST', "/api/admin/users/{$u->id}/balance", ['type' => 'deduct', 'amount' => 50, 'reason' => 'race deduct']),
                $this->job($fin, 'POST', "/api/financial/withdrawals/{$w->id}/approve"),
            ]);
            $ctx = "round {$round}, statuses " . implode(',', $st);

            $balance = (float) $u->fresh()->balance;
            $approved = $w->fresh()->status === 'approved' ? 1 : 0;
            $rows = BalanceAdjustment::where('user_id', $u->id)->get();
            $withdrawalDebits = $rows->filter(fn ($r) => $r->type === 'deduct' && $r->reason === "Withdrawal #{$w->id} approved")->count();
            $adminDeducts     = $rows->filter(fn ($r) => $r->type === 'deduct' && (int) $r->admin_id === $admin->id)->count();
            $payouts          = WalletLedgerEntry::where('entry_key', "withdrawal:{$w->id}:payout")->count();

            $this->assertGreaterThanOrEqual(0.0, $balance, "NEGATIVE BALANCE (overdraw) — {$ctx}");
            $this->assertFalse($withdrawalDebits === 1 && $adminDeducts === 1, "both debits succeeded (130 > 100) — {$ctx}");
            $this->assertSame($approved, $withdrawalDebits, "approval debit rows must equal approved withdrawals — {$ctx}");
            $this->assertSame($approved, $payouts, "exactly one payout ledger entry per approved withdrawal — {$ctx}");
            $this->assertSame($rows->count(), $withdrawalDebits + $adminDeducts, "unexplained balance adjustment rows — {$ctx}");
            $this->assertSame($this->money(100 - 80 * $withdrawalDebits - 50 * $adminDeducts), $this->money($balance), "balance != start - debits — {$ctx}");
            $this->assertSame(1, count(array_filter($st, fn ($s) => $s === 200)), "exactly one racer may win — {$ctx}");
            foreach ($rows as $r) {
                $this->assertEqualsWithDelta((float) $r->balance_before - (float) $r->amount, (float) $r->balance_after, 0.005, "adjustment arithmetic — {$ctx}");
            }
            $outcomes[$approved ? 'approval_won' : 'deduction_won']++;
        }
        fwrite(STDERR, "\n[rounds] outcomes across " . self::ROUNDS . " rounds: " . json_encode($outcomes) . "\n");
        $this->assertSame(self::ROUNDS, array_sum($outcomes));
    }

    public function test_two_simultaneous_approvals_of_one_withdrawal_debit_exactly_once(): void
    {
        foreach (range(1, 3) as $round) {
            $f1 = $this->staff('financial'); $f2 = $this->staff('financial'); $u = $this->investor('100.00');
            $w = $this->pendingWithdrawal($u);
            $st = $this->fireTogether([$this->job($f1, 'POST', "/api/financial/withdrawals/{$w->id}/approve"), $this->job($f2, 'POST', "/api/financial/withdrawals/{$w->id}/approve")]);
            $this->assertSame([200, 422], $this->sorted($st), "round {$round}");
            $this->assertSame('20.00', $this->money($u->fresh()->balance), "debited once, not twice — round {$round}");
            $this->assertSame(1, BalanceAdjustment::where('user_id', $u->id)->count());
            $this->assertSame(1, WalletLedgerEntry::where('entry_key', "withdrawal:{$w->id}:payout")->count());
        }
    }

    public function test_approval_and_rejection_racing_each_other_end_in_one_state_with_consistent_money(): void
    {
        foreach (range(1, 3) as $round) {
            $f1 = $this->staff('financial'); $f2 = $this->staff('financial'); $u = $this->investor('100.00');
            $w = $this->pendingWithdrawal($u);
            $st = $this->fireTogether([$this->job($f1, 'POST', "/api/financial/withdrawals/{$w->id}/approve"), $this->job($f2, 'POST', "/api/financial/withdrawals/{$w->id}/reject", ['reason' => 'race'])]);
            $this->assertSame([200, 422], $this->sorted($st), "round {$round}");
            $status = $w->fresh()->status;
            $this->assertContains($status, ['approved', 'rejected']);
            $this->assertSame($status === 'approved' ? '20.00' : '100.00', $this->money($u->fresh()->balance), "a rejected withdrawal must leave no deduction — round {$round}");
            $this->assertSame($status === 'approved' ? 1 : 0, BalanceAdjustment::where('user_id', $u->id)->count());
        }
    }

    public function test_the_same_idempotency_key_submitted_twice_at_once_creates_one_withdrawal(): void
    {
        $u = $this->investor('1000.00', true);
        $body = ['amount' => 100, 'method' => 'usdt', 'wallet_address' => '0xabc', 'withdrawal_pin' => '1234'];
        $h = ['Idempotency-Key' => 'round-key-0001'];
        $st = $this->fireTogether([$this->job($u, 'POST', '/api/investor-investment/investor/withdrawals', $body, $h), $this->job($u, 'POST', '/api/investor-investment/investor/withdrawals', $body, $h)]);
        $this->assertSame(1, Withdrawal::where('user_id', $u->id)->count(), implode(',', $st));
        $this->assertSame('1000.00', $this->money($u->fresh()->balance), 'submission never debits');
        $this->assertSame(1, WalletLedgerEntry::where('user_id', $u->id)->where('classification', 'pending_withdrawal')->count());
    }

    public function test_two_submissions_with_different_keys_leave_exactly_one_pending_withdrawal(): void
    {
        $u = $this->investor('1000.00', true);
        $body = ['amount' => 100, 'method' => 'usdt', 'wallet_address' => '0xabc', 'withdrawal_pin' => '1234'];
        $st = $this->fireTogether([$this->job($u, 'POST', '/api/investor-investment/investor/withdrawals', $body, ['Idempotency-Key' => 'key-a-0001']),
            $this->job($u, 'POST', '/api/investor-investment/investor/withdrawals', $body, ['Idempotency-Key' => 'key-b-0002'])]);
        $this->assertSame([201, 422], $this->sorted($st));
        $this->assertSame(1, Withdrawal::where('user_id', $u->id)->where('status', 'pending')->count());
    }

    public function test_a_fee_bearing_withdrawal_approved_twice_at_once_collects_the_fee_once(): void
    {
        WithdrawalFeePolicy::create(['fee_type' => 'fixed', 'fixed_amount' => '5.00', 'currency' => 'USD', 'is_active' => true, 'policy_approved' => true,
            'approval_reference' => 'TEST-ONLY', 'effective_from' => now()->subMinute(), 'reason' => 'concurrency test', 'configured_by_name' => 'test', 'created_at' => now()]);
        $f1 = $this->staff('financial'); $f2 = $this->staff('financial'); $u = $this->investor('1000.00', true);
        $r = $this->actingAs($u, 'sanctum')->postJson('/api/investor-investment/investor/withdrawals',
            ['amount' => 100, 'method' => 'usdt', 'wallet_address' => '0xabc', 'withdrawal_pin' => '1234', 'confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertStatus(201);
        $w = Withdrawal::findOrFail($r->json('withdrawal.id'));

        $st = $this->fireTogether([$this->job($f1, 'POST', "/api/financial/withdrawals/{$w->id}/approve"), $this->job($f2, 'POST', "/api/financial/withdrawals/{$w->id}/approve")]);
        $this->assertSame([200, 422], $this->sorted($st));
        $this->assertSame('900.00', $this->money($u->fresh()->balance), 'the fee is part of the gross debit, never a second debit');
        $this->assertSame(1, WithdrawalFeeEvent::where('withdrawal_id', $w->id)->where('event', 'collected')->count());
        $this->assertSame('collected', $w->fresh()->fee_status);
    }
}
