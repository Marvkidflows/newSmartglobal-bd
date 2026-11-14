<?php
// Task 5: referral rewards ledger - eligibility, fraud flags, approval, crediting, rejection and reversal.
//
// SAFETY MODEL
//  * A reward is created Pending when a referred investor COMPLETES registration. Registration alone never makes it eligible.
//  * Eligibility is decided by the CURRENT referral policy (configurable criteria). While the policy is not Management-approved the result is
//    'policy_not_approved' and a reward can neither be approved nor credited.
//  * Approve and Credit are separate, authorised, audited steps. Every transition happens inside one DB transaction with the reward row locked
//    (FOR UPDATE), so two simultaneous attempts cannot both succeed; credit_reference is DB-unique and the wallet credit uses an idempotency key.
//  * The wallet is credited ONLY through the existing BalanceAdjustmentService (row lock + idempotency + audit). If anything fails the whole
//    transaction rolls back - a reward can never read 'credited' without the balance having moved, nor the reverse.
//  * Suspicious rewards are flagged for Financial investigation; flagging never touches a balance. An approval is refused while a flag stands.
//  * Reversal appends a reversal reference + reason, deducts through the same mechanism (never below zero) and keeps the original history.
namespace App\Services\Task5;

use App\Models\ReferralEvent;
use App\Models\ReferralPolicy;
use App\Models\ReferralReward;
use App\Models\ReferralRewardEvent;
use App\Models\User;
use App\Services\Financial\BalanceAdjustmentService;
use App\Services\FinancialAuditService;
use App\Services\Ledger\WalletLedger;
use App\Support\Decimal;
use App\Support\StaffAccountGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReferralRewardService
{
    public const STAFF_ROLES = ['admin', 'financial'];
    public const HARD_FLAGS = ['self_referral', 'same_phone', 'duplicate_identity', 'shared_ip_and_address', 'velocity'];

    public function __construct(protected FinancialAuditService $audit, protected ReferralService $referrals, protected WalletLedger $ledger, protected BalanceAdjustmentService $wallet) {}

    // ── creation (called from registration; must never break registration) ─────────────────────────────────────────────────────────
    public function createForReferred(User $referred, ?string $ipFingerprint = null): ?ReferralReward
    {
        if (!$referred->referred_by) return null;
        if ($existing = ReferralReward::where('referred_id', $referred->id)->first()) return $existing;
        $referrer = User::find($referred->referred_by);
        if (!$referrer) return null;
        $policy = ReferralPolicy::current();
        $started = ReferralEvent::where('referred_id', $referred->id)->where('event', 'registration_started')->oldest('id')->value('created_at');

        try {
            return DB::transaction(function () use ($referred, $referrer, $policy, $started) {
                $r = (new ReferralReward)->forceFill([
                    'reference' => 'RR-' . strtoupper(Str::random(10)), 'referrer_id' => $referrer->id, 'referred_id' => $referred->id, 'referral_code' => $referrer->referral_code,
                    'policy_id' => $policy?->id, 'amount' => $policy ? Decimal::money($policy->reward_amount) : config('ssi_task5.referral.default_reward'),
                    'currency' => $policy->currency ?? 'USD', 'status' => 'pending', 'eligibility' => 'not_checked',
                    'registration_started_at' => $started, 'registration_completed_at' => now(),
                ]);
                $r->save();
                $this->log($r, 'created', null, 'pending', null, ['policy_id' => $policy?->id, 'amount' => (string) $r->amount], null);
                return $r;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return ReferralReward::where('referred_id', $referred->id)->first();       // concurrent creation: the unique(referred_id) index decided
        }
    }

    protected function log(ReferralReward $r, string $event, ?string $from, ?string $to, ?string $reason, array $detail, ?User $actor): void
    {
        ReferralRewardEvent::create(['reward_id' => $r->id, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'detail' => $detail ?: null,
            'actor_id' => $actor?->id, 'actor_name' => $actor ? ($actor->name ?? $actor->full_name ?? 'Staff') : 'system', 'created_at' => now()]);
    }

    protected function auditIt(User $actor, string $action, ReferralReward $r, ?array $prev, array $new, ?string $reason): void
    {
        $this->audit->record($actor, "referral_reward.{$action}", 'referral_reward', $r->id, $r->referrer_id, $prev, $new + ['reference' => $r->reference, 'referred_id' => $r->referred_id, 'amount' => (string) $r->amount], $reason);
    }

    protected function assertStaff(User $actor): void
    {
        if (!StaffAccountGuard::isActiveStaffWithRole($actor, self::STAFF_ROLES)) throw ValidationException::withMessages(['actor' => ['You are not authorised to perform this action.']]);
    }

    // ── eligibility ────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    /** @return array{eligibility:string, checks:array, flags:array, activated:bool} - pure evaluation, writes nothing. */
    public function assess(ReferralReward $r): array
    {
        $policy = ReferralPolicy::current();
        $c = $policy?->criteria ?? [];
        $referred = User::find($r->referred_id); $referrer = User::find($r->referrer_id);
        $checks = [];
        $pass = function (string $k, bool $ok, string $why) use (&$checks): void { $checks[$k] = ['passed' => $ok, 'detail' => $why]; };   // by-reference: an arrow fn would capture a COPY

        $activated = $referred && $referred->role === 'investor' && (bool) $referred->registration_completed && $referred->email_verified_at !== null
            && in_array(strtolower((string) ($referred->status ?: 'active')), ['active'], true);
        $pass('activated', $activated, $activated ? 'Registration completed, email verified, account active.' : 'The referred investor has not completed registration / verification or the account is not active.');
        $pass('referrer_valid', (bool) $referrer && $referrer->role === 'investor' && $r->referrer_id !== $r->referred_id, ($referrer && $r->referrer_id !== $r->referred_id) ? 'Referrer is a different investor account.' : 'Referrer is missing or is the same account (self-referral).');

        if (!empty($c['referrer_must_be_active'])) { $ok = $referrer && in_array(strtolower((string) ($referrer->status ?: 'active')), ['active'], true); $pass('referrer_active', (bool) $ok, $ok ? 'Referrer account is active.' : 'Referrer account is not active.'); }
        if (!empty($c['require_kyc_approved'])) { $ok = $referred && $referred->kyc_status === 'approved' && (bool) $referred->kyc_verified; $pass('kyc_approved', (bool) $ok, $ok ? 'KYC approved.' : 'KYC is not approved.'); }
        if (!empty($c['require_approved_deposit'])) { $ok = $referred && DB::table('deposits')->where('user_id', $referred->id)->where('status', 'approved')->exists(); $pass('approved_deposit', (bool) $ok, $ok ? 'Has an approved deposit.' : 'No approved deposit.'); }
        if (!empty($c['require_investment'])) { $ok = $referred && DB::table('investment_accounts')->where('user_id', $referred->id)->whereIn('status', ['active', 'completed'])->where('amount', '>', 0)->exists(); $pass('investment', (bool) $ok, $ok ? 'Has a non-cancelled investment.' : 'No qualifying investment.'); }
        if (!empty($c['require_completed_cycle'])) { $ok = $referred && DB::table('investment_accounts')->where('user_id', $referred->id)->where('status', 'completed')->exists(); $pass('completed_cycle', (bool) $ok, $ok ? 'Has a completed investment cycle.' : 'No completed investment cycle.'); }
        if (!empty($c['max_rewards_per_referrer'])) {
            $n = ReferralReward::where('referrer_id', $r->referrer_id)->whereIn('status', ['approved', 'credited'])->where('id', '!=', $r->id)->count();
            $pass('reward_count_limit', $n < (int) $c['max_rewards_per_referrer'], "Referrer has {$n} approved/credited rewards (limit {$c['max_rewards_per_referrer']}).");
        }
        if (!empty($c['max_total_value_per_referrer'])) {
            $sum = Decimal::sum(ReferralReward::where('referrer_id', $r->referrer_id)->whereIn('status', ['approved', 'credited'])->where('id', '!=', $r->id)->pluck('amount')->map(fn ($v) => (string) $v));
            $pass('reward_value_limit', Decimal::lte(Decimal::add($sum, (string) $r->amount), Decimal::fromInput($c['max_total_value_per_referrer'])), "Approved/credited total {$sum} + this reward vs limit {$c['max_total_value_per_referrer']}.");
        }

        $flags = $this->detectFlags($r, $referred, $referrer);
        $allPass = collect($checks)->every(fn ($x) => $x['passed']);
        $eligibility = !$allPass ? 'not_eligible' : (!($policy?->policy_approved) ? 'policy_not_approved' : 'eligible');
        return ['eligibility' => $eligibility, 'checks' => $checks, 'flags' => $flags, 'activated' => (bool) $activated, 'policy_id' => $policy?->id, 'policy_approved' => (bool) $policy?->policy_approved];
    }

    /** Fraud signals. IP similarity is only ever counted together with another identifying signal. */
    protected function detectFlags(ReferralReward $r, ?User $referred, ?User $referrer): array
    {
        $f = [];
        if ($r->referrer_id === $r->referred_id) $f[] = 'self_referral';
        if ($referred && $referrer) {
            if ($referred->phone && User::where('phone', $referred->phone)->where('id', '!=', $referred->id)->exists()) $f[] = 'same_phone';
            if ($referred->id_number && User::where('id_number', $referred->id_number)->where('id', '!=', $referred->id)->exists()) $f[] = 'duplicate_identity';
            $ipA = ReferralEvent::where('referred_id', $referred->id)->where('event', 'registration_started')->value('meta');
            $ipMatch = false;
            if (!empty($ipA['fp'])) {
                $ipMatch = ReferralEvent::where('referrer_id', $referrer->id)->whereNotNull('meta')->get()->contains(fn ($e) => ($e->meta['fp'] ?? null) === $ipA['fp'] && $e->referred_id !== $referred->id)
                    || ReferralEvent::where('referred_id', $referrer->id)->where('event', 'registration_started')->get()->contains(fn ($e) => ($e->meta['fp'] ?? null) === $ipA['fp']);
            }
            $sameAddr = $referred->postal_code && $referrer->postal_code && strtolower(trim((string) $referred->residential_address)) !== '' && strtolower(trim((string) $referred->residential_address)) === strtolower(trim((string) $referrer->residential_address)) && $referred->postal_code === $referrer->postal_code;
            if ($ipMatch && $sameAddr) $f[] = 'shared_ip_and_address';
            $win = (int) config('ssi_task5.referral.velocity_window_hours', 24); $thr = (int) config('ssi_task5.referral.velocity_threshold', 5);
            $recent = ReferralReward::where('referrer_id', $r->referrer_id)->where('registration_completed_at', '>=', now()->subHours($win))->count();
            if ($recent >= $thr) $f[] = 'velocity';
            $mins = (int) config('ssi_task5.referral.fast_activation_minutes', 10);
            if ($referrer->created_at && $referred->created_at && $referred->created_at->diffInMinutes($referrer->created_at, true) <= $mins && count($f) > 0) $f[] = 'rapid_creation';
        }
        return array_values(array_unique($f));
    }

    /** Re-evaluate one reward and persist the result (+ funnel events + suspicion flag). Never changes status or money. */
    /**
     * @param bool $scheduled  true only from the scheduler/`referrals:scan`: (a) a row that is no longer Pending (decided since it was selected) is left
     *                         untouched, and (b) the routine 'eligibility_checked' audit event is written only when eligibility or flags actually changed,
     *                         so an hourly scan cannot flood the history. Default false = behaviour exactly as before (manual re-checks always log).
     */
    public function evaluate(ReferralReward $r, ?User $actor = null, bool $scheduled = false): ReferralReward
    {
        return DB::transaction(function () use ($r, $actor, $scheduled) {
            $locked = ReferralReward::whereKey($r->id)->lockForUpdate()->first();
            if ($scheduled && $locked->status !== 'pending') return $locked;
            $a = $this->assess($locked);
            $hard = array_values(array_intersect($a['flags'], self::HARD_FLAGS));
            $wasActivated = $locked->activated_at !== null; $prevElig = $locked->eligibility; $prevFlags = $locked->flags; $wasSuspicious = (bool) $locked->suspicious;
            $new = ['eligibility' => $a['eligibility'], 'eligibility_detail' => ['checks' => $a['checks'], 'policy_id' => $a['policy_id'], 'policy_approved' => $a['policy_approved']],
                'eligibility_checked_at' => now(), 'flags' => $a['flags'] ?: null];
            if ($a['activated'] && !$wasActivated) { $new['activated_at'] = now(); }
            if ($hard && !$locked->suspicious) $new['suspicious'] = true;               // flag only; clearing is an explicit audited action
            $locked->forceFill($new)->save();
            if ($a['activated'] && !$wasActivated) $this->referrals->log('activated', $locked->referrer_id, $locked->referred_id, $locked->referral_code);
            if ($a['eligibility'] === 'eligible' && $prevElig !== 'eligible') $this->referrals->log('eligibility_confirmed', $locked->referrer_id, $locked->referred_id, $locked->referral_code);
            if ($hard && !$wasSuspicious) $this->log($locked, 'flagged', $locked->status, $locked->status, 'Automatic fraud signals: ' . implode(', ', $hard), ['flags' => $a['flags']], null);
            $changed = $prevElig !== $a['eligibility'] || ($a['flags'] ?: null) != ($prevFlags ?: null);
            if (!$scheduled || $changed) $this->log($locked, 'eligibility_checked', $locked->status, $locked->status, null, ['eligibility' => $a['eligibility'], 'flags' => $a['flags']], $actor);
            return $locked->fresh();
        });
    }

    // ── approval workflow ──────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function approve(ReferralReward $r, User $actor, ?string $note = null): ReferralReward
    {
        $this->assertStaff($actor);
        $out = DB::transaction(function () use ($r, $actor, $note) {
            $locked = ReferralReward::whereKey($r->id)->lockForUpdate()->first();
            if ($locked->status !== 'pending') throw ValidationException::withMessages(['status' => ["Only a pending reward can be approved (current status: {$locked->status})."]]);
            if (in_array($actor->id, [$locked->referrer_id, $locked->referred_id], true)) throw ValidationException::withMessages(['actor' => ['You cannot approve a reward in which you are the referrer or the referred investor.']]);
            $a = $this->assess($locked);
            if ($a['eligibility'] === 'policy_not_approved') throw ValidationException::withMessages(['eligibility' => ['The referral policy has not been approved by Management, so no reward can be approved yet.']]);
            if ($a['eligibility'] !== 'eligible') throw ValidationException::withMessages(['eligibility' => ['This referral does not currently meet the eligibility criteria.']]);
            if ($locked->suspicious || array_intersect($a['flags'], self::HARD_FLAGS)) throw ValidationException::withMessages(['flags' => ['This referral is flagged as suspicious. Investigate and clear the flag (with a reason) before approving.']]);
            $locked->forceFill(['status' => 'approved', 'eligibility' => 'eligible', 'eligibility_detail' => ['checks' => $a['checks'], 'policy_id' => $a['policy_id']], 'eligibility_checked_at' => now(),
                'decision_reason' => $note, 'decided_by' => $actor->id, 'decided_by_name' => $actor->name ?? $actor->full_name, 'decided_at' => now()])->save();
            $this->log($locked, 'approved', 'pending', 'approved', $note, ['checks' => $a['checks']], $actor);
            $this->auditIt($actor, 'approved', $locked, ['status' => 'pending'], ['status' => 'approved'], $note);
            return $locked;
        });
        return $out->fresh();
    }

    public function reject(ReferralReward $r, User $actor, string $reason): ReferralReward
    {
        $this->assertStaff($actor);
        if (trim($reason) === '') throw ValidationException::withMessages(['reason' => ['A reason is required to reject a reward.']]);
        return DB::transaction(function () use ($r, $actor, $reason) {
            $locked = ReferralReward::whereKey($r->id)->lockForUpdate()->first();
            if (!in_array($locked->status, ['pending', 'approved'], true)) throw ValidationException::withMessages(['status' => ["Only a pending or approved (not yet credited) reward can be rejected (current status: {$locked->status})."]]);
            $prev = $locked->status;
            $locked->forceFill(['status' => 'rejected', 'decision_reason' => $reason, 'decided_by' => $actor->id, 'decided_by_name' => $actor->name ?? $actor->full_name, 'decided_at' => now()])->save();
            $this->log($locked, 'rejected', $prev, 'rejected', $reason, [], $actor);
            $this->auditIt($actor, 'rejected', $locked, ['status' => $prev], ['status' => 'rejected'], $reason);
            return $locked->fresh();
        });
    }

    /**
     * The original Financial dual-approval control (config/financial_controls.php: a single wallet add/deduct at or above balance_threshold_usd needs a
     * SECOND approver through FinancialApprovalService) is enforced for manual balance adjustments. A referral credit/reversal moves the wallet through
     * BalanceAdjustmentService::applySigned, which has no second-approver step, so it must never be used to move an amount the original rule would hold
     * for a second person. Fail-closed, uses the EXISTING setting, grants no new authority: such an amount is refused here and must go through the
     * balance-adjustment approval workflow. (With the default threshold and a reward of a few dollars this never triggers.)
     */
    private function assertBelowDualApprovalThreshold(string $amount): void
    {
        $t = config('financial_controls.balance_threshold_usd');
        if (!config('financial_controls.dual_approval_enabled') || $t === null || $t === '') return;
        if (Decimal::gte(Decimal::money($amount), Decimal::money($t))) {
            throw ValidationException::withMessages(['amount' => ["This amount is at or above the Financial dual-approval threshold ({$t} USD). It cannot be moved by a referral action; it needs a second approver through the balance-adjustment approval workflow."]]);
        }
    }

    public function credit(ReferralReward $r, User $actor): ReferralReward
    {
        $this->assertStaff($actor);
        return DB::transaction(function () use ($r, $actor) {                           // outer transaction: wallet credit + status + events commit or roll back TOGETHER
            $locked = ReferralReward::whereKey($r->id)->lockForUpdate()->first();
            if ($locked->status === 'credited') throw ValidationException::withMessages(['status' => ['This reward has already been credited.']]);
            if ($locked->status !== 'approved') throw ValidationException::withMessages(['status' => ["Only an approved reward can be credited (current status: {$locked->status}). A pending reward is never credited."]]);
            if ($actor->id === $locked->referrer_id) throw ValidationException::withMessages(['actor' => ['You cannot credit your own reward.']]);
            if ($locked->credit_reference) throw ValidationException::withMessages(['status' => ['A credit reference already exists for this reward.']]);
            if (($locked->currency ?? 'USD') !== 'USD') throw ValidationException::withMessages(['currency' => ['Rewards are credited to the USD wallet only; no exchange rate is ever assumed. Resolve the currency policy first.']]);
            $referrer = User::find($locked->referrer_id);
            if (!$referrer || $referrer->role !== 'investor') throw ValidationException::withMessages(['referrer' => ['The referring investor account is not available.']]);
            $this->assertBelowDualApprovalThreshold(Decimal::money($locked->amount));

            $res = $this->wallet->applySigned($referrer->id, Decimal::money($locked->amount), "Referral reward {$locked->reference}", $actor, "referral-reward-{$locked->id}-credit");
            $ref = 'RRC-' . strtoupper(Str::random(10));
            $locked->forceFill(['status' => 'credited', 'credited_at' => now(), 'credited_by' => $actor->id, 'credit_reference' => $ref, 'balance_adjustment_id' => $res['adjustment']->id])->save();
            $this->ledger->record(['entry_key' => "referral:{$locked->id}:credit", 'user_id' => $referrer->id, 'classification' => 'referral_reward_credit', 'direction' => 'credit', 'amount' => Decimal::money($locked->amount),
                'reference' => $ref, 'balance_after' => $res['adjustment']->balance_after, 'actor_id' => $actor->id, 'actor_name' => $actor->name ?? 'Staff', 'note' => "Referral reward {$locked->reference}"]);
            $this->log($locked, 'credited', 'approved', 'credited', null, ['credit_reference' => $ref, 'balance_adjustment_id' => $res['adjustment']->id], $actor);
            $this->auditIt($actor, 'credited', $locked, ['status' => 'approved'], ['status' => 'credited', 'credit_reference' => $ref], null);
            return $locked->fresh();
        });
    }

    public function reverse(ReferralReward $r, User $actor, string $reason): ReferralReward
    {
        $this->assertStaff($actor);
        if (trim($reason) === '') throw ValidationException::withMessages(['reason' => ['A reason is required to reverse a credited reward.']]);
        return DB::transaction(function () use ($r, $actor, $reason) {
            $locked = ReferralReward::whereKey($r->id)->lockForUpdate()->first();
            if ($locked->status !== 'credited') throw ValidationException::withMessages(['status' => ["Only a credited reward can be reversed (current status: {$locked->status})."]]);
            $this->assertBelowDualApprovalThreshold(Decimal::money($locked->amount));
            // Deducts through the same authorised mechanism; refuses (no state change) if the investor no longer holds the funds - then Financial must investigate.
            $res = $this->wallet->applySigned($locked->referrer_id, Decimal::negate(Decimal::money($locked->amount)), "Reversal of referral reward {$locked->reference}: {$reason}", $actor, "referral-reward-{$locked->id}-reversal");
            $ref = 'RRR-' . strtoupper(Str::random(10));
            $locked->forceFill(['status' => 'reversed', 'reversal_reference' => $ref, 'reversal_reason' => $reason, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_adjustment_id' => $res['adjustment']->id])->save();
            $this->log($locked, 'reversed', 'credited', 'reversed', $reason, ['reversal_reference' => $ref, 'credit_reference' => $locked->credit_reference], $actor);
            $this->auditIt($actor, 'reversed', $locked, ['status' => 'credited'], ['status' => 'reversed', 'reversal_reference' => $ref], $reason);
            return $locked->fresh();
        });
    }

    public function setFlag(ReferralReward $r, User $actor, bool $suspicious, string $reason): ReferralReward
    {
        $this->assertStaff($actor);
        if (trim($reason) === '') throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        return DB::transaction(function () use ($r, $actor, $suspicious, $reason) {
            $locked = ReferralReward::whereKey($r->id)->lockForUpdate()->first();
            $prev = (bool) $locked->suspicious;
            $locked->forceFill(['suspicious' => $suspicious])->save();                   // evidence (flags, events, checks) is preserved; nothing about money changes
            $this->log($locked, $suspicious ? 'flagged' : 'unflagged', $locked->status, $locked->status, $reason, ['flags' => $locked->flags], $actor);
            $this->auditIt($actor, $suspicious ? 'flagged' : 'unflagged', $locked, ['suspicious' => $prev], ['suspicious' => $suspicious], $reason);
            return $locked->fresh();
        });
    }

    // ── policy (amount + criteria) ─────────────────────────────────────────────────────────────────────────────────────────────────
    public const CRITERIA_KEYS = ['require_kyc_approved', 'require_approved_deposit', 'require_investment', 'require_completed_cycle', 'referrer_must_be_active'];

    /** Appends a policy version. Existing rewards keep the amount they were created/approved with. Only an Administrator can mark a policy Management-approved. */
    public function savePolicy(User $actor, array $d): ReferralPolicy
    {
        $this->assertStaff($actor);
        $amount = Decimal::money($d['reward_amount']);
        if (Decimal::lte($amount, '0')) throw ValidationException::withMessages(['reward_amount' => ['The reward amount must be greater than zero.']]);
        if (($d['currency'] ?? 'USD') !== 'USD') throw ValidationException::withMessages(['currency' => ['Only USD is supported; no exchange rate is ever assumed.']]);
        $crit = [];
        foreach (self::CRITERIA_KEYS as $k) $crit[$k] = (bool) ($d['criteria'][$k] ?? false);
        foreach (['max_rewards_per_referrer', 'max_total_value_per_referrer'] as $k) $crit[$k] = isset($d['criteria'][$k]) && $d['criteria'][$k] !== '' ? $d['criteria'][$k] : null;
        $approve = (bool) ($d['policy_approved'] ?? false);
        if ($approve) {
            if ($actor->role !== 'admin') throw ValidationException::withMessages(['policy_approved' => ['Only an Administrator may mark the referral policy as Management-approved.']]);
            if (empty($d['approval_reference'])) throw ValidationException::withMessages(['approval_reference' => ['The Management approval reference is required to approve the policy.']]);
            if (!collect(self::CRITERIA_KEYS)->contains(fn ($k) => $crit[$k])) throw ValidationException::withMessages(['criteria' => ['At least one activation criterion must be enabled: registration alone can never qualify a referral.']]);
        }
        $effective = !empty($d['effective_from']) ? \Illuminate\Support\Carbon::parse($d['effective_from']) : now();
        if ($effective->lt(now()->subMinute())) throw ValidationException::withMessages(['effective_from' => ['The effective date cannot be in the past.']]);

        return DB::transaction(function () use ($actor, $amount, $crit, $approve, $effective, $d) {
            $prev = ReferralPolicy::current();
            $p = ReferralPolicy::create(['reward_amount' => $amount, 'previous_reward_amount' => $prev?->reward_amount, 'currency' => 'USD', 'criteria' => $crit, 'policy_approved' => $approve,
                'approval_reference' => $d['approval_reference'] ?? null, 'approved_by' => $approve ? $actor->id : null, 'approved_at' => $approve ? now() : null, 'effective_from' => $effective,
                'reason' => $d['reason'], 'changed_by' => $actor->id, 'changed_by_name' => $actor->name ?? $actor->full_name ?? 'Staff', 'created_at' => now()]);
            $this->audit->record($actor, 'referral_policy.saved', 'referral_policy', $p->id, null,
                $prev ? ['reward_amount' => (string) $prev->reward_amount, 'criteria' => $prev->criteria, 'approved' => $prev->policy_approved] : null,
                ['reward_amount' => (string) $p->reward_amount, 'criteria' => $crit, 'approved' => $approve, 'effective_from' => $effective->toIso8601String()], $d['reason']);
            return $p;
        });
    }

    // ── reporting ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function summary(): array
    {
        $cnt = fn (string $ev) => ReferralEvent::where('event', $ev)->count();
        $by = ReferralReward::selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $sum = fn (array $st) => Decimal::fromCents((int) ReferralReward::whereIn('status', $st)->pluck('amount')->sum(fn ($a) => Decimal::toCents(Decimal::money($a))));
        return [
            'link_visits' => $cnt('link_visited'), 'registrations_started' => $cnt('registration_started'), 'registrations_completed' => ReferralReward::count(),
            'total_referrals' => ReferralReward::count(), 'activated' => ReferralReward::whereNotNull('activated_at')->count(), 'eligible' => ReferralReward::where('eligibility', 'eligible')->count(),
            'pending' => (int) ($by['pending'] ?? 0), 'approved' => (int) ($by['approved'] ?? 0), 'credited' => (int) ($by['credited'] ?? 0), 'rejected' => (int) ($by['rejected'] ?? 0), 'reversed' => (int) ($by['reversed'] ?? 0),
            'suspicious' => ReferralReward::where('suspicious', true)->count(),
            'pending_liability' => $sum(['pending']), 'approved_liability' => $sum(['approved']), 'total_liability' => $sum(['pending', 'approved']), 'credited_value' => $sum(['credited']), 'reversed_value' => $sum(['reversed']),
            'currency' => 'USD', 'policy' => ($p = ReferralPolicy::current()) ? ['reward_amount' => (string) $p->reward_amount, 'currency' => $p->currency, 'criteria' => $p->criteria, 'policy_approved' => $p->policy_approved, 'approval_reference' => $p->approval_reference] : null,
        ];
    }
}
