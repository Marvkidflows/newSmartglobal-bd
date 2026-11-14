<?php
// LOCATION: tests/MySql/MySqlConcurrencyTest.php
//
// F-01 / F-03 acceptance tests on REAL MySQL with REAL parallel processes (SQLite cannot exercise row locks). Each request runs in its own OS
// process (tests/Support/concurrent_worker.php) through the application's HTTP kernel and all are released at the same instant.
// STATUS: written, NOT executed by the author (no PHP/MySQL in the authoring environment). Run exactly as documented in phpunit.mysql.xml.
//
// These tests commit real rows, so they use DatabaseMigrations (migrate:fresh) and therefore REFUSE to run unless DB_DATABASE contains "test".

namespace Tests\MySql;

use App\Models\BalanceAdjustment;
use App\Models\InvestmentAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MySqlConcurrencyTest extends MySqlTestCase
{
    public function test_ten_simultaneous_investments_cannot_spend_more_than_the_balance(): void
    {
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '100.00']);
        $plan = $this->planId(); $token = $u->createToken('t')->plainTextToken;
        $jobs = array_fill(0, 10, ['token' => $token, 'method' => 'POST', 'uri' => '/api/investor-investment/investor/investments', 'body' => ['plan_id' => $plan, 'amount' => 30]]);

        $statuses = $this->fireTogether($jobs);

        $ok = count(array_filter($statuses, fn ($s) => $s === 201));
        $this->assertSame(3, $ok, 'exactly floor(100/30)=3 may succeed; got statuses ' . implode(',', $statuses));
        $this->assertSame(7, count(array_filter($statuses, fn ($s) => $s === 422)), 'the rest are clean 422 refusals: ' . implode(',', $statuses));
        $this->assertSame('10.00', number_format((float) $u->fresh()->balance, 2, '.', ''));
        $this->assertSame(3, InvestmentAccount::where('user_id', $u->id)->count());
        $this->assertSame('90.00', number_format((float) InvestmentAccount::where('user_id', $u->id)->sum('amount'), 2, '.', ''), 'investment total == total debited');
    }

    public function test_two_simultaneous_full_balance_investments_produce_exactly_one_position(): void
    {
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '100.00']);
        $plan = $this->planId(); $token = $u->createToken('t')->plainTextToken;
        $job = ['token' => $token, 'method' => 'POST', 'uri' => '/api/investor-investment/investor/investments', 'body' => ['plan_id' => $plan, 'amount' => 100]];
        $statuses = $this->fireTogether([$job, $job]);
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $this->assertSame('0.00', number_format((float) $u->fresh()->balance, 2, '.', ''));
        $this->assertSame(1, InvestmentAccount::where('user_id', $u->id)->count());
    }

    public function test_admin_adjustments_racing_investments_preserve_the_arithmetic_and_the_audit_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '50.00']);
        $plan = $this->planId();
        $adminTok = $admin->createToken('a')->plainTextToken; $invTok = $u->createToken('i')->plainTextToken;

        $jobs = [];
        for ($i = 0; $i < 8; $i++) {
            $jobs[] = ['token' => $adminTok, 'method' => 'POST', 'uri' => "/api/admin/users/{$u->id}/balance",
                       'body' => ['type' => 'add', 'amount' => 5, 'reason' => "race add {$i}"], 'headers' => ['Idempotency-Key' => "race-add-key-{$i}-xx"]];
            $jobs[] = ['token' => $invTok, 'method' => 'POST', 'uri' => '/api/investor-investment/investor/investments', 'body' => ['plan_id' => $plan, 'amount' => 10]];
        }
        $this->fireTogether($jobs);

        $k = InvestmentAccount::where('user_id', $u->id)->count();                       // successful investments
        $final = (float) $u->fresh()->balance;
        $this->assertEqualsWithDelta(50 + 8 * 5 - 10 * $k, $final, 0.001, 'final balance == start + adjustments − investments (no lost update)');
        $this->assertGreaterThanOrEqual(0.0, $final);
        $this->assertSame(8, BalanceAdjustment::where('user_id', $u->id)->count(), 'every adjustment has its own audit row');
        foreach (BalanceAdjustment::where('user_id', $u->id)->get() as $a) {
            $this->assertEqualsWithDelta((float) $a->balance_before + 5, (float) $a->balance_after, 0.001, 'before/after recorded under the lock');
        }
    }

    public function test_the_same_idempotency_key_submitted_in_parallel_adjusts_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '10.00']);
        $tok = $admin->createToken('a')->plainTextToken;
        $job = ['token' => $tok, 'method' => 'POST', 'uri' => "/api/admin/users/{$u->id}/balance", 'body' => ['type' => 'add', 'amount' => 5, 'reason' => 'dup'], 'headers' => ['Idempotency-Key' => 'same-key-parallel-1']];
        $this->fireTogether(array_fill(0, 6, $job));
        $this->assertSame('15.00', number_format((float) $u->fresh()->balance, 2, '.', ''));
        $this->assertSame(1, BalanceAdjustment::where('user_id', $u->id)->count());
    }
}
