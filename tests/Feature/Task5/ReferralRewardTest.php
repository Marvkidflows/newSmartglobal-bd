<?php
// Task 5 - referral attribution, eligibility, fraud flags, approval, crediting, reversal and the investor view.
// STATUS: written, NOT executed by the author (no PHP in the authoring environment) - see docs/task5/TEST_EXECUTION_REPORT.md.
namespace Tests\Feature\Task5;

use App\Models\ReferralEvent;
use App\Models\ReferralPolicy;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\Financial\BalanceAdjustmentService;
use App\Services\OtpService;
use App\Services\Task5\ReferralRewardService;
use App\Services\Task5\ReferralService;

class ReferralRewardTest extends Task5TestCase
{
    private function svc(): ReferralRewardService { return app(ReferralRewardService::class); }
    private function bal(User $u): string { return number_format((float) $u->fresh()->balance, 2, '.', ''); }

    /** @return array{0:User,1:User,2:ReferralReward} */
    private function pair(array $referred = []): array
    {
        $ref = $this->investor(); $new = $this->investor($referred + ['referred_by' => $ref->id]);
        return [$ref, $new, $this->svc()->createForReferred($new)];
    }

    private function eligiblePair(): array
    {
        $this->approveReferralPolicy();
        [$ref, $new, $r] = $this->pair();
        $this->kycApprove($new);
        $this->svc()->evaluate($r);
        return [$ref, $new, $r->fresh()];
    }

    // ── configuration defaults ───────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_default_policy_is_usd_5_and_not_approved(): void
    {
        $p = ReferralPolicy::current();
        $this->assertSame(['5.00', 'USD', false], [(string) $p->reward_amount, $p->currency, $p->policy_approved]);
        $this->assertFalse(collect($p->criteria)->only(ReferralRewardService::CRITERIA_KEYS)->contains(true), 'no eligibility rule is silently active');
    }

    // ── codes + attribution ──────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_generated_referral_codes_are_unique(): void
    {
        $codes = [];
        for ($i = 0; $i < 60; $i++) { $c = app(ReferralService::class)->newCode(); $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $c); $codes[] = $c; User::factory()->create(['role' => 'investor', 'referral_code' => $c]); }
        $this->assertCount(60, array_unique($codes));
    }

    public function test_ensure_code_creates_missing_codes_but_never_overwrites(): void
    {
        $u = User::factory()->create(['role' => 'investor', 'referral_code' => null]);
        $code = app(ReferralService::class)->ensureCode($u);
        $this->assertNotEmpty($code);
        $this->assertSame($code, app(ReferralService::class)->ensureCode($u->fresh()));
        $keep = $this->investor(['referral_code' => 'KEEPME22']);
        $this->assertSame('KEEPME22', app(ReferralService::class)->ensureCode($keep));
    }

    // CONTRACT CHANGE (enumeration closure): the account is created at /register/verify after the emailed code. Same inputs and outcome as before.
    private function stage1(string $email, ?string $ref, string $phone = '2015550123')
    {
        $payload = array_filter(['full_name' => 'New Person', 'email' => $email, 'country_iso2' => 'US', 'phone' => $phone,
            'password' => 'password123', 'password_confirmation' => 'password123', 'referral_code' => $ref]);
        \Illuminate\Support\Facades\Mail::fake();
        $this->postJson('/api/register/stage1', $payload)->assertOk();
        $m = \Illuminate\Support\Facades\Mail::sent(\App\Mail\RegistrationEmailCodeMail::class)->last();
        return $this->postJson('/api/register/verify', $payload + ['code' => $m ? $m->code : '000000']);
    }

    public function test_registration_through_a_referral_code_records_the_referrer_and_a_started_event(): void
    {
        $ref = $this->investor(['referral_code' => 'REFCODE2']);
        $this->stage1('a@example.com', 'REFCODE2')->assertOk();
        $u = User::where('email', 'a@example.com')->first();
        $this->assertSame($ref->id, $u->referred_by);
        $this->assertNotEmpty($u->referral_code);
        $this->assertNotSame('REFCODE2', $u->referral_code);
        $this->assertDatabaseHas('referral_events', ['event' => 'registration_started', 'referrer_id' => $ref->id, 'referred_id' => $u->id]);
        $this->assertSame(0, ReferralReward::count(), 'registration started is not a referral reward');
    }

    public function test_an_unknown_referral_code_is_ignored_without_failing_registration(): void
    {
        $this->stage1('b@example.com', 'NOSUCHCD')->assertOk();
        $this->assertNull(User::where('email', 'b@example.com')->value('referred_by'));
    }

    public function test_attribution_survives_onboarding_and_completion_creates_one_pending_reward(): void
    {
        $ref = $this->investor(['referral_code' => 'REFCODE3']);
        $this->stage1('c@example.com', 'REFCODE3')->assertOk();
        $u = User::where('email', 'c@example.com')->first();
        $u->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($u, 'sanctum')->postJson('/api/register/stage4', ['referral_code' => 'SOMEONEELSE'])->assertStatus(201);   // a stage-4 code must NOT re-attribute
        $this->actingAs($u, 'sanctum')->postJson('/api/register/stage4')->assertStatus(201);                                       // repeated completion: still one reward
        $this->assertSame($ref->id, $u->fresh()->referred_by);
        $r = ReferralReward::where('referred_id', $u->id)->get();
        $this->assertCount(1, $r);
        $this->assertSame(['pending', '5.00'], [$r[0]->status, (string) $r[0]->amount]);
        $this->assertDatabaseHas('referral_events', ['event' => 'registration_completed', 'referred_id' => $u->id]);
    }

    public function test_an_investor_cannot_change_their_own_referral_attribution(): void
    {
        $a = $this->investor(); $b = $this->investor(); $c = $this->investor(['referred_by' => $a->id]);
        $this->actingAs($c, 'sanctum')->putJson('/api/investor-investment/investor/profile', ['referred_by' => $b->id, 'referral_code' => 'HIJACK22', 'name' => 'X Y'])->assertStatus(200);
        $c = $c->fresh();
        $this->assertSame($a->id, $c->referred_by);
        $this->assertNotSame('HIJACK22', $c->referral_code);
    }

    public function test_link_visit_beacon_is_public_identical_for_unknown_codes_and_logs_only_valid_ones(): void
    {
        $ref = $this->investor(['referral_code' => 'VISIT222']);
        $this->postJson('/api/referrals/visit', ['code' => 'visit222'])->assertOk()->assertJson(['success' => true]);
        $this->postJson('/api/referrals/visit', ['code' => 'NOPE9999'])->assertOk()->assertJson(['success' => true]);
        $this->postJson('/api/referrals/visit', ['code' => 'bad code!'])->assertStatus(422);
        $this->assertSame(1, ReferralEvent::where('event', 'link_visited')->count());
    }

    // ── eligibility ──────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_registration_alone_never_qualifies_and_nothing_can_be_approved_while_the_policy_is_unapproved(): void
    {
        [$ref, $new, $r] = $this->pair();
        $this->svc()->evaluate($r);
        $this->assertSame('policy_not_approved', $r->fresh()->eligibility);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/approve")->assertStatus(422);
        $this->assertSame('pending', $r->fresh()->status);
    }

    public function test_the_policy_cannot_be_approved_with_no_criteria_because_registration_alone_must_not_qualify(): void
    {
        $this->fin($this->admin)->postJson('/api/financial/referral-policy', ['reward_amount' => 5, 'criteria' => ['require_kyc_approved' => false], 'policy_approved' => true, 'approval_reference' => 'M-1', 'reason' => 'test reason'])->assertStatus(422);
    }

    public function test_configured_criteria_are_enforced(): void
    {
        $this->approveReferralPolicy(['require_kyc_approved' => true, 'require_approved_deposit' => true, 'require_investment' => true]);
        [$ref, $new, $r] = $this->pair();
        $this->svc()->evaluate($r);
        $this->assertSame('not_eligible', $r->fresh()->eligibility);
        $this->kycApprove($new);
        $this->svc()->evaluate($r);
        $this->assertSame('not_eligible', $r->fresh()->eligibility, 'KYC alone does not qualify when a deposit and investment are also required');
        \Illuminate\Support\Facades\DB::table('deposits')->insert(['user_id' => $new->id, 'amount' => 100, 'method' => 'usdt', 'payment_method' => 'usdt', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $this->svc()->evaluate($r);
        $this->assertSame('not_eligible', $r->fresh()->eligibility, 'a deposit alone does not qualify either');
        $this->assertFalse($r->fresh()->eligibility_detail['checks']['investment']['passed']);
    }

    public function test_an_unverified_or_incomplete_referred_account_is_not_activated(): void
    {
        $this->approveReferralPolicy(['require_kyc_approved' => true]);
        [$ref, $new, $r] = $this->pair(['email_verified_at' => null, 'registration_completed' => false]);
        $this->kycApprove($new);
        $this->svc()->evaluate($r);
        $r = $r->fresh();
        $this->assertSame('not_eligible', $r->eligibility);
        $this->assertNull($r->activated_at);
    }

    public function test_eligible_referral_records_activation_and_eligibility_events(): void
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $this->assertSame('eligible', $r->eligibility);
        $this->assertNotNull($r->activated_at);
        $this->assertDatabaseHas('referral_events', ['event' => 'activated', 'referred_id' => $new->id]);
        $this->assertDatabaseHas('referral_events', ['event' => 'eligibility_confirmed', 'referred_id' => $new->id]);
    }

    public function test_reward_count_limit_is_enforced_when_configured(): void
    {
        $this->approveReferralPolicy(['require_kyc_approved' => true, 'max_rewards_per_referrer' => 1]);
        $ref = $this->investor();
        $a = $this->investor(['referred_by' => $ref->id]); $b = $this->investor(['referred_by' => $ref->id]);
        $ra = $this->svc()->createForReferred($a); $rb = $this->svc()->createForReferred($b);
        $this->kycApprove($a); $this->kycApprove($b);
        $this->svc()->approve($this->svc()->evaluate($ra), $this->financial);
        $this->svc()->evaluate($rb);
        $this->assertSame('not_eligible', $rb->fresh()->eligibility);
    }

    // ── approval + credit ────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_approval_does_not_credit_and_credit_requires_approval(): void
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/credit")->assertStatus(422);       // a pending reward is never credited
        $this->assertSame(['pending', '0.00'], [$r->fresh()->status, $this->bal($ref)]);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/approve", ['note' => 'verified'])->assertOk()->assertJsonPath('reward.status', 'approved');
        $this->assertSame('0.00', $this->bal($ref), 'approved is not paid');
        $this->assertNull($r->fresh()->credit_reference);
    }

    public function test_credit_moves_the_balance_exactly_once_with_a_unique_reference_and_full_audit(): void
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $this->svc()->approve($r, $this->financial);
        $this->fin($this->financial2)->postJson("/api/financial/referrals/{$r->id}/credit")->assertOk()->assertJsonPath('reward.status', 'credited');
        $this->fin($this->financial2)->postJson("/api/financial/referrals/{$r->id}/credit")->assertStatus(422);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/credit")->assertStatus(422);
        $r = $r->fresh();
        $this->assertSame('5.00', $this->bal($ref));
        $this->assertStringStartsWith('RRC-', $r->credit_reference);
        $this->assertNotNull($r->balance_adjustment_id);
        $this->assertSame(1, \App\Models\BalanceAdjustment::where('user_id', $ref->id)->where('type', 'add')->count());
        $this->assertDatabaseHas('financial_audit_logs', ['action' => 'referral_reward.approved', 'entity_id' => $r->id, 'actor_id' => $this->financial->id]);
        $this->assertDatabaseHas('financial_audit_logs', ['action' => 'referral_reward.credited', 'entity_id' => $r->id]);
        $this->assertSame(['created', 'eligibility_checked', 'approved', 'credited'], $r->events->pluck('event')->unique()->values()->all());
    }

    public function test_a_failed_credit_leaves_no_false_credited_status(): void
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $this->svc()->approve($r, $this->financial);
        $this->mock(BalanceAdjustmentService::class, fn ($m) => $m->shouldReceive('applySigned')->andThrow(new \RuntimeException('wallet down')));
        try { app(ReferralRewardService::class)->credit($r->fresh(), $this->financial2); $this->fail('expected failure'); } catch (\RuntimeException $e) {}
        $r = $r->fresh();
        $this->assertSame('approved', $r->status);
        $this->assertNull($r->credit_reference);
        $this->assertSame('0.00', $this->bal($ref));
    }

    public function test_the_referrer_cannot_approve_or_credit_their_own_reward_and_investors_have_no_access(): void
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $this->fin($ref)->postJson("/api/financial/referrals/{$r->id}/approve")->assertStatus(403);
        $this->fin($new)->postJson("/api/financial/referrals/{$r->id}/credit")->assertStatus(403);
        $this->assertSame('pending', $r->fresh()->status);
        // even a staff account that is itself the referrer is refused by the service
        $staffRef = User::factory()->create(['role' => 'financial', 'status' => 'active', 'referral_code' => 'STAFFREF2']);
        $n2 = $this->investor(['referred_by' => $staffRef->id]); $this->kycApprove($n2);
        $r2 = $this->svc()->createForReferred($n2);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->svc()->approve($this->svc()->evaluate($r2), $staffRef);
    }

    // ── configurable amount ──────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_changing_the_amount_never_alters_existing_rewards_and_history_is_recorded(): void
    {
        $this->approveReferralPolicy();
        [$r1ref, $n1, $old] = $this->pair();
        $new = $this->svc()->savePolicy($this->admin, ['reward_amount' => '7.50', 'criteria' => ['require_kyc_approved' => true], 'policy_approved' => true, 'approval_reference' => 'M-2', 'reason' => 'Board uplift']);
        [$r2ref, $n2, $fresh] = $this->pair();
        $this->assertSame('5.00', (string) $old->fresh()->amount);
        $this->assertSame('7.50', (string) $fresh->fresh()->amount);
        $this->assertSame('5.00', (string) $new->previous_reward_amount);
        $this->assertSame([$this->admin->id, 'Board uplift'], [$new->changed_by, $new->reason]);
        $this->assertDatabaseHas('financial_audit_logs', ['action' => 'referral_policy.saved', 'entity_id' => $new->id]);
        $this->expectException(\LogicException::class);
        $new->update(['reward_amount' => 1]);
    }

    public function test_only_an_administrator_can_approve_the_policy_and_amounts_must_be_valid(): void
    {
        $base = ['reward_amount' => 5, 'criteria' => ['require_kyc_approved' => true], 'reason' => 'test reason'];
        $this->fin($this->financial)->postJson('/api/financial/referral-policy', $base + ['policy_approved' => true, 'approval_reference' => 'M'])->assertStatus(422);
        $this->fin($this->financial)->postJson('/api/financial/referral-policy', $base)->assertCreated();
        $this->assertFalse(ReferralPolicy::current()->policy_approved);
        foreach ([0, -5, 'abc'] as $bad) $this->fin($this->financial)->postJson('/api/financial/referral-policy', ['reward_amount' => $bad] + $base)->assertStatus(422);
        $this->fin($this->financial)->postJson('/api/financial/referral-policy', ['currency' => 'EUR'] + $base)->assertStatus(422);
    }

    // ── rejection ────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_rejection_needs_a_reason_keeps_history_and_blocks_credit(): void
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/reject", [])->assertStatus(422);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/reject", ['reason' => 'Account closed'])->assertOk()->assertJsonPath('reward.status', 'rejected');
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/credit")->assertStatus(422);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/approve")->assertStatus(422);
        $this->assertSame(['Account closed', $this->financial->id], [$r->fresh()->decision_reason, $r->fresh()->decided_by]);
        $this->assertSame('0.00', $this->bal($ref));
        $this->assertSame(1, ReferralReward::count());
    }

    // ── fraud ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_self_referral_is_flagged_and_can_never_be_approved(): void
    {
        $this->approveReferralPolicy();
        $u = $this->investor(); $this->kycApprove($u);
        $r = (new ReferralReward)->forceFill(['reference' => 'RR-SELF000001', 'referrer_id' => $u->id, 'referred_id' => $u->id, 'amount' => '5.00', 'status' => 'pending', 'registration_completed_at' => now()]);
        $r->save();
        $this->svc()->evaluate($r);
        $r = $r->fresh();
        $this->assertTrue($r->suspicious);
        $this->assertContains('self_referral', $r->flags);
        $this->assertSame('not_eligible', $r->eligibility);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/approve")->assertStatus(422);
    }

    public function test_duplicate_identity_and_phone_are_flagged_for_investigation_without_touching_money(): void
    {
        $this->approveReferralPolicy();
        $ref = $this->investor(['id_number' => 'ID-777']);
        $dup = $this->investor(['referred_by' => $ref->id, 'id_number' => 'ID-777']); $this->kycApprove($dup);
        $r = $this->svc()->createForReferred($dup);
        $this->svc()->evaluate($r);
        $r = $r->fresh();
        $this->assertTrue($r->suspicious);
        $this->assertContains('duplicate_identity', $r->flags);
        $this->assertSame(['0.00', '0.00'], [$this->bal($ref), $this->bal($dup)]);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/approve")->assertStatus(422)->assertJsonValidationErrors('flags');
    }

    public function test_a_shared_ip_alone_is_not_declared_fraud(): void
    {
        $this->approveReferralPolicy();
        [$ref, $new, $r] = $this->pair(); $this->kycApprove($new);
        ReferralEvent::create(['referrer_id' => $ref->id, 'referred_id' => $new->id, 'event' => 'registration_started', 'meta' => ['fp' => 'same-fp'], 'created_at' => now()]);
        ReferralEvent::create(['referrer_id' => $ref->id, 'referred_id' => null, 'event' => 'link_visited', 'meta' => ['fp' => 'same-fp'], 'created_at' => now()]);
        $this->svc()->evaluate($r);
        $r = $r->fresh();
        $this->assertFalse($r->suspicious);
        $this->assertSame('eligible', $r->eligibility);
    }

    public function test_velocity_flags_many_registrations_through_one_code(): void
    {
        config(['ssi_task5.referral.velocity_threshold' => 3]);
        $this->approveReferralPolicy();
        $ref = $this->investor(); $last = null;
        for ($i = 0; $i < 3; $i++) { $n = $this->investor(['referred_by' => $ref->id]); $last = $this->svc()->createForReferred($n); }
        $this->svc()->evaluate($last);
        $this->assertContains('velocity', $last->fresh()->flags);
        $this->assertTrue($last->fresh()->suspicious);
    }

    public function test_a_flag_must_be_cleared_with_a_reason_before_approval_and_is_audited(): void
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/flag", [])->assertStatus(422);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/flag", ['reason' => 'Disputed by the investor'])->assertOk()->assertJsonPath('reward.suspicious', true);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/approve")->assertStatus(422);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/unflag", ['reason' => 'Checked, genuine'])->assertOk()->assertJsonPath('reward.suspicious', false);
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/approve")->assertOk();
        $this->assertSame('0.00', $this->bal($ref));
        $this->assertDatabaseHas('financial_audit_logs', ['action' => 'referral_reward.flagged', 'entity_id' => $r->id]);
        $this->assertDatabaseHas('financial_audit_logs', ['action' => 'referral_reward.unflagged', 'entity_id' => $r->id]);
    }

    // ── reversal ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    private function credited(): array
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $this->svc()->approve($r, $this->financial); $this->svc()->credit($r->fresh(), $this->financial2);
        return [$ref, $new, $r->fresh()];
    }

    public function test_reversal_is_admin_only_audited_and_preserves_the_original_credit_record(): void
    {
        [$ref, $new, $r] = $this->credited();
        $this->fin($this->financial)->postJson("/api/financial/referrals/{$r->id}/reverse", ['reason' => 'fraud'])->assertStatus(403);
        $this->fin($this->admin)->postJson("/api/financial/referrals/{$r->id}/reverse", [])->assertStatus(422);
        $this->fin($this->admin)->postJson("/api/financial/referrals/{$r->id}/reverse", ['reason' => 'Confirmed duplicate account'])->assertOk()->assertJsonPath('reward.status', 'reversed');
        $r = $r->fresh();
        $this->assertSame('0.00', $this->bal($ref));
        $this->assertNotNull($r->credit_reference, 'the original credit reference is kept');
        $this->assertStringStartsWith('RRR-', $r->reversal_reference);
        $this->assertSame('Confirmed duplicate account', $r->reversal_reason);
        $this->fin($this->admin)->postJson("/api/financial/referrals/{$r->id}/reverse", ['reason' => 'again'])->assertStatus(422);
        $this->assertSame('0.00', $this->bal($ref));
        $this->assertDatabaseHas('financial_audit_logs', ['action' => 'referral_reward.reversed', 'entity_id' => $r->id]);
    }

    public function test_reversal_is_refused_not_overdrawn_when_the_funds_are_gone(): void
    {
        [$ref, $new, $r] = $this->credited();
        $ref->forceFill(['balance' => '1.00'])->save();
        $this->fin($this->admin)->postJson("/api/financial/referrals/{$r->id}/reverse", ['reason' => 'fraud'])->assertStatus(422);
        $this->assertSame(['credited', '1.00'], [$r->fresh()->status, $this->bal($ref)]);
    }

    public function test_rewards_cannot_be_deleted(): void
    {
        [$ref, $new, $r] = $this->pair();
        $this->expectException(\LogicException::class);
        $r->delete();
    }

    // ── investor view ────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_investor_dashboard_never_presents_pending_or_approved_as_paid_and_keeps_totals_separate(): void
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $json = $this->fin($ref)->getJson('/api/investor-investment/investor/referrals')->assertOk()->json();
        $this->assertSame(['pending', false, 0], [$json['referrals'][0]['status'], $json['referrals'][0]['paid'], $json['referrals'][0]['bonus']]);
        $this->assertSame(0.0, (float) $json['total_bonus']);
        $this->assertSame('5.00', $json['totals']['pending']);
        $this->assertSame('0.00', $json['totals']['credited']);
        $this->svc()->approve($r, $this->financial);
        $json = $this->fin($ref)->getJson('/api/investor-investment/investor/referrals')->json();
        $this->assertSame(['5.00', '0.00', 0.0], [$json['totals']['approved'], $json['totals']['credited'], (float) $json['total_bonus']]);
        $this->assertStringContainsString('Not yet paid', $json['referrals'][0]['explanation']);
        $this->svc()->credit($r->fresh(), $this->financial2);
        $json = $this->fin($ref)->getJson('/api/investor-investment/investor/referrals')->json();
        $this->assertSame([true, 5.0, '5.00'], [$json['referrals'][0]['paid'], (float) $json['total_bonus'], $json['totals']['credited']]);
    }

    public function test_investor_sees_only_their_own_referrals_with_masked_details(): void
    {
        [$ref, $new, $r] = $this->pair(['name' => 'Jane Doe', 'email' => 'jane.doe@example.com']);
        [$other] = $this->pair();
        $mine = $this->fin($ref)->getJson('/api/investor-investment/investor/referrals')->json('referrals');
        $this->assertCount(1, $mine);
        $this->assertSame(['Jane D.', 'j***@example.com'], [$mine[0]['name'], $mine[0]['email']]);
        $this->assertStringNotContainsString('jane.doe@example.com', json_encode($mine));
        $this->assertArrayNotHasKey('referred_id', $mine[0]);
        $this->assertSame(1, $this->fin($other)->getJson('/api/investor-investment/investor/referrals')->json('total_referrals'));
    }

    public function test_investor_programme_info_explains_conditions_and_unapproved_state(): void
    {
        $ref = $this->investor();
        $j = $this->fin($ref)->getJson('/api/investor-investment/investor/referrals')->json('programme');
        $this->assertFalse($j['active']);
        $this->assertStringContainsString('not been finalised', $j['conditions'][0]);
        $this->approveReferralPolicy(['require_kyc_approved' => true]);
        $j = $this->fin($ref)->getJson('/api/investor-investment/investor/referrals')->json('programme');
        $this->assertTrue($j['active']);
        $this->assertTrue(collect($j['conditions'])->contains(fn ($c) => str_contains($c, 'KYC')));
    }

    public function test_incomplete_registration_is_shown_as_started_not_as_a_reward(): void
    {
        $ref = $this->investor();
        $this->investor(['referred_by' => $ref->id, 'registration_completed' => false]);
        $row = $this->fin($ref)->getJson('/api/investor-investment/investor/referrals')->json('referrals')[0];
        $this->assertSame(['started', 'none', false], [$row['registration_status'], $row['status'], $row['paid']]);
    }

    // ── financial dashboard ──────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_summary_reports_liabilities_and_funnel_correctly(): void
    {
        $this->approveReferralPolicy();
        [$a, $na, $ra] = $this->pair(); [$b, $nb, $rb] = $this->pair(); [$c, $nc, $rc] = $this->pair();
        foreach ([$na, $nb, $nc] as $n) $this->kycApprove($n);
        foreach ([$ra, $rb, $rc] as $r) $this->svc()->evaluate($r);
        $this->svc()->approve($ra->fresh(), $this->financial);
        $this->svc()->approve($rb->fresh(), $this->financial); $this->svc()->credit($rb->fresh(), $this->financial2);
        $this->svc()->reject($rc->fresh(), $this->financial, 'dup');
        app(ReferralService::class)->log('link_visited', $a->id, null, $a->referral_code);
        $s = $this->fin($this->financial)->getJson('/api/financial/referrals/summary')->assertOk()->json('summary');
        $this->assertSame([3, 3, 1, 1, 1, 1], [$s['total_referrals'], $s['eligible'] + $s['rejected'] * 0, $s['approved'], $s['credited'], $s['rejected'], $s['link_visits']]);
        $this->assertSame(['0.00', '5.00', '5.00', '5.00'], [$s['pending_liability'], $s['approved_liability'], $s['total_liability'], $s['credited_value']]);
    }

    public function test_list_filters_search_and_pagination(): void
    {
        [$a, $na, $ra] = $this->pair(['name' => 'Zed Finder']); [$b, $nb, $rb] = $this->pair();
        $this->svc()->reject($rb, $this->financial, 'dup');
        $this->fin($this->financial)->getJson('/api/financial/referrals?status=rejected')->assertOk()->assertJsonCount(1, 'rewards');
        $this->fin($this->financial)->getJson('/api/financial/referrals?q=Zed')->assertJsonCount(1, 'rewards')->assertJsonPath('rewards.0.reference', $ra->reference);
        $this->fin($this->financial)->getJson('/api/financial/referrals?per_page=1')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->fin($this->financial)->getJson("/api/financial/referrals/{$ra->id}")->assertOk()->assertJsonStructure(['reward', 'history', 'funnel']);
    }

    public function test_export_is_permissioned_and_neutralises_spreadsheet_formulas(): void
    {
        [$a, $na, $ra] = $this->pair(['name' => '=HYPERLINK("http://evil")']);
        $this->fin($a)->get('/api/financial/referrals-export')->assertStatus(403);
        $res = $this->fin($this->financial)->get('/api/financial/referrals-export');
        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function test_backfill_command_creates_pending_rows_only_and_credits_nothing(): void
    {
        $ref = $this->investor(); $this->investor(['referred_by' => $ref->id]); $this->investor(['referred_by' => $ref->id]);
        $this->artisan('referrals:scan')->assertExitCode(0);
        $this->artisan('referrals:scan')->assertExitCode(0);
        $this->assertSame([2, 2, '0.00'], [ReferralReward::count(), ReferralReward::where('status', 'pending')->count(), $this->bal($ref)]);
    }

    // ?? gap-closure: referral credit/reversal must not bypass the ORIGINAL dual-approval threshold (config/financial_controls.php) ??

    public function test_a_credit_at_or_above_the_dual_approval_threshold_is_refused_and_moves_nothing(): void
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $this->svc()->approve($r, $this->financial);
        config(['financial_controls.dual_approval_enabled' => true, 'financial_controls.balance_threshold_usd' => '5.00']);   // reward is exactly 5.00

        $this->fin($this->financial2)->postJson("/api/financial/referrals/{$r->id}/credit")->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->assertSame('0.00', $this->bal($ref));
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(0, \App\Models\BalanceAdjustment::where('user_id', $ref->id)->count());
    }

    public function test_a_credit_below_the_threshold_or_with_the_threshold_disabled_still_works(): void
    {
        [$ref, $new, $r] = $this->eligiblePair();
        $this->svc()->approve($r, $this->financial);
        config(['financial_controls.balance_threshold_usd' => '10000.00']);
        $this->fin($this->financial2)->postJson("/api/financial/referrals/{$r->id}/credit")->assertOk();
        $this->assertSame('5.00', $this->bal($ref));
    }

    public function test_a_reversal_at_or_above_the_threshold_is_refused(): void
    {
        [$ref, $new, $r] = $this->credited();
        config(['financial_controls.dual_approval_enabled' => true, 'financial_controls.balance_threshold_usd' => '5.00']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->fin($admin)->postJson("/api/financial/referrals/{$r->id}/reverse", ['reason' => 'test reversal'])->assertStatus(422);
        $this->assertSame('credited', $r->fresh()->status);
        $this->assertSame('5.00', $this->bal($ref));
    }
}
