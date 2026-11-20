<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\ReferralPolicy;
use App\Services\DepositPaymentService;
use App\Models\ReferralReward;
use App\Models\Withdrawal;
use App\Models\WithdrawalFeePolicy;
use App\Services\Task5\WithdrawalFeeCalculator;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Policy impact PREVIEW — strictly read-only.
 * Takes proposed referral / withdrawal-fee terms and returns: current terms, proposed terms, differences, illustrative impact, warnings,
 * validation failures and the approval status. It never saves a draft, approves, activates, credits, debits or migrates anything: no model is
 * created or updated (the proposed policy exists only as an unsaved in-memory object). Saving remains the existing separate-author draft → approval flow.
 */
class PolicyPreviewController extends Controller
{
    private const SAMPLES = ['10.00', '100.00', '500.00', '1000.00', '5000.00'];
    private const LOOKBACK_DAYS = 30;
    private const ROW_CAP = 5000;

    private function approvalStatus(Request $r): array
    {
        return ['state' => 'preview only — nothing saved', 'author_if_saved' => $r->user()->name ?? 'you',
            'approver' => 'A DIFFERENT Administrator must approve a saved draft (separation of duties); an author can never approve their own draft.',
            'approved' => false, 'active' => false, 'effective_from' => null,
            'management_approval' => 'Not requested. A preview is not Management approval and does not satisfy any decision in the register.'];
    }

    // POST /financial/withdrawal-fees/policy/preview
    public function withdrawalFee(Request $request, WithdrawalFeeCalculator $calc)
    {
        Gate::authorize('financial.view-withdrawal-fees');
        $v = $request->validate([
            'fee_type' => ['required', 'in:none,fixed,percentage,combined'], 'fixed_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_fee' => ['nullable', 'numeric', 'min:0'], 'max_fee' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'in:USD'],
        ]);
        $type = $v['fee_type']; $fixed = $v['fixed_amount'] ?? null; $pct = $v['percentage'] ?? null; $min = $v['min_fee'] ?? null; $max = $v['max_fee'] ?? null;
        $errors = []; $warnings = [];
        if (in_array($type, ['fixed', 'combined'], true) && ($fixed === null || Decimal::lte(Decimal::fromInput($fixed), '0'))) $errors['fixed_amount'] = 'A positive fixed amount is required for this fee type.';
        if (in_array($type, ['percentage', 'combined'], true) && ($pct === null || Decimal::lte(Decimal::fromInput($pct), '0') || Decimal::gt(Decimal::fromInput($pct), '100'))) $errors['percentage'] = 'A percentage greater than 0 and at most 100 is required for this fee type.';
        if ($min !== null && $max !== null && Decimal::gt(Decimal::fromInput($min), Decimal::fromInput($max))) $errors['min_fee'] = 'The minimum fee cannot exceed the maximum fee.';
        if ($type === 'fixed') $pct = null; if ($type === 'percentage') $fixed = null; if ($type === 'none') { $fixed = null; $pct = null; }
        if ($pct !== null && Decimal::gt(Decimal::fromInput($pct), '10')) $warnings[] = 'A percentage above 10% is unusually high for a withdrawal fee; confirm it is intended.';
        if ($type === 'percentage' && $min === null) $warnings[] = 'No minimum fee: very small withdrawals would be charged cents.';
        if ($type !== 'none' && $max === null && $pct !== null) $warnings[] = 'No maximum fee: large withdrawals pay an uncapped fee.';

        $cur = WithdrawalFeePolicy::current();
        $curSet = $cur ? ['fee_type' => $cur->fee_type, 'fixed_amount' => $cur->fixed_amount !== null ? (string) $cur->fixed_amount : null, 'percentage' => $cur->percentage !== null ? (string) $cur->percentage : null,
            'min_fee' => $cur->min_fee !== null ? (string) $cur->min_fee : null, 'max_fee' => $cur->max_fee !== null ? (string) $cur->max_fee : null,
            'charging_fees' => (bool) ($cur->is_active && $cur->policy_approved && $cur->fee_type !== 'none')] : ['fee_type' => 'none', 'charging_fees' => false];
        $propSet = ['fee_type' => $type, 'fixed_amount' => $fixed, 'percentage' => $pct, 'min_fee' => $min, 'max_fee' => $max];
        $diff = [];
        foreach (['fee_type', 'fixed_amount', 'percentage', 'min_fee', 'max_fee'] as $k) {
            $a = $curSet[$k] ?? null; $b = $propSet[$k] ?? null;
            if ((string) $a !== (string) $b && !(is_numeric($a) && is_numeric($b) && Decimal::eq(Decimal::fromInput($a), Decimal::fromInput($b)))) $diff[] = ['field' => $k, 'current' => $a, 'proposed' => $b];
        }
        if (!$cur || !$curSet['charging_fees']) $warnings[] = 'No approved, active fee policy is charging fees today; the proposal would be the first.';

        $impact = ['samples' => [], 'recent' => null];
        if (!$errors) {
            // Unsaved, in-memory object: forced active+approved ONLY so the calculator shows what the fee would be IF it were approved.
            $prop = new WithdrawalFeePolicy(['fee_type' => $type, 'fixed_amount' => $fixed, 'percentage' => $pct, 'min_fee' => $min, 'max_fee' => $max, 'currency' => 'USD']);
            $prop->is_active = true; $prop->policy_approved = true; $prop->effective_from = now()->subMinute();
            $quote = fn ($pol, $amt) => $calc->calculate($amt, $pol)['fee'];
            foreach (self::SAMPLES as $s) $impact['samples'][] = ['withdrawal' => $s, 'current_fee' => $quote($cur, $s), 'proposed_fee' => $quote($prop, $s)];
            $rows = Withdrawal::where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))->whereIn('status', ['pending', 'approved', 'completed', 'hold'])->orderByDesc('id')->limit(self::ROW_CAP + 1)->get(['id', 'amount', 'gross_amount']);
            $truncated = $rows->count() > self::ROW_CAP; $rows = $rows->take(self::ROW_CAP);
            $c = '0.00'; $p = '0.00'; $n = 0;
            foreach ($rows as $w) {
                $g = Decimal::money($w->gross_amount ?? $w->amount);
                if (Decimal::lte($g, '0')) continue;
                $c = Decimal::add($c, $quote($cur, $g)); $p = Decimal::add($p, $quote($prop, $g)); $n++;
            }
            $impact['recent'] = ['lookback_days' => self::LOOKBACK_DAYS, 'withdrawals_considered' => $n, 'truncated' => $truncated, 'fees_under_current_terms' => $c, 'fees_under_proposed_terms' => $p,
                'difference' => Decimal::sub($p, $c), 'note' => 'ILLUSTRATIVE ONLY: what recent requests would have paid. Submitted withdrawals keep the fee they were disclosed with; fee policies never apply retroactively.'];
        }
        return response()->json(['read_only' => true, 'valid' => !$errors, 'errors' => $errors, 'warnings' => $warnings, 'current' => $curSet, 'proposed' => $propSet, 'differences' => $diff, 'impact' => $impact, 'approval' => $this->approvalStatus($request)]);
    }

    // POST /financial/referral-policy/preview
    public function referral(Request $request)
    {
        Gate::authorize('financial.view-referrals');
        $v = $request->validate(['reward_amount' => ['required', 'numeric', 'max:1000000'], 'currency' => ['nullable', 'string', 'in:USD']]);
        $errors = []; $warnings = [];
        $amt = Decimal::fromInput($v['reward_amount']);
        if (Decimal::lte($amt, '0')) $errors['reward_amount'] = 'The reward amount must be greater than zero.';
        $cur = ReferralPolicy::current();
        $curAmt = $cur ? (string) $cur->reward_amount : null;
        $diff = ($curAmt === null || !Decimal::eq(Decimal::fromInput($curAmt), $amt)) ? [['field' => 'reward_amount', 'current' => $curAmt, 'proposed' => $v['reward_amount']]] : [];
        if ($cur && !$cur->policy_approved) $warnings[] = 'The current referral policy is not Management-approved.';
        if ($curAmt !== null && Decimal::gt($curAmt, '0') && Decimal::gt($amt, Decimal::mul($curAmt, '2'))) $warnings[] = 'The proposed reward is more than double the current reward.';
        $impact = null;
        if (!$errors) {
            $q = ReferralReward::where('created_at', '>=', now()->subDays(90));
            $count = (clone $q)->count();
            $cost = Decimal::mul((string) $count, $amt);
            $was = (clone $q)->sum('amount');
            $impact = ['lookback_days' => 90, 'rewards_created' => $count, 'paid_or_owed_at_their_original_amounts' => Decimal::money((string) $was), 'same_count_at_proposed_amount' => Decimal::money($cost),
                'note' => 'ILLUSTRATIVE ONLY. Existing rewards keep the amount they were created with; a new amount applies only to future rewards, and which referrals qualify is still a pending Management decision.'];
        }
        return response()->json(['read_only' => true, 'valid' => !$errors, 'errors' => $errors, 'warnings' => $warnings, 'current' => ['reward_amount' => $curAmt, 'approved' => (bool) ($cur?->policy_approved)], 'proposed' => ['reward_amount' => $v['reward_amount']],
            'differences' => $diff, 'impact' => $impact, 'approval' => $this->approvalStatus($request)]);
    }

    // POST /financial/deposits/{deposit}/payment-preview — FX / valuation dry run for a deposit. Changes NO rate, deposit or balance.
    public function payment(Request $request, Deposit $deposit, DepositPaymentService $payments)
    {
        Gate::authorize('financial.review-transactions');
        $v = $request->validate(['paid_amount' => ['required', 'numeric'], 'paid_currency' => ['required', 'string', 'max:8'], 'rate' => ['nullable', 'numeric'], 'rate_date' => ['nullable', 'date'],
            'payment_date' => ['nullable', 'date'], 'approved_amount' => ['nullable', 'numeric']]);
        $p = $payments->preview($deposit, $v);
        $warnings = $p['rate_warnings'];
        if (!in_array($deposit->status, ['pending', 'hold'], true)) $warnings[] = 'This deposit is ' . $deposit->status . '; payment details can no longer be recorded.';
        return response()->json(['read_only' => true, 'valid' => $p['valid'], 'errors' => $p['errors'], 'warnings' => $warnings,
            'current' => ['requested_usd' => $p['requested_usd'], 'recorded_paid_amount' => $deposit->paid_amount, 'recorded_currency' => $deposit->paid_currency, 'recorded_rate' => $deposit->fx_rate, 'verified' => (bool) $deposit->payment_verified_at],
            'proposed' => ['paid_amount' => $v['paid_amount'], 'paid_currency' => strtoupper($v['paid_currency']), 'rate' => $p['rate'], 'rate_date' => $v['rate_date'] ?? null],
            'impact' => $p['valid'] ? ['computed_usd' => $p['computed_usd'], 'approved_usd' => $p['approved_usd'], 'difference_from_computed_pct' => $p['difference_pct'], 'tolerance_pct' => $p['tolerance_pct'],
                'would_need_amount_acknowledgement' => $p['would_need_amount_acknowledgement'], 'change_vs_requested_usd' => round($p['approved_usd'] - $p['requested_usd'], 2), 'rounding_mode' => $p['mode'],
                'note' => 'ILLUSTRATIVE ONLY. Nothing is recorded; no rate is stored or changed. The wallet is credited only when a different review step approves the deposit.'] : null,
            'approval' => $this->approvalStatus($request)]);
    }

    // POST /admin/investment-plan-preview — what editing a plan would mean. Plans are edited directly today (no draft/approval workflow exists: decision D35).
    public function plan(Request $request)
    {
        $v = $request->validate(['plan_id' => ['required', 'integer', 'exists:investment_plans,id'], 'min_amount' => ['nullable', 'numeric', 'min:1'], 'max_amount' => ['nullable', 'numeric'],
            'profit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'status' => ['nullable', 'in:active,inactive']]);
        $plan = InvestmentPlan::findOrFail($v['plan_id']);
        $cur = ['min_amount' => (string) $plan->min_amount, 'max_amount' => $plan->max_amount !== null ? (string) $plan->max_amount : null, 'profit_percent' => (string) ($plan->profit_percentage ?? $plan->profit_percent),
            'duration_days' => (string) $plan->duration_days, 'status' => $plan->status];
        $prop = array_merge($cur, array_filter(array_intersect_key($v, $cur), fn ($x) => $x !== null));
        if (array_key_exists('max_amount', $v) && $v['max_amount'] === null && $request->has('max_amount')) $prop['max_amount'] = null;
        $errors = []; $warnings = [];
        if ($prop['max_amount'] !== null && Decimal::gt(Decimal::fromInput($prop['min_amount']), Decimal::fromInput($prop['max_amount']))) $errors['max_amount'] = 'The maximum cannot be below the minimum.';
        $diff = [];
        foreach ($cur as $k => $a) { $b = $prop[$k]; if ((string) $a !== (string) $b && !(is_numeric($a) && is_numeric($b) && Decimal::eq(Decimal::fromInput($a), Decimal::fromInput($b)))) $diff[] = ['field' => $k, 'current' => $a, 'proposed' => $b]; }
        $active = InvestmentAccount::where('investment_plan_id', $plan->id)->where('status', 'active');
        $nActive = (clone $active)->count(); $pending = Deposit::where('investment_plan_id', $plan->id)->whereIn('status', ['pending', 'hold'])->count();
        if ($nActive > 0) $warnings[] = "{$nActive} active investment(s) already hold their own profit rate and dates (stored when they were opened); they are NOT changed by editing the plan.";
        if ($pending > 0) $warnings[] = "{$pending} pending/held deposit(s) carry this plan. They are opened with the plan terms in force WHEN THEY ARE APPROVED, so a change here applies to them.";
        if (Decimal::gt(Decimal::fromInput($prop['profit_percent']), '100')) $errors['profit_percent'] = 'Profit percent cannot exceed 100.';
        if (!Decimal::eq(Decimal::fromInput($cur['profit_percent']), Decimal::fromInput($prop['profit_percent']))) $warnings[] = 'The profit rate changes what NEW investments will earn; confirm Management approval outside this screen. No plan-change approval workflow exists yet (D35).';
        $samples = [];
        if (!$errors) foreach (['100.00', '1000.00', '10000.00'] as $a) {
            if (Decimal::lt(Decimal::fromInput($a), Decimal::fromInput($prop['min_amount']))) continue;
            if ($prop['max_amount'] !== null && Decimal::gt(Decimal::fromInput($a), Decimal::fromInput($prop['max_amount']))) continue;
            $samples[] = ['amount' => $a, 'profit_current' => Decimal::percentOf($a, Decimal::fromInput($cur['profit_percent'])), 'profit_proposed' => Decimal::percentOf($a, Decimal::fromInput($prop['profit_percent']))];
        }
        return response()->json(['read_only' => true, 'valid' => !$errors, 'errors' => $errors, 'warnings' => $warnings, 'current' => $cur, 'proposed' => $prop, 'differences' => $diff,
            'impact' => ['active_investments_unchanged' => $nActive, 'pending_deposits_affected' => $pending, 'samples' => $samples, 'note' => 'ILLUSTRATIVE ONLY. Nothing is saved.'],
            'approval' => ['state' => 'preview only — nothing saved', 'approved' => false, 'active' => false, 'management_approval' => 'No approval workflow exists for plan edits today (decision D35). A preview is not approval.']]);
    }
}
