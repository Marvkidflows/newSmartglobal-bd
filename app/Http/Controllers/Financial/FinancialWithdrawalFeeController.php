<?php
// Task 5: Financial Administration - withdrawal fee policy, fee report, fee adjustments and reconciliation.
namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\WithdrawalFeeEvent;
use App\Models\WithdrawalFeePolicy;
use App\Services\Task5\WithdrawalFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FinancialWithdrawalFeeController extends Controller
{
    public function __construct(protected WithdrawalFeeService $fees) {}

    protected function policyRow(WithdrawalFeePolicy $p): array
    {
        return ['id' => $p->id, 'fee_type' => $p->fee_type, 'fixed_amount' => $p->fixed_amount !== null ? (string) $p->fixed_amount : null, 'percentage' => $p->percentage !== null ? (string) $p->percentage : null,
            'min_fee' => $p->min_fee !== null ? (string) $p->min_fee : null, 'max_fee' => $p->max_fee !== null ? (string) $p->max_fee : null, 'currency' => $p->currency, 'is_active' => $p->is_active,
            'policy_approved' => $p->policy_approved, 'approval_reference' => $p->approval_reference, 'effective_from' => optional($p->effective_from)->toIso8601String(), 'reason' => $p->reason,
            'configured_by' => $p->configured_by_name, 'created_at' => optional($p->created_at)->toIso8601String()];
    }

    // GET /financial/withdrawal-fees/policy
    public function policy()
    {
        Gate::authorize('financial.view-withdrawal-fees');
        $cur = $this->fees->currentPolicy();
        return response()->json([
            'current' => $cur ? $this->policyRow($cur) : null,
            'charging_fees' => (bool) ($cur && $cur->is_active && $cur->policy_approved && $cur->fee_type !== 'none'),
            'history' => WithdrawalFeePolicy::orderByDesc('id')->limit(100)->get()->map(fn ($p) => $this->policyRow($p))->values(),
            'can_approve_policy' => request()->user()->role === 'admin',
        ]);
    }

    // POST /financial/withdrawal-fees/policy
    public function savePolicy(Request $request)
    {
        Gate::authorize('financial.configure-withdrawal-fee');
        $v = $request->validate([
            'fee_type' => ['required', 'in:none,fixed,percentage,combined'], 'fixed_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_fee' => ['nullable', 'numeric', 'min:0'], 'max_fee' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'in:USD'],
            'is_active' => ['nullable', 'boolean'], 'policy_approved' => ['nullable', 'boolean'], 'approval_reference' => ['nullable', 'string', 'max:120'],
            'effective_from' => ['nullable', 'date'], 'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $p = $this->fees->savePolicy($request->user(), $v);
        return response()->json(['message' => 'Fee policy version saved. Withdrawals already submitted keep the fee they were disclosed with.', 'policy' => $this->policyRow($p)], 201);
    }

    // POST /financial/withdrawal-fees/policy/{policy}/approve   (D-04: a DIFFERENT Administrator approves an existing draft)
    public function approvePolicy(Request $request, int $policy)
    {
        Gate::authorize('financial.configure-withdrawal-fee');
        $v = $request->validate(['approval_reference' => ['required', 'string', 'min:3', 'max:120'], 'reason' => ['required', 'string', 'min:3', 'max:900']]);
        $p = $this->fees->approveDraft($request->user(), $policy, $v['approval_reference'], $v['reason']);
        return response()->json(['message' => 'Fee policy approved as a new version. Withdrawals already submitted keep the fee they were disclosed with.', 'policy' => $this->policyRow($p)], 201);
    }

    // POST /financial/withdrawal-fees/policy/{policy}/reject
    public function rejectPolicy(Request $request, int $policy)
    {
        Gate::authorize('financial.configure-withdrawal-fee');
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:900']]);
        $this->fees->rejectDraft($request->user(), $policy, $v['reason']);
        return response()->json(['message' => 'Draft rejection recorded in the audit trail.']);
    }

    // GET /financial/withdrawal-fees/summary
    public function summary()
    {
        Gate::authorize('financial.view-withdrawal-fees');
        return response()->json(['summary' => $this->fees->summary()]);
    }

    // GET /financial/withdrawal-fees/withdrawals
    public function withdrawals(Request $request)
    {
        Gate::authorize('financial.view-withdrawal-fees');
        $q = Withdrawal::with('user:id,name,full_name,email')->latest('id');
        if (in_array($request->query('status'), ['pending', 'approved', 'rejected', 'hold'], true)) $q->where('status', $request->query('status'));
        if (in_array($request->query('fee_status'), ['none', 'charged', 'collected', 'not_collected', 'reversed', 'waived', 'review'], true)) $q->where('fee_status', $request->query('fee_status'));
        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $q->where(fn ($w) => $w->where('transaction_reference', 'like', $like)->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)));
        }
        $p = $q->paginate(min(max((int) $request->query('per_page', 25), 1), 100));
        return response()->json(['withdrawals' => collect($p->items())->map(fn (Withdrawal $w) => [
            'id' => $w->id, 'transaction_reference' => $w->transaction_reference, 'status' => $w->status, 'fee_status' => $w->fee_status ?: 'none', 'currency' => $w->fee_currency ?: 'USD',
            'gross_amount' => (string) ($w->gross_amount ?? $w->amount), 'fee_amount' => (string) ($w->fee_amount ?? '0.00'), 'net_amount' => (string) ($w->net_amount ?? $w->amount),
            'fee_policy_id' => $w->fee_policy_id, 'fee_calculation' => $w->fee_calculation, 'investor' => ['id' => $w->user_id, 'name' => $w->user?->name ?? $w->user?->full_name, 'email' => $w->user?->email],
            'created_at' => optional($w->created_at)->toIso8601String(), 'processed_at' => optional($w->processed_at)->toIso8601String()])->values(),
            'meta' => ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]]);
    }

    // GET /financial/withdrawal-fees/withdrawals/{withdrawal}/events
    public function events(Withdrawal $withdrawal)
    {
        Gate::authorize('financial.view-withdrawal-fees');
        return response()->json(['events' => WithdrawalFeeEvent::where('withdrawal_id', $withdrawal->id)->orderBy('id')->get()->map(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'amount' => (string) $e->amount, 'currency' => $e->currency,
            'reference' => $e->reference, 'related_event_id' => $e->related_event_id, 'reason' => $e->reason, 'actor' => $e->actor_name, 'at' => optional($e->created_at)->toIso8601String()])->values()]);
    }

    // POST /financial/withdrawal-fees/withdrawals/{withdrawal}/adjust
    public function adjust(Request $request, Withdrawal $withdrawal)
    {
        Gate::authorize('financial.adjust-withdrawal-fee');
        $v = $request->validate(['action' => ['required', 'in:reverse,waive,review'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'refund_to_wallet' => ['nullable', 'boolean']]);
        if ($v['action'] !== 'review' && !config('ssi_task5.withdrawal_fee.allow_adjustments')) {
            return response()->json(['message' => 'Reversing, waiving or refunding a withdrawal fee is not enabled: the fee-refund rules await Management approval. You can still flag the fee for review.'], 403);
        }
        $refund = (bool) ($v['refund_to_wallet'] ?? false);
        if ($refund) Gate::authorize('financial.adjust-balance');                       // the wallet moves only for someone already allowed to adjust balances
        $w = $this->fees->adjust($withdrawal, $request->user(), $v['action'], $v['reason'], $refund);
        return response()->json(['message' => 'Fee ' . $v['action'] . ' recorded.', 'fee_status' => $w->fee_status]);
    }

    // GET /financial/withdrawal-fees/reconciliation
    public function reconciliation()
    {
        Gate::authorize('financial.view-withdrawal-fees');
        $issues = $this->fees->reconcile();
        return response()->json(['reconciled' => count($issues) === 0, 'issues' => $issues]);
    }
}
