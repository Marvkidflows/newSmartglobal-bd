<?php
// Task 5 - role/permission matrix, enforced on the BACKEND for every new financial endpoint.
// STATUS: written, NOT executed by the author (no PHP in the authoring environment) - see docs/task5/TEST_EXECUTION_REPORT.md.
// NOTE: the Task 2 "Registration Team" role does not exist in this codebase yet (Task 2 is delivered separately). Until it lands, a registration-team user
// would be any non-financial/non-admin role; the MarvFlow staff role and the investor role stand in here, and every Task 5 gate is default-deny.
namespace Tests\Feature\Task5;

use App\Models\ReferralReward;
use App\Models\User;
use App\Services\Task5\ReferralRewardService;

class Task5PermissionsTest extends Task5TestCase
{
    private function endpoints(): array
    {
        $inv = $this->investor(); $n = $this->investor(['referred_by' => $inv->id]);
        $r = app(ReferralRewardService::class)->createForReferred($n);
        $w = \App\Models\Withdrawal::create(['user_id' => $inv->id, 'amount' => 20, 'method' => 'usdt', 'account_details' => '{}', 'status' => 'pending']);
        return [
            ['GET',  '/api/financial/referrals/summary', []], ['GET', '/api/financial/referrals', []], ['GET', "/api/financial/referrals/{$r->id}", []], ['GET', '/api/financial/referrals-export', []],
            ['POST', "/api/financial/referrals/{$r->id}/evaluate", []], ['POST', "/api/financial/referrals/{$r->id}/approve", []], ['POST', "/api/financial/referrals/{$r->id}/reject", ['reason' => 'x y z']],
            ['POST', "/api/financial/referrals/{$r->id}/credit", []], ['POST', "/api/financial/referrals/{$r->id}/flag", ['reason' => 'x y z']],
            ['GET',  '/api/financial/referral-policy', []], ['POST', '/api/financial/referral-policy', ['reward_amount' => 5, 'criteria' => ['require_kyc_approved' => true], 'reason' => 'abc']],
            ['GET',  '/api/financial/withdrawal-fees/policy', []], ['POST', '/api/financial/withdrawal-fees/policy', ['fee_type' => 'none', 'reason' => 'abc']],
            ['GET',  '/api/financial/withdrawal-fees/summary', []], ['GET', '/api/financial/withdrawal-fees/withdrawals', []], ['GET', '/api/financial/withdrawal-fees/reconciliation', []],
            ['GET',  "/api/financial/withdrawal-fees/withdrawals/{$w->id}/events", []], ['POST', "/api/financial/withdrawal-fees/withdrawals/{$w->id}/adjust", ['action' => 'review', 'reason' => 'abc']],
        ];
    }

    public function test_guests_get_401_on_every_task5_financial_endpoint(): void
    {
        foreach ($this->endpoints() as [$m, $u, $b]) $this->json($m, $u, $b)->assertStatus(401);
    }

    public function test_investors_and_other_department_staff_get_403_on_every_task5_financial_endpoint(): void
    {
        $eps = $this->endpoints();
        $marv = User::factory()->create(['role' => 'marvflow_member', 'status' => 'active']);
        foreach ([$this->investor(), $marv] as $who) {
            foreach ($eps as [$m, $u, $b]) $this->fin($who)->json($m, $u, $b)->assertStatus(403, "{$who->role} {$m} {$u}");
        }
    }

    public function test_financial_and_admin_pass_the_gates_on_read_endpoints(): void
    {
        foreach ([$this->financial, $this->admin] as $who) {
            foreach (['/api/financial/referrals/summary', '/api/financial/referrals', '/api/financial/referral-policy', '/api/financial/withdrawal-fees/policy', '/api/financial/withdrawal-fees/summary',
                '/api/financial/withdrawal-fees/withdrawals', '/api/financial/withdrawal-fees/reconciliation'] as $u) $this->fin($who)->getJson($u)->assertOk();
        }
    }

    public function test_suspended_financial_staff_are_refused(): void
    {
        $f = User::factory()->create(['role' => 'financial', 'status' => 'suspended']);
        $this->fin($f)->getJson('/api/financial/referrals/summary')->assertStatus(403);
    }

    public function test_investors_cannot_reach_withdrawal_fee_configuration_or_alter_a_confirmed_fee(): void
    {
        $inv = $this->investor();
        $this->fin($inv)->postJson('/api/financial/withdrawal-fees/policy', ['fee_type' => 'fixed', 'fixed_amount' => 99, 'reason' => 'abc'])->assertStatus(403);
        $this->fin($inv)->postJson('/api/admin/withdrawals/1/approve')->assertStatus(403);
        $this->assertSame('none', \App\Models\WithdrawalFeePolicy::current()->fee_type);
    }

    public function test_investor_endpoints_do_not_leak_other_investors_data(): void
    {
        $a = $this->investor(); $b = $this->investor(['referred_by' => $a->id]); app(ReferralRewardService::class)->createForReferred($b);
        $this->assertStringNotContainsString($a->email, json_encode($this->fin($b)->getJson('/api/investor-investment/investor/referrals')->json()));
    }
}
