<?php
// Task 5 concurrency on REAL MySQL with REAL parallel processes (same harness as MySqlConcurrencyTest; see phpunit.mysql.xml for how to run).
// STATUS: written, NOT executed by the author (no PHP / MySQL in the authoring environment).
namespace Tests\MySql;

use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalFeeEvent;
use App\Services\Task5\ReferralRewardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MySqlTask5ConcurrencyTest extends MySqlTestCase
{
    private function approvedReward(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $fin = User::factory()->create(['role' => 'financial', 'status' => 'active']);
        $ref = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '0.00', 'referral_code' => 'MYSQLREF']);
        $new = User::factory()->create(['role' => 'investor', 'status' => 'active', 'registration_completed' => true, 'referred_by' => $ref->id, 'phone' => '+15550001111']);
        $new->forceFill(['kyc_status' => 'approved', 'kyc_verified' => true])->save();
        $svc = app(ReferralRewardService::class);
        $svc->savePolicy($admin, ['reward_amount' => '5.00', 'criteria' => ['require_kyc_approved' => true], 'policy_approved' => true, 'approval_reference' => 'M-1', 'reason' => 't']);
        $r = $svc->createForReferred($new);
        $svc->approve($svc->evaluate($r), $fin);
        return [$ref, $r->fresh(), $fin];
    }

    public function test_ten_simultaneous_credit_requests_credit_a_reward_exactly_once(): void
    {
        [$ref, $r, $fin] = $this->approvedReward();
        $token = $fin->createToken('t')->plainTextToken;
        $jobs = array_fill(0, 10, ['token' => $token, 'method' => 'POST', 'uri' => "/api/financial/referrals/{$r->id}/credit", 'body' => []]);
        $statuses = $this->fireTogether($jobs);
        $this->assertSame(1, count(array_filter($statuses, fn ($s) => $s === 200)), 'exactly one credit: ' . implode(',', $statuses));
        $this->assertSame('5.00', $this->money($ref->fresh()->balance));
        $this->assertSame(1, DB::table('balance_adjustments')->where('user_id', $ref->id)->where('type', 'add')->count());
        $this->assertSame('credited', $r->fresh()->status);
    }

    public function test_simultaneous_approve_and_reject_cannot_both_win(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $fin = User::factory()->create(['role' => 'financial', 'status' => 'active']);
        $ref = User::factory()->create(['role' => 'investor', 'status' => 'active', 'referral_code' => 'MYSQLRE2']);
        $new = User::factory()->create(['role' => 'investor', 'status' => 'active', 'registration_completed' => true, 'referred_by' => $ref->id, 'phone' => '+15550002222']);
        $new->forceFill(['kyc_status' => 'approved', 'kyc_verified' => true])->save();
        $svc = app(ReferralRewardService::class);
        $svc->savePolicy($admin, ['reward_amount' => '5.00', 'criteria' => ['require_kyc_approved' => true], 'policy_approved' => true, 'approval_reference' => 'M-1', 'reason' => 't']);
        $r = $svc->createForReferred($new);
        $t = $fin->createToken('t')->plainTextToken;
        $statuses = $this->fireTogether([
            ['token' => $t, 'method' => 'POST', 'uri' => "/api/financial/referrals/{$r->id}/approve", 'body' => []],
            ['token' => $t, 'method' => 'POST', 'uri' => "/api/financial/referrals/{$r->id}/reject", 'body' => ['reason' => 'dup account']],
        ]);
        $this->assertContains(200, $statuses);
        $final = $r->fresh()->status;
        $this->assertContains($final, ['approved', 'rejected']);
        $this->assertSame(1, DB::table('referral_reward_events')->where('reward_id', $r->id)->whereIn('event', ['approved', 'rejected'])->count());
    }

    public function test_simultaneous_withdrawal_requests_create_one_pending_withdrawal(): void
    {
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '1000.00', 'withdrawal_pin' => Hash::make('1234')]);
        $t = $u->createToken('t')->plainTextToken;
        $job = ['token' => $t, 'method' => 'POST', 'uri' => '/api/investor-investment/investor/withdrawals', 'body' => ['amount' => 100, 'method' => 'usdt', 'wallet_address' => '0xabc', 'withdrawal_pin' => '1234']];
        $statuses = $this->fireTogether(array_fill(0, 8, $job));
        $this->assertSame(1, count(array_filter($statuses, fn ($s) => $s === 201)), implode(',', $statuses));
        $this->assertSame(1, Withdrawal::where('user_id', $u->id)->count());
    }

    public function test_simultaneous_approvals_collect_the_fee_once_and_debit_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $fin = User::factory()->create(['role' => 'financial', 'status' => 'active']);
        app(\App\Services\Task5\WithdrawalFeeService::class)->savePolicy($admin, ['fee_type' => 'fixed', 'fixed_amount' => '5.00', 'is_active' => true, 'policy_approved' => true, 'approval_reference' => 'M', 'reason' => 't']);
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '1000.00', 'withdrawal_pin' => Hash::make('1234')]);
        $this->actingAs($u, 'sanctum')->postJson('/api/investor-investment/investor/withdrawals', ['amount' => 100, 'method' => 'usdt', 'wallet_address' => '0xabc', 'withdrawal_pin' => '1234', 'confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertCreated();
        $w = Withdrawal::firstOrFail(); $t = $fin->createToken('t')->plainTextToken;
        $statuses = $this->fireTogether(array_fill(0, 6, ['token' => $t, 'method' => 'POST', 'uri' => "/api/financial/withdrawals/{$w->id}/approve", 'body' => []]));
        $this->assertSame(1, count(array_filter($statuses, fn ($s) => $s === 200)), implode(',', $statuses));
        $this->assertSame('900.00', $this->money($u->fresh()->balance));
        $this->assertSame(1, WithdrawalFeeEvent::where('withdrawal_id', $w->id)->where('event', 'collected')->count());
    }

    public function test_decimal_columns_round_trip_exactly_on_mysql(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $p = app(\App\Services\Task5\WithdrawalFeeService::class)->savePolicy($admin, ['fee_type' => 'combined', 'fixed_amount' => '0.10', 'percentage' => '1.2345', 'is_active' => true, 'policy_approved' => true, 'approval_reference' => 'M', 'reason' => 't']);
        $q = (new \App\Services\Task5\WithdrawalFeeCalculator())->calculate('333.33', $p->fresh());
        $this->assertSame(['333.33', '4.21', '329.12'], [$q['gross'], $q['fee'], $q['net']]);   // 0.10 + 333.33 x 1.2345% (=4.11494...) = 4.21494 -> 4.21
    }
}
