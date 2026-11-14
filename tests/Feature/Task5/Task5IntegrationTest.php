<?php
// Task 5 - end-to-end workflows. STATUS: written, NOT executed by the author (no PHP in the authoring environment).
namespace Tests\Feature\Task5;

use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\OtpService;
use App\Services\Task5\WithdrawalFeeService;

class Task5IntegrationTest extends Task5TestCase
{
    public function test_referral_link_to_credited_reward_to_fee_bearing_withdrawal_and_reconciliation(): void
    {
        $this->approveReferralPolicy(['require_kyc_approved' => true]);
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $referrer = $this->withPin($this->investor(['referral_code' => 'E2EREF22']), '1234', '95.00');

        // 1. link visit, registration through the link, completion
        $this->postJson('/api/referrals/visit', ['code' => 'E2EREF22'])->assertOk();
        $reg = ['full_name' => 'E2E Friend', 'email' => 'friend@example.com', 'country_iso2' => 'US', 'phone' => '2015550177',
            'password' => 'password123', 'password_confirmation' => 'password123', 'referral_code' => 'E2EREF22'];
        \Illuminate\Support\Facades\Mail::fake();
        $this->postJson('/api/register/stage1', $reg)->assertOk();   // verify-first contract: the account is created after the emailed code
        $code = \Illuminate\Support\Facades\Mail::sent(\App\Mail\RegistrationEmailCodeMail::class)->last()->code;
        $this->postJson('/api/register/verify', $reg + ['code' => $code])->assertOk();
        $friend = User::where('email', 'friend@example.com')->first();
        $friend->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($friend, 'sanctum')->postJson('/api/register/stage4')->assertStatus(201);
        $reward = ReferralReward::where('referred_id', $friend->id)->firstOrFail();
        $this->assertSame('pending', $reward->status);

        // 2. registration alone does not qualify
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$reward->id}/approve")->assertStatus(422);

        // 3. KYC approved -> evaluate -> approve -> credit (different staff member)
        $this->kycApprove($friend);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$reward->id}/evaluate")->assertOk()->assertJsonPath('reward.eligibility', 'eligible');
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$reward->id}/approve", ['note' => 'ok'])->assertOk();
        $this->fin($this->financial2)->postJson("/api/financial/referrals/{$reward->id}/credit")->assertOk();
        $this->assertSame('100.00', number_format((float) $referrer->fresh()->balance, 2, '.', ''));
        $j = $this->fin($referrer)->getJson('/api/investor-investment/investor/referrals')->json();
        $this->assertSame(['5.00', true], [$j['totals']['credited'], $j['referrals'][0]['paid']]);

        // 4. withdraw the whole $100 with a $5 fee: disclosed, confirmed, approved
        $q = $this->fin($referrer)->getJson('/api/investor-investment/investor/withdrawals/quote?amount=100')->json('quote');
        $this->assertSame(['100.00', '5.00', '95.00'], [$q['gross'], $q['fee'], $q['net']]);
        $this->fin($referrer)->postJson('/api/investor-investment/investor/withdrawals', ['amount' => 100, 'method' => 'usdt', 'wallet_address' => '0xabc', 'withdrawal_pin' => '1234',
            'confirmed_fee' => $q['fee'], 'confirmed_net' => $q['net']])->assertCreated();
        $w = Withdrawal::firstOrFail();
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$w->id}/approve")->assertOk();

        // 5. accounting is consistent
        $this->assertSame('0.00', number_format((float) $referrer->fresh()->balance, 2, '.', ''));
        $s = app(WithdrawalFeeService::class)->summary();
        $this->assertSame(['100.00', '5.00', '95.00', '5.00'], [$s['amounts']['gross_requested'], $s['amounts']['fees_charged'], $s['amounts']['net_payable'], $s['amounts']['fees_collected']]);
        $this->assertSame([], $s['reconciliation']);
    }
}
