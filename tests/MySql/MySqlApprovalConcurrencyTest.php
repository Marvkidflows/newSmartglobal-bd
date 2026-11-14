<?php
// LOCATION: tests/MySql/MySqlApprovalConcurrencyTest.php
//
// Correction-pass MySQL concurrency scenarios (R7/R8/F-14): approvals, idempotency, deposits/withdrawals vs wallet changes, task completion,
// and investment correction / profit adjustment / reversal racing settlement. REAL parallel OS processes against REAL MySQL.
// STATUS: written, NOT executed by the author (no PHP/MySQL available). Run per phpunit.mysql.xml. SQLite cannot prove any of this.
// Assertions are INVARIANTS (e.g. "exactly one approval wins", "balance == start − Σ successful operations"), not a fixed winner,
// because which racer wins is non-deterministic.

namespace Tests\MySql;

use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\FinancialApprovalEvent;
use App\Models\FinancialApprovalRequest as Req;
use App\Models\FinancialAuditLog;
use App\Models\InvestmentAccount;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Financial\FinancialApprovalService;

class MySqlApprovalConcurrencyTest extends MySqlTestCase
{
    protected function staff(string $role): User { return User::factory()->create(['role' => $role, 'status' => 'active']); }
    protected function investor(string $balance): User { return User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => $balance]); }
    protected function tok(User $u): string { return $u->createToken('t')->plainTextToken; }
    protected function job(User $u, string $method, string $uri, array $body = [], array $headers = []): array
    {
        return ['token' => $this->tok($u), 'method' => $method, 'uri' => $uri, 'body' => $body, 'headers' => $headers];
    }
    protected function maturedInvestment(User $u, int $amount = 1000): InvestmentAccount
    {
        return InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $this->planId(), 'amount' => $amount, 'profit_percentage' => 10,
            'expected_profit' => $amount / 10, 'total_return' => $amount * 1.1, 'status' => 'active', 'start_date' => now()->subDays(31)->toDateString(),
            'end_date' => now()->subDay()->toDateString(), 'remaining_days' => 0]);
    }
    protected function assertCoherent(InvestmentAccount $i): void
    {
        $i = $i->fresh();
        $exp = round((float) $i->amount * (float) $i->profit_percentage / 100, 2);
        $this->assertEqualsWithDelta($exp, (float) $i->expected_profit, 0.005, 'expected_profit must equal amount × rate (no torn update)');
        $this->assertEqualsWithDelta((float) $i->amount + $exp, (float) $i->total_return, 0.005, 'total_return must equal amount + expected_profit');
    }

    // ── approvals ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_two_approvers_deciding_the_same_request_execute_it_exactly_once(): void
    {
        $admin = $this->staff('admin'); $admin2 = $this->staff('admin'); $fin2 = $this->staff('financial'); $u = $this->investor('20000.00');
        $req = app(FinancialApprovalService::class)->submit('balance_adjust', ['user_id' => $u->id, 'type' => 'deduct', 'amount' => '15000.00', 'reason' => 'race', 'investor_only' => false], $admin);

        $st = $this->fireTogether([$this->job($admin2, 'POST', "/api/financial/approvals/{$req->id}/approve"), $this->job($fin2, 'POST', "/api/financial/approvals/{$req->id}/approve")]);

        $this->assertSame([200, 422], $this->sorted($st));
        $this->assertSame('5000.00', $this->money($u->fresh()->balance), 'deducted once, not twice');
        $this->assertSame(1, BalanceAdjustment::where('user_id', $u->id)->count());
        $this->assertSame('executed', Req::find($req->id)->status);
        $this->assertSame(1, FinancialApprovalEvent::where('approval_request_id', $req->id)->where('event', 'executed')->count());
    }

    public function test_parallel_identical_submissions_create_exactly_one_pending_request(): void
    {
        $admin = $this->staff('admin'); $u = $this->investor('100.00');
        $job = $this->job($admin, 'POST', "/api/admin/users/{$u->id}/balance", ['type' => 'reset', 'reason' => 'Account closure review']);
        $st = $this->fireTogether(array_fill(0, 6, $job));
        $this->assertSame([202, 202, 202, 202, 202, 202], $this->sorted($st));
        $this->assertSame(1, Req::count(), 'the unique open_key admits one pending request per identical payload');
        $this->assertSame('100.00', $this->money($u->fresh()->balance));
    }

    public function test_one_idempotency_key_with_two_different_payloads_admits_exactly_one(): void
    {
        $admin = $this->staff('admin'); $u = $this->investor('100.00'); $h = ['Idempotency-Key' => 'same-key-two-payloads'];
        $a = $this->job($admin, 'POST', "/api/admin/users/{$u->id}/balance", ['type' => 'reset', 'reason' => 'Payload A reason here'], $h);
        $b = $this->job($admin, 'POST', "/api/admin/users/{$u->id}/balance", ['type' => 'reset', 'reason' => 'Payload B reason here'], $h);
        $st = $this->fireTogether([$a, $a, $a, $b, $b, $b]);
        $this->assertSame(1, Req::count());
        $this->assertSame(3, count(array_filter($st, fn ($s) => $s === 202)), implode(',', $st));
        $this->assertSame(3, count(array_filter($st, fn ($s) => $s === 409)), implode(',', $st));
    }

    public function test_json_payload_hash_round_trips_through_mysql_json_with_nested_keys(): void
    {
        $admin = $this->staff('financial');
        $payload = ['z_last' => 1, 'investment_id' => 7, 'user_id' => 9, 'before' => ['total_return' => '1100.00', 'amount' => '1000.00', 'profit_percentage' => '10.00'],
                    'proposed' => ['profit_percentage' => '12.00', 'amount' => '1000.00'], 'reason' => 'Round trip', 'flag' => true, 'ids' => [3, 1, 2]];
        $r = app(FinancialApprovalService::class)->submit('investment_correction', $payload, $admin);
        $stored = Req::find($r->id);
        $this->assertSame($stored->payload_hash, FinancialApprovalService::hash((array) $stored->payload), 'MySQL JSON re-orders object keys; the canonical hash must not care');
        $this->assertSame([3, 1, 2], $stored->payload['ids'], 'list order is preserved');
    }

    // ── deposits / withdrawals / adjustments ───────────────────────────────────────────────────────────────────────────────────────
    public function test_a_deposit_approved_twice_at_once_credits_once(): void
    {
        $f1 = $this->staff('financial'); $f2 = $this->staff('financial'); $u = $this->investor('0.00');
        $d = Deposit::create(['user_id' => $u->id, 'amount' => 500, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-CONC-1', 'status' => 'pending']);
        $body = ['confirm_usd' => true, 'reason' => 'Received and cleared in USD (concurrency test)'];
        $st = $this->fireTogether([$this->job($f1, 'POST', "/api/financial/deposits/{$d->id}/approve", $body), $this->job($f2, 'POST', "/api/financial/deposits/{$d->id}/approve", $body)]);
        $this->assertSame(1, count(array_filter($st, fn ($s) => $s === 200)), implode(',', $st));
        $this->assertSame('500.00', $this->money($u->fresh()->balance));
        $this->assertSame(1, FinancialAuditLog::where('action', 'deposit.approved')->count());
    }

    public function test_withdrawal_and_investment_cannot_both_spend_the_same_cash(): void
    {
        $fin = $this->staff('financial'); $u = $this->investor('100.00'); $plan = $this->planId();
        $w = Withdrawal::create(['user_id' => $u->id, 'amount' => 80, 'method' => 'bank_transfer', 'account_details' => json_encode(['bank_name' => 'T']), 'status' => 'pending']);
        $st = $this->fireTogether([$this->job($fin, 'POST', "/api/financial/withdrawals/{$w->id}/approve"),
            $this->job($u, 'POST', '/api/investor-investment/investor/investments', ['plan_id' => $plan, 'amount' => 50])]);
        $wOk = $w->fresh()->status === 'approved' ? 1 : 0; $inv = InvestmentAccount::where('user_id', $u->id)->count();
        $this->assertFalse($wOk === 1 && $inv === 1, 'both succeeded (130 > 100): ' . implode(',', $st));
        $this->assertGreaterThanOrEqual(0.0, (float) $u->fresh()->balance);
        $this->assertSame($this->money(100 - 80 * $wOk - 50 * $inv), $this->money($u->fresh()->balance));
    }

    public function test_a_balance_deduction_and_a_withdrawal_approval_cannot_overdraw(): void
    {
        $admin = $this->staff('admin'); $fin = $this->staff('financial'); $u = $this->investor('100.00');
        $w = Withdrawal::create(['user_id' => $u->id, 'amount' => 80, 'method' => 'bank_transfer', 'account_details' => json_encode(['bank_name' => 'T']), 'status' => 'pending']);
        $st = $this->fireTogether([$this->job($admin, 'POST', "/api/admin/users/{$u->id}/balance", ['type' => 'deduct', 'amount' => 50, 'reason' => 'race deduct']),
            $this->job($fin, 'POST', "/api/financial/withdrawals/{$w->id}/approve")]);
        // INTEGRATION-REVIEW CORRECTION (counting only; no invariant was relaxed). Approving a withdrawal ALSO writes its own BalanceAdjustment row of
        // type 'deduct' ("Withdrawal #N approved", admin_id = the approving user). The original line counted every 'deduct' row for the investor, so
        // whenever the approval won and the administrator's 50 deduction was (correctly) refused with 422, the approval's own row made $adj = 1 and the test
        // reported "both succeeded" and mis-computed the expected balance (100 - 80 - 50) although the balance was a correct 20.00. Count only the
        // administrator's adjustment. A genuine overdraw (both succeed) still fails assertGreaterThanOrEqual(0.0, balance) and the balance equation below.
        $wOk = $w->fresh()->status === 'approved' ? 1 : 0;
        $adj = BalanceAdjustment::where('user_id', $u->id)->where('type', 'deduct')->where('admin_id', $admin->id)->count();
        $this->assertFalse($wOk === 1 && $adj === 1, 'both succeeded (130 > 100): ' . implode(',', $st));
        $this->assertGreaterThanOrEqual(0.0, (float) $u->fresh()->balance);
        $this->assertSame($this->money(100 - 80 * $wOk - 50 * $adj), $this->money($u->fresh()->balance));
    }

    public function test_two_simultaneous_task_completions_credit_the_wallet_once(): void
    {
        $admin = $this->staff('admin'); $u = $this->investor('1000.00');
        $type = TaskType::create(['key' => 'activity', 'label' => 'Activity', 'requires_amount' => true, 'is_active' => true]);
        $task = Task::create(['task_code' => 'T' . uniqid(), 'task_type_id' => $type->id, 'title' => 'Race', 'status' => 'active', 'expires_at' => now()->addHours(2)]);
        $a = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $u->id, 'status' => 'under_review', 'profit_loss' => 135]);
        $job = $this->job($admin, 'POST', "/api/admin/tasks/{$a->id}/complete");
        $st = $this->fireTogether([$job, $job]);
        $this->assertSame([200, 422], $this->sorted($st));
        $this->assertSame('1135.00', $this->money($u->fresh()->balance));
        $this->assertSame(1, BalanceAdjustment::where('user_id', $u->id)->count());
    }

    // ── corrections / profit adjustments racing settlement and each other ────────────────────────────────────────────────────────────
    public function test_an_investment_correction_racing_settlement_is_all_or_nothing(): void
    {
        $fin = $this->staff('financial'); $admin = $this->staff('admin'); $u = $this->investor('0.00'); $inv = $this->maturedInvestment($u);
        $rid = $this->actingAs($fin, 'sanctum')->postJson("/api/financial/investments/{$inv->id}/correct",
            ['amount' => 2000, 'reason' => 'Race against settlement', 'authorization_reference' => 'CASE-R1'])->assertStatus(202)->json('approval_request_id');
        $this->app['auth']->forgetGuards();

        $this->fireTogether([$this->job($admin, 'POST', "/api/financial/approvals/{$rid}/approve"), ['token' => 'x', 'method' => 'ARTISAN', 'uri' => 'investments:update-maturity', 'body' => []]]);

        $req = Req::find($rid); $i = $inv->fresh();
        $this->assertSame('completed', $i->status, 'settlement ran exactly once');
        $this->assertSame($this->money($i->expected_profit), $this->money($u->fresh()->balance), 'settlement paid exactly the final record\'s expected_profit, once');
        $this->assertSame($req->status === 'executed', (float) $i->amount === 2000.0, "executed ⇔ applied (request {$req->status}, amount {$i->amount})");
        $this->assertContains($req->status, ['executed', 'failed']);
        $this->assertCoherent($i);
    }

    public function test_an_investment_correction_racing_a_profit_adjustment_leaves_a_coherent_record(): void
    {
        $fin = $this->staff('financial'); $admin = $this->staff('admin'); $admin2 = $this->staff('admin'); $fin2 = $this->staff('financial');
        $u = $this->investor('0.00');
        $inv = InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $this->planId(), 'amount' => 1000, 'profit_percentage' => 10, 'expected_profit' => 100, 'total_return' => 1100,
            'status' => 'active', 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(), 'remaining_days' => 30]);
        $c = $this->actingAs($fin, 'sanctum')->postJson("/api/financial/investments/{$inv->id}/correct", ['amount' => 2000, 'reason' => 'Concurrent correction', 'authorization_reference' => 'CASE-R2'])->assertStatus(202)->json('approval_request_id');
        $this->app['auth']->forgetGuards();
        $p = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/global/profit-adjustments', ['scope' => 'global', 'percentage_change' => 2, 'reason' => 'Board-approved rate revision', 'authorization_reference' => 'BOARD-R2'])->assertStatus(202)->json('approval_request_id');
        $this->app['auth']->forgetGuards();

        $this->fireTogether([$this->job($fin2, 'POST', "/api/financial/approvals/{$c}/approve"), $this->job($admin2, 'POST', "/api/financial/approvals/{$p}/approve")]);

        $this->assertContains(Req::find($c)->status, ['executed', 'failed']);
        $this->assertContains(Req::find($p)->status, ['executed', 'failed']);
        $this->assertCoherent($inv);
    }

    public function test_a_profit_reversal_racing_settlement_never_alters_a_settled_record(): void
    {
        $admin = $this->staff('admin'); $admin2 = $this->staff('admin'); $u = $this->investor('0.00');
        $inv = InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $this->planId(), 'amount' => 1000, 'profit_percentage' => 10, 'expected_profit' => 100, 'total_return' => 1100,
            'status' => 'active', 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(), 'remaining_days' => 30]);
        $p = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/global/profit-adjustments', ['scope' => 'global', 'percentage_change' => 2, 'reason' => 'Board-approved rate revision', 'authorization_reference' => 'BOARD-R3'])->assertStatus(202)->json('approval_request_id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin2, 'sanctum')->postJson("/api/financial/approvals/{$p}/approve")->assertOk();      // sequential: rate is now 12%
        $this->app['auth']->forgetGuards();
        $this->assertEquals(12, $inv->fresh()->profit_percentage);

        $adj = \App\Models\ProfitAdjustment::firstOrFail();
        $inv->forceFill(['end_date' => now()->subDay()->toDateString()])->save();                               // now matured → settlement will pay
        $r = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/global/profit-adjustments/{$adj->id}/reverse", ['reason' => 'Authorisation withdrawn by Board'])->assertStatus(202)->json('approval_request_id');
        $this->app['auth']->forgetGuards();

        $this->fireTogether([$this->job($admin2, 'POST', "/api/financial/approvals/{$r}/approve"), ['token' => 'x', 'method' => 'ARTISAN', 'uri' => 'investments:update-maturity', 'body' => []]]);

        $i = $inv->fresh();
        $this->assertSame('completed', $i->status);
        $this->assertCoherent($i);
        // Whatever the order, what was PAID must equal the record's final expected_profit: a reversal must not rewrite a record that already settled.
        $this->assertSame($this->money($i->expected_profit), $this->money($u->fresh()->balance), "paid {$u->fresh()->balance} but record says {$i->expected_profit}");
    }
}
