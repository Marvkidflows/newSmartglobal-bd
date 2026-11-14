<?php
// Task 5: Financial Administration - referral management. Every action re-checks its Gate (the route group's middleware is not the only control)
// and the service re-checks the actor's role/status at execution time.
namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\ReferralEvent;
use App\Models\ReferralPolicy;
use App\Models\ReferralReward;
use App\Services\Task5\ReferralRewardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FinancialReferralController extends Controller
{
    public function __construct(protected ReferralRewardService $svc) {}

    protected function row(ReferralReward $r): array
    {
        return [
            'id' => $r->id, 'reference' => $r->reference, 'status' => $r->status, 'eligibility' => $r->eligibility, 'suspicious' => (bool) $r->suspicious, 'flags' => $r->flags ?? [],
            'amount' => (string) $r->amount, 'currency' => $r->currency, 'referral_code' => $r->referral_code,
            'referrer' => ['id' => $r->referrer_id, 'name' => $r->referrer?->name ?? $r->referrer?->full_name, 'email' => $r->referrer?->email],
            'referred' => ['id' => $r->referred_id, 'name' => $r->referred?->name ?? $r->referred?->full_name, 'email' => $r->referred?->email, 'kyc_status' => $r->referred?->kyc_status],
            'registration_started_at' => optional($r->registration_started_at)->toIso8601String(), 'registration_completed_at' => optional($r->registration_completed_at)->toIso8601String(),
            'activated_at' => optional($r->activated_at)->toIso8601String(), 'eligibility_checked_at' => optional($r->eligibility_checked_at)->toIso8601String(),
            'decision_reason' => $r->decision_reason, 'decided_by' => $r->decided_by_name, 'decided_at' => optional($r->decided_at)->toIso8601String(),
            'credited_at' => optional($r->credited_at)->toIso8601String(), 'credit_reference' => $r->credit_reference,
            'reversal_reference' => $r->reversal_reference, 'reversal_reason' => $r->reversal_reason, 'reversed_at' => optional($r->reversed_at)->toIso8601String(),
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }

    protected function filtered(Request $request)
    {
        $q = ReferralReward::with(['referrer:id,name,full_name,email', 'referred:id,name,full_name,email,kyc_status'])->latest('id');
        if (in_array($request->query('status'), ReferralReward::STATUSES, true)) $q->where('status', $request->query('status'));
        if (in_array($request->query('eligibility'), ['not_checked', 'policy_not_approved', 'not_eligible', 'eligible'], true)) $q->where('eligibility', $request->query('eligibility'));
        if ($request->query('suspicious') !== null && $request->query('suspicious') !== '') $q->where('suspicious', filter_var($request->query('suspicious'), FILTER_VALIDATE_BOOLEAN));
        if ($request->filled('from')) $q->whereDate('created_at', '>=', $request->query('from'));
        if ($request->filled('to')) $q->whereDate('created_at', '<=', $request->query('to'));
        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $q->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('referral_code', 'like', $like)->orWhere('credit_reference', 'like', $like)
                ->orWhereHas('referrer', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orWhereHas('referred', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)));
        }
        return $q;
    }

    // GET /financial/referrals/summary
    public function summary()
    {
        Gate::authorize('financial.view-referrals');
        return response()->json(['summary' => $this->svc->summary()]);
    }

    // GET /financial/referrals
    public function index(Request $request)
    {
        Gate::authorize('financial.view-referrals');
        $p = $this->filtered($request)->paginate(min(max((int) $request->query('per_page', 25), 1), 100));
        return response()->json(['rewards' => collect($p->items())->map(fn ($r) => $this->row($r))->values(), 'meta' => ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total(), 'per_page' => $p->perPage()]]);
    }

    // GET /financial/referrals/{reward}
    public function show(ReferralReward $reward)
    {
        Gate::authorize('financial.view-referrals');
        $reward->load(['referrer:id,name,full_name,email', 'referred:id,name,full_name,email,kyc_status', 'events']);
        return response()->json([
            'reward' => $this->row($reward) + ['eligibility_detail' => $reward->eligibility_detail],
            'history' => $reward->events->map(fn ($e) => ['event' => $e->event, 'from' => $e->from_status, 'to' => $e->to_status, 'reason' => $e->reason, 'detail' => $e->detail, 'actor' => $e->actor_name, 'at' => optional($e->created_at)->toIso8601String()])->values(),
            'funnel' => ReferralEvent::where('referred_id', $reward->referred_id)->orderBy('id')->get(['event', 'created_at'])->map(fn ($e) => ['event' => $e->event, 'at' => optional($e->created_at)->toIso8601String()])->values(),
        ]);
    }

    // POST /financial/referrals/{reward}/evaluate
    public function evaluate(Request $request, ReferralReward $reward)
    {
        Gate::authorize('financial.manage-referrals');
        return response()->json(['reward' => $this->row($this->svc->evaluate($reward, $request->user())->load('referrer:id,name,full_name,email', 'referred:id,name,full_name,email,kyc_status'))]);
    }

    // POST /financial/referrals/{reward}/approve
    public function approve(Request $request, ReferralReward $reward)
    {
        Gate::authorize('financial.manage-referrals');
        $v = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        return response()->json(['message' => 'Reward approved. It is NOT yet credited.', 'reward' => $this->row($this->svc->approve($reward, $request->user(), $v['note'] ?? null)->load('referrer:id,name,full_name,email', 'referred:id,name,full_name,email,kyc_status'))]);
    }

    // POST /financial/referrals/{reward}/reject
    public function reject(Request $request, ReferralReward $reward)
    {
        Gate::authorize('financial.manage-referrals');
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        return response()->json(['message' => 'Reward rejected.', 'reward' => $this->row($this->svc->reject($reward, $request->user(), $v['reason'])->load('referrer:id,name,full_name,email', 'referred:id,name,full_name,email,kyc_status'))]);
    }

    // POST /financial/referrals/{reward}/credit
    public function credit(Request $request, ReferralReward $reward)
    {
        Gate::authorize('financial.manage-referrals');
        return response()->json(['message' => 'Reward credited to the investor wallet.', 'reward' => $this->row($this->svc->credit($reward, $request->user())->load('referrer:id,name,full_name,email', 'referred:id,name,full_name,email,kyc_status'))]);
    }

    // POST /financial/referrals/{reward}/reverse   (Administrator only)
    public function reverse(Request $request, ReferralReward $reward)
    {
        Gate::authorize('financial.reverse-referral-reward');
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        return response()->json(['message' => 'Reward reversed.', 'reward' => $this->row($this->svc->reverse($reward, $request->user(), $v['reason'])->load('referrer:id,name,full_name,email', 'referred:id,name,full_name,email,kyc_status'))]);
    }

    // POST /financial/referrals/{reward}/flag | /unflag
    public function flag(Request $request, ReferralReward $reward) { return $this->setFlag($request, $reward, true); }
    public function unflag(Request $request, ReferralReward $reward) { return $this->setFlag($request, $reward, false); }

    protected function setFlag(Request $request, ReferralReward $reward, bool $on)
    {
        Gate::authorize('financial.manage-referrals');
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        return response()->json(['reward' => $this->row($this->svc->setFlag($reward, $request->user(), $on, $v['reason'])->load('referrer:id,name,full_name,email', 'referred:id,name,full_name,email,kyc_status'))]);
    }

    // GET /financial/referrals-export   (CSV, same filters as the list)
    public function export(Request $request)
    {
        Gate::authorize('financial.view-referrals');
        $safe = fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;      // CSV/formula-injection guard
        $q = $this->filtered($request);
        return response()->streamDownload(function () use ($q, $safe) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['reference', 'status', 'eligibility', 'suspicious', 'amount', 'currency', 'referrer_name', 'referrer_email', 'referred_name', 'referred_email', 'referral_code',
                'registration_completed_at', 'activated_at', 'decision_reason', 'decided_by', 'credited_at', 'credit_reference', 'reversal_reference', 'reversal_reason']);
            $q->chunkById(500, function ($rows) use ($out, $safe) {
                foreach ($rows as $r) fputcsv($out, array_map($safe, [$r->reference, $r->status, $r->eligibility, $r->suspicious ? 'yes' : 'no', (string) $r->amount, $r->currency,
                    $r->referrer?->name, $r->referrer?->email, $r->referred?->name, $r->referred?->email, $r->referral_code, optional($r->registration_completed_at)->toIso8601String(),
                    optional($r->activated_at)->toIso8601String(), $r->decision_reason, $r->decided_by_name, optional($r->credited_at)->toIso8601String(), $r->credit_reference, $r->reversal_reference, $r->reversal_reason]));
            });
            fclose($out);
        }, 'referral-rewards-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    // GET /financial/referral-policy
    public function policy()
    {
        Gate::authorize('financial.view-referrals');
        $cur = ReferralPolicy::current();
        $hist = ReferralPolicy::orderByDesc('id')->limit(100)->get()->map(fn ($p) => ['id' => $p->id, 'reward_amount' => (string) $p->reward_amount, 'previous_reward_amount' => $p->previous_reward_amount !== null ? (string) $p->previous_reward_amount : null,
            'currency' => $p->currency, 'criteria' => $p->criteria, 'policy_approved' => $p->policy_approved, 'approval_reference' => $p->approval_reference, 'effective_from' => optional($p->effective_from)->toIso8601String(),
            'reason' => $p->reason, 'changed_by' => $p->changed_by_name, 'created_at' => optional($p->created_at)->toIso8601String()]);
        return response()->json(['current_id' => $cur?->id, 'policy' => $hist->firstWhere('id', $cur?->id), 'history' => $hist,
            'can_approve_policy' => request()->user()->role === 'admin',
            'pending_management_decisions' => ['Definition of an eligible activated referral', 'Whether KYC approval is mandatory', 'Whether a qualifying investment / completed cycle is required', 'Whether the referrer must remain active',
                'Limits on number / total value of rewards', 'Destination account for rewards (wallet assumed)', 'Whether and when rewards may be reversed']]);
    }

    // POST /financial/referral-policy
    public function savePolicy(Request $request)
    {
        Gate::authorize('financial.configure-referral-policy');
        $v = $request->validate([
            'reward_amount' => ['required', 'numeric', 'gt:0', 'max:1000000'], 'currency' => ['nullable', 'string', 'in:USD'],
            'criteria' => ['required', 'array'], 'criteria.*' => ['nullable'],
            'policy_approved' => ['nullable', 'boolean'], 'approval_reference' => ['nullable', 'string', 'max:120'],
            'effective_from' => ['nullable', 'date'], 'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $p = $this->svc->savePolicy($request->user(), $v);
        return response()->json(['message' => 'Referral policy version saved. Existing rewards keep the amount they were created with.', 'policy_id' => $p->id], 201);
    }
}
