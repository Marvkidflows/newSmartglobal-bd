<?php
// Task 5 shared helpers. Uses the in-memory SQLite test database only (never the main SSI database).
namespace Tests\Feature\Task5;

use App\Models\ReferralPolicy;
use App\Models\User;
use App\Models\WithdrawalFeePolicy;
use App\Services\Task5\ReferralRewardService;
use App\Services\Task5\WithdrawalFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

abstract class Task5TestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $financial;
    protected User $financial2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin      = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->financial  = User::factory()->create(['role' => 'financial', 'status' => 'active']);
        $this->financial2 = User::factory()->create(['role' => 'financial', 'status' => 'active']);
    }

    protected function investor(array $o = []): User
    {
        static $n = 0; $n++;
        return User::factory()->create($o + ['role' => 'investor', 'status' => 'active', 'balance' => '0.00', 'registration_completed' => true, 'registration_stage' => 4,
            'referral_code' => 'TC' . str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'phone' => '+1555000' . str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'kyc_status' => 'not_submitted']);
    }

    protected function approveReferralPolicy(array $criteria = ['require_kyc_approved' => true], string $amount = '5.00'): ReferralPolicy
    {
        // D-04: the real two-person flow — one Administrator drafts, a DIFFERENT Administrator approves.
        $draft = app(ReferralRewardService::class)->savePolicy($this->admin, ['reward_amount' => $amount, 'criteria' => $criteria, 'reason' => 'test policy']);
        return app(ReferralRewardService::class)->approveDraft($this->secondAdmin(), $draft->id, 'MGMT-TEST-001', 'test approval');
    }

    /** D-04: a second, different Administrator for approving drafts. */
    protected function secondAdmin(): User
    {
        static $n = 0; $n++;
        return User::factory()->create(['role' => 'admin', 'status' => 'active', 'email' => "second.admin.{$n}@ssi-test.test"]);
    }

    protected function kycApprove(User $u): void { $u->forceFill(['kyc_status' => 'approved', 'kyc_verified' => true])->save(); }

    protected function approveFee(string $type, array $v = []): WithdrawalFeePolicy
    {
        // D-04: draft by one Administrator, approval by a DIFFERENT Administrator.
        $draft = app(WithdrawalFeeService::class)->savePolicy($this->admin, $v + ['fee_type' => $type, 'is_active' => true, 'reason' => 'test fee']);
        return app(WithdrawalFeeService::class)->approveDraft($this->secondAdmin(), $draft->id, 'MGMT-FEE-001', 'test approval');
    }

    protected function withPin(User $u, string $pin = '1234', string $balance = '1000.00'): User
    {
        $u->forceFill(['withdrawal_pin' => Hash::make($pin), 'balance' => $balance])->save();
        return $u->fresh();
    }

    protected function fin(User $u) { return $this->actingAs($u, 'sanctum'); }
}
