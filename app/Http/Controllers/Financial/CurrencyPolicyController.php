<?php
// LOCATION: app/Http/Controllers/Financial/CurrencyPolicyController.php
//
// The currency-policy workflow that unlocks money-based rank criteria. Mounted twice on purpose:
//   • /financial/currency/policy…   Financial Team + Admin: read the policy, record the Financial review (step 1)
//   • /admin/ranks/currency-policy… Admin only (route group): read, review, CONFIRM or WITHDRAW (step 2)
// Every authorization is a Gate checked here, so a mis-mounted route still cannot confirm anything.
// Nothing here touches investments, deposits, balances, withdrawals or profits.

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Services\Rank\CurrencyPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CurrencyPolicyController extends Controller
{
    public function __construct(protected CurrencyPolicyService $policy) {}

    // GET …/policy — current state, the exact rules, record counts, who may do what
    public function show(Request $request)
    {
        Gate::authorize('financial.view-records');

        return response()->json($this->policy->report() + [
            'can_review'  => Gate::allows('financial.review-currency-policy'),
            'can_confirm' => Gate::allows('ranks.confirm-currency-policy'),
            'viewer_id'   => $request->user()->id,
        ]);
    }

    // GET …/policy/history — every version, who reviewed / confirmed / withdrew it
    public function history()
    {
        Gate::authorize('financial.view-audit');

        return response()->json(['data' => $this->policy->history()]);
    }

    // POST …/policy/review   { note }   — step 1 (Financial Team or Admin)
    public function review(Request $request)
    {
        Gate::authorize('financial.review-currency-policy');
        $v = $request->validate(['note' => 'required|string|min:10|max:1000']);

        $p = $this->policy->submitReview($request->user(), $v['note']);

        return response()->json([
            'message' => "Review recorded as policy v{$p->version}. An Administrator must now confirm it before money-based criteria can be activated.",
            'policy'  => $this->policy->present($p),
        ], 201);
    }

    // POST …/policy/confirm   { note, unverified_handling, acknowledged_review_count? }   — step 2 (Admin only)
    public function confirm(Request $request)
    {
        Gate::authorize('ranks.confirm-currency-policy');
        $v = $request->validate([
            'note'                      => 'required|string|min:10|max:1000',
            'unverified_handling'       => 'required|string|in:' . implode(',', CurrencyPolicyService::HANDLING),
            'acknowledged_review_count' => 'nullable|integer|min:0',
        ]);

        $p = $this->policy->confirm(
            $request->user(), $v['note'], $v['unverified_handling'],
            array_key_exists('acknowledged_review_count', $v) && $v['acknowledged_review_count'] !== null ? (int) $v['acknowledged_review_count'] : null,
        );

        return response()->json([
            'message' => "Currency policy v{$p->version} confirmed. Money-based criteria can now be activated.",
            'policy'  => $this->policy->present($p),
        ]);
    }

    // POST …/policy/revoke   { reason }   — Admin only. Money criteria lock again; no investor rank is changed.
    public function revoke(Request $request)
    {
        Gate::authorize('ranks.confirm-currency-policy');
        $v = $request->validate(['reason' => 'required|string|min:10|max:1000']);

        $p = $this->policy->revoke($request->user(), $v['reason']);

        return response()->json([
            'message' => "Currency policy v{$p->version} withdrawn. Money-based criteria cannot be activated until a new policy is confirmed; existing investor ranks are unchanged.",
            'policy'  => $this->policy->present($p),
        ]);
    }
}
