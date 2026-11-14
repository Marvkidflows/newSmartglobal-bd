<?php
// Task 5: withdrawal-fee policy, quoting, per-withdrawal freezing, fee accounting events and reconciliation.
//
// MONEY MOVEMENT (unchanged from today): nothing leaves the wallet until Admin/Financial APPROVES a withdrawal; approval debits `amount`
// (= the GROSS requested). With a fee: gross is debited, the investor is paid NET (= gross - fee) and the fee is retained by SSI.
// Therefore: a pending / on-hold / rejected withdrawal has had NO money (and no fee) taken; the fee is "charged" (disclosed + frozen) at
// submission, "collected" only when the withdrawal is approved, and "not_collected" if it is rejected. This is the DEFAULT mechanical
// treatment pending Management decision #10/#11; anything else (reversal of a collected fee, refunds) is an explicit, audited Financial action.
namespace App\Services\Task5;

use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalFeeEvent;
use App\Models\WithdrawalFeePolicy;
use App\Services\Financial\BalanceAdjustmentService;
use App\Services\FinancialAuditService;
use App\Services\Ledger\WalletLedger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WithdrawalFeeService
{
    public function __construct(protected WithdrawalFeeCalculator $calc, protected FinancialAuditService $audit, protected WalletLedger $ledger) {}

    public function currentPolicy(): ?WithdrawalFeePolicy { return WithdrawalFeePolicy::current(); }

    /** Disclosure shown to the investor BEFORE they confirm. */
    public function quote(mixed $amount): array { return $this->calc->calculate($amount, $this->currentPolicy()); }

    public function newTransactionReference(): string { return 'WD-' . strtoupper(Str::random(10)); }

    /** Withdrawal attributes that freeze the fee configuration applied at submission. `amount` stays the gross (what approval debits). */
    public function freezeAttributes(array $q): array
    {
        return [
            'amount' => $q['gross'], 'gross_amount' => $q['gross'], 'fee_amount' => $q['fee'], 'net_amount' => $q['net'], 'fee_currency' => $q['currency'],
            'fee_policy_id' => $q['applied'] ? $q['policy_id'] : null,
            'fee_calculation' => ['fee_type' => $q['fee_type'], 'fixed_amount' => $q['fixed_amount'], 'percentage' => $q['percentage'], 'steps' => $q['steps'], 'explanation' => $q['explanation']],
            'fee_status' => $q['applied'] ? 'charged' : 'none', 'fee_confirmed_at' => now(),
        ];
    }

    // ── fee events ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    protected function event(Withdrawal $w, string $event, string $amount, ?User $actor, ?string $reason, ?int $related = null): WithdrawalFeeEvent
    {
        $key = "wd:{$w->id}:{$event}" . ($related ? ":{$related}" : '');
        if ($e = WithdrawalFeeEvent::where('event_key', $key)->first()) return $e;       // idempotent: a fee event is written once
        return WithdrawalFeeEvent::create(['withdrawal_id' => $w->id, 'user_id' => $w->user_id, 'event' => $event, 'amount' => $amount, 'currency' => $w->fee_currency ?: 'USD',
            'event_key' => $key, 'reference' => 'WF-' . strtoupper(Str::random(10)), 'related_event_id' => $related, 'reason' => $reason,
            'actor_id' => $actor?->id, 'actor_name' => $actor ? ($actor->name ?? $actor->full_name ?? 'Staff') : 'system', 'created_at' => now()]);
    }

    public function recordCharged(Withdrawal $w, ?User $actor): void
    {
        if ($w->fee_status === 'charged' && Decimal::gt(Decimal::fromInput($w->fee_amount), '0')) {
            $this->event($w, 'charged', Decimal::money($w->fee_amount), $actor, 'Fee disclosed and confirmed by the investor at submission');
        }
    }

    /** Call INSIDE the approval transaction, with the withdrawal row already locked and already marked approved. */
    public function onApproved(Withdrawal $w, User $actor): void
    {
        if ($w->fee_status !== 'charged') return;                                           // nothing to collect, or already handled: never twice
        $fee = Decimal::money($w->fee_amount);
        $this->event($w, 'collected', $fee, $actor, 'Withdrawal approved; fee retained from the gross amount');
        $w->forceFill(['fee_status' => 'collected'])->save();
        $this->ledger->record(['entry_key' => "withdrawal:{$w->id}:fee_collected", 'user_id' => $w->user_id, 'classification' => 'withdrawal_fee_collected', 'direction' => 'info',
            'amount' => $fee, 'currency' => $w->fee_currency ?: 'USD', 'withdrawal_id' => $w->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name ?? 'Staff', 'note' => 'Fee part of the gross debit; investor paid net']);
    }

    /** Call INSIDE the rejection transaction. No balance was ever debited for a rejected withdrawal, so no fee was taken. */
    public function onRejected(Withdrawal $w, User $actor): void
    {
        if ($w->fee_status !== 'charged') return;
        $this->event($w, 'not_collected', Decimal::money($w->fee_amount), $actor, 'Withdrawal rejected; no funds were debited so no fee was taken');
        $w->forceFill(['fee_status' => 'not_collected'])->save();
    }

    /**
     * Financial adjustment of a COLLECTED fee. action: reverse (fee given back - accounting record, optionally refunded to the wallet) | waive (same, recorded as a waiver)
     * | review (flag for Financial review; moves nothing). A reversal is linked to the collection it reverses; the original rows are never edited.
     */
    public function adjust(Withdrawal $w, User $actor, string $action, string $reason, bool $refundToWallet = false): Withdrawal
    {
        if (!in_array($action, ['reverse', 'waive', 'review'], true)) throw ValidationException::withMessages(['action' => ['Unknown fee action.']]);
        if (trim($reason) === '') throw ValidationException::withMessages(['reason' => ['A reason is required.']]);

        return DB::transaction(function () use ($w, $actor, $action, $reason, $refundToWallet) {
            $locked = Withdrawal::whereKey($w->id)->lockForUpdate()->first();
            $fee = Decimal::money($locked->fee_amount);
            if (Decimal::lte($fee, '0')) throw ValidationException::withMessages(['withdrawal' => ['This withdrawal has no fee.']]);

            if ($action === 'review') {
                $this->event($locked, 'review', $fee, $actor, $reason);
                $locked->forceFill(['fee_status' => 'review'])->save();
            } else {
                if ($locked->fee_status !== 'collected') throw ValidationException::withMessages(['withdrawal' => ['Only a collected fee can be reversed or waived (current fee status: ' . ($locked->fee_status ?? 'none') . ').']]);
                $collected = WithdrawalFeeEvent::where('event_key', "wd:{$locked->id}:collected")->first();
                if (!$collected) throw ValidationException::withMessages(['withdrawal' => ['No collection record exists for this fee; flag it for review instead.']]);
                $this->event($locked, $action === 'reverse' ? 'reversed' : 'waived', $fee, $actor, $reason, $collected->id);
                if ($refundToWallet) {
                    $t = config('financial_controls.balance_threshold_usd');   // same existing dual-approval threshold as referral credits (see ReferralRewardService)
                    if (config('financial_controls.dual_approval_enabled') && $t !== null && $t !== '' && Decimal::gte($fee, Decimal::money($t))) {
                        throw ValidationException::withMessages(['refund_to_wallet' => ['A refund at or above the Financial dual-approval threshold needs a second approver through the balance-adjustment approval workflow.']]);
                    }
                    // The ONLY wallet movement, through the existing authorised mechanism (row lock + idempotency key + audit). Idempotent per withdrawal.
                    app(BalanceAdjustmentService::class)->applySigned($locked->user_id, $fee, "Withdrawal fee refund for withdrawal #{$locked->id}: {$reason}", $actor, "wdfee-{$locked->id}-refund");
                }
                $locked->forceFill(['fee_status' => $action === 'reverse' ? 'reversed' : 'waived'])->save();
                $this->ledger->record(['entry_key' => "withdrawal:{$locked->id}:fee_{$action}", 'user_id' => $locked->user_id, 'classification' => 'withdrawal_fee_reversed', 'direction' => 'info',
                    'amount' => $fee, 'currency' => $locked->fee_currency ?: 'USD', 'withdrawal_id' => $locked->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name ?? 'Staff', 'note' => $reason]);
            }
            $this->audit->record($actor, "withdrawal_fee.{$action}", 'withdrawal', $locked->id, $locked->user_id, ['fee_status' => $w->fee_status],
                ['fee_status' => $locked->fee_status, 'fee' => $fee, 'refund_to_wallet' => $refundToWallet && $action !== 'review'], $reason);
            return $locked->fresh();
        });
    }

    // ── policy management ──────────────────────────────────────────────────────────────────────────────────────────────────────────
    /** Appends a new policy version. Only an Administrator may mark it approved (a recorded Management approval reference is mandatory for that). */
    public function savePolicy(User $actor, array $d): WithdrawalFeePolicy
    {
        $type = $d['fee_type'];
        $fixed = $d['fixed_amount'] ?? null; $pct = $d['percentage'] ?? null;
        if (in_array($type, ['fixed', 'combined'], true) && ($fixed === null || Decimal::lte(Decimal::fromInput($fixed), '0'))) throw ValidationException::withMessages(['fixed_amount' => ['A positive fixed amount is required for this fee type.']]);
        if (in_array($type, ['percentage', 'combined'], true) && ($pct === null || Decimal::lte(Decimal::fromInput($pct), '0') || Decimal::gt(Decimal::fromInput($pct), '100'))) throw ValidationException::withMessages(['percentage' => ['A percentage greater than 0 and at most 100 is required for this fee type.']]);
        if ($type === 'fixed') $pct = null;
        if ($type === 'percentage') $fixed = null;
        if ($type === 'none') { $fixed = null; $pct = null; }
        if (($d['currency'] ?? 'USD') !== config('ssi_task5.withdrawal_fee.default_currency', 'USD')) throw ValidationException::withMessages(['currency' => ['Only the wallet currency is supported; no exchange rate is ever assumed.']]);
        $min = $d['min_fee'] ?? null; $max = $d['max_fee'] ?? null;
        if ($min !== null && $max !== null && Decimal::gt(Decimal::fromInput($min), Decimal::fromInput($max))) throw ValidationException::withMessages(['min_fee' => ['The minimum fee cannot exceed the maximum fee.']]);

        $approve = (bool) ($d['policy_approved'] ?? false);
        if ($approve) {
            if (!($actor->role === 'admin')) throw ValidationException::withMessages(['policy_approved' => ['Only an Administrator may mark a fee policy as Management-approved.']]);
            if (empty($d['approval_reference'])) throw ValidationException::withMessages(['approval_reference' => ['The Management approval reference is required to approve a fee policy.']]);
        }
        $active = (bool) ($d['is_active'] ?? false);
        $effective = !empty($d['effective_from']) ? \Illuminate\Support\Carbon::parse($d['effective_from']) : now();
        if ($effective->lt(now()->subMinute())) throw ValidationException::withMessages(['effective_from' => ['The effective date cannot be in the past; a fee policy never applies retroactively.']]);

        return DB::transaction(function () use ($actor, $type, $fixed, $pct, $min, $max, $approve, $active, $effective, $d) {
            $prev = WithdrawalFeePolicy::current();
            $p = WithdrawalFeePolicy::create(['fee_type' => $type, 'fixed_amount' => $fixed !== null ? Decimal::money($fixed) : null, 'percentage' => $pct !== null ? Decimal::roundHalfUp(Decimal::fromInput($pct), 4) : null,
                'min_fee' => $min !== null ? Decimal::money($min) : null, 'max_fee' => $max !== null ? Decimal::money($max) : null, 'currency' => $d['currency'] ?? 'USD',
                'is_active' => $active, 'policy_approved' => $approve, 'approval_reference' => $approve ? $d['approval_reference'] : ($d['approval_reference'] ?? null), 'approved_by' => $approve ? $actor->id : null,
                'effective_from' => $effective, 'reason' => $d['reason'], 'configured_by' => $actor->id, 'configured_by_name' => $actor->name ?? $actor->full_name ?? 'Staff', 'created_at' => now()]);
            $this->audit->record($actor, 'withdrawal_fee.policy_saved', 'withdrawal_fee_policy', $p->id, null,
                $prev ? ['fee_type' => $prev->fee_type, 'fixed' => $prev->fixed_amount, 'percentage' => $prev->percentage, 'active' => $prev->is_active, 'approved' => $prev->policy_approved] : null,
                ['fee_type' => $p->fee_type, 'fixed' => $p->fixed_amount, 'percentage' => $p->percentage, 'active' => $p->is_active, 'approved' => $p->policy_approved, 'effective_from' => $effective->toIso8601String()], $d['reason']);
            return $p;
        });
    }

    // ── reporting / reconciliation ─────────────────────────────────────────────────────────────────────────────────────────────────
    /** Fees CHARGED (disclosed on submitted withdrawals) are reported separately from fees actually COLLECTED (withdrawal approved). Sums use integer cents. */
    public function summary(): array
    {
        $rows = Withdrawal::query()->select('status', 'fee_status', 'gross_amount', 'fee_amount', 'net_amount', 'amount')->get();
        $c = fn ($v) => Decimal::toCents(Decimal::money($v));
        $t = ['gross_requested' => 0, 'fees_charged' => 0, 'net_payable' => 0, 'fees_collected' => 0, 'fees_not_collected' => 0, 'fees_reversed_or_adjusted' => 0, 'fees_awaiting_review' => 0, 'fees_outstanding' => 0];
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'hold' => 0];
        foreach ($rows as $r) {
            $gross = $c($r->gross_amount ?? $r->amount); $fee = $c($r->fee_amount ?? 0); $net = $c($r->net_amount ?? $r->amount);
            $counts[$r->status] = ($counts[$r->status] ?? 0) + 1;
            $t['gross_requested'] += $gross; $t['net_payable'] += $net;
            if ($fee > 0) $t['fees_charged'] += $fee;
            switch ($r->fee_status) {
                case 'collected': $t['fees_collected'] += $fee; break;
                case 'not_collected': $t['fees_not_collected'] += $fee; break;
                case 'reversed': case 'waived': $t['fees_reversed_or_adjusted'] += $fee; break;
                case 'review': $t['fees_awaiting_review'] += $fee; break;
                case 'charged': $t['fees_outstanding'] += $fee; break;
            }
        }
        return ['amounts' => array_map(fn ($x) => Decimal::fromCents($x), $t), 'withdrawal_counts' => $counts, 'currency' => 'USD',
            'net_fees_retained' => Decimal::fromCents($t['fees_collected']), 'reconciliation' => $this->reconcile()];
    }

    /** Consistency checks between withdrawal rows, the fee sub-ledger and statuses. An empty list = reconciled. */
    public function reconcile(): array
    {
        $issues = [];
        foreach (Withdrawal::whereNotNull('fee_amount')->where('fee_amount', '>', 0)->get() as $w) {
            $ev = WithdrawalFeeEvent::where('withdrawal_id', $w->id)->pluck('event')->all();
            $add = function ($code, $msg) use (&$issues, $w): void { $issues[] = ['withdrawal_id' => $w->id, 'code' => $code, 'message' => $msg]; };
            if (Decimal::cmp(Decimal::add(Decimal::money($w->fee_amount), Decimal::money($w->net_amount)), Decimal::money($w->gross_amount)) !== 0) $add('arithmetic', 'gross != fee + net');
            if ($w->status === 'approved' && $w->fee_status === 'charged') $add('approved_uncollected', 'Approved withdrawal still shows the fee as charged, not collected.');
            if ($w->status === 'rejected' && in_array($w->fee_status, ['collected', 'charged'], true)) $add('rejected_with_fee', 'Rejected withdrawal still shows a charged/collected fee.');
            if ($w->fee_status === 'collected' && !in_array('collected', $ev, true)) $add('missing_event', 'Collected fee has no collection event.');
            if (count(array_keys($ev, 'collected', true)) > 1) $add('double_collection', 'Fee collected more than once.');
            if ($w->fee_status === 'review') $add('flagged', 'Flagged for Financial review.');
        }
        return $issues;
    }
}
