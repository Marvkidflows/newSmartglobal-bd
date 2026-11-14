<?php
// LOCATION: app/Http/Controllers/Investor/InvestorBenefitController.php
// Investor-facing, scoped to the AUTHENTICATED investor only (no investor id is ever read from the request).
// Benefits are read-only; the investor can change only their own consent and their own loan application.

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\InvestmentPlan;
use App\Models\LoanApplication;
use App\Models\RankIncentiveTerm;
use App\Services\Rank\RankReportService;
use App\Services\Rank\LoanService;
use App\Services\Rank\RankBenefitService;
use Illuminate\Http\Request;

class InvestorBenefitController extends Controller
{
    public function __construct(protected RankBenefitService $benefits, protected LoanService $loans, protected RankReportService $reports) {}

    // GET /investor-investment/rank/benefits — all six ranks' benefits, status labelled; which apply to this investor; history; consent
    public function show(Request $request)
    {
        return response()->json($this->benefits->investorView($request->user()));
    }

    // PUT /investor-investment/rank/consent  { recognition_communications: bool }
    public function consent(Request $request)
    {
        $v = $request->validate(['recognition_communications' => 'required|boolean']);
        $state = $this->benefits->setConsent($request->user(), (bool) $v['recognition_communications']);

        return response()->json(['consent' => ['recognition_communications' => $state]]);
    }

    // GET /investor-investment/rank/report — own cycle history / consolidated records, only if management enabled the benefit
    public function report(Request $request)
    {
        return response()->json($this->reports->for($request->user()));
    }

    // GET /investor-investment/rank/plan-terms/{plan} — the terms to read BEFORE committing to a plan
    public function planTerms(Request $request, InvestmentPlan $plan)
    {
        abort_unless($plan->status === 'active', 404);

        return response()->json($this->termsFor($plan, $request->user()) + $this->termsNotices());
    }

    // GET /investor-investment/rank/plan-terms — the terms of every ACTIVE plan (shown on the rank page, framework §8)
    public function allPlanTerms(Request $request)
    {
        $user = $request->user();
        $plans = InvestmentPlan::where('status', 'active')->orderBy('min_amount')->get();   // same filter and order as the existing plans endpoint

        return response()->json(['plans' => $plans->map(fn (InvestmentPlan $p) => $this->termsFor($p, $user))->values()->all()] + $this->termsNotices());
    }

    protected function termsFor(InvestmentPlan $plan, $user): array
    {
        // Rank incentives apply only when: the master switch is on, the term is ACTIVE (written approval recorded), it is for this
        // investor's current rank, for this plan (or all plans) and inside its dates. By default none exist.
        $incentives = [];
        if (config('ranks.incentives_activation_enabled', false) && $user->investor_rank_id && ($user->rank_status ?? 'active') === 'active') {
            $today = now()->toDateString();
            $incentives = RankIncentiveTerm::where('status', RankIncentiveTerm::ACTIVE)->where('investor_rank_id', $user->investor_rank_id)
                ->where(fn ($q) => $q->whereNull('investment_plan_id')->orWhere('investment_plan_id', $plan->id))
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
                ->get()->map(fn (RankIncentiveTerm $t) => [
                    'label' => $t->label, 'kind' => $t->kind, 'value' => $t->value, 'conditions' => $t->conditions,
                    'effective_from' => $t->effective_from?->toDateString(), 'effective_to' => $t->effective_to?->toDateString(),
                ])->all();
        }

        return [
            'plan' => [
                'id' => $plan->id, 'name' => $plan->name, 'min_amount' => (float) $plan->min_amount, 'max_amount' => $plan->max_amount ? (float) $plan->max_amount : null,
                'contractual_return_percent' => (float) ($plan->profit_percentage ?: $plan->profit_percent ?: 0),
                'duration_days' => $plan->duration_days ?? (($plan->duration_months ?? 1) * 30),
            ],
            'rank_incentives' => $incentives,
        ];
    }

    protected function termsNotices(): array
    {
        return [
            'notice' => 'The contractual return above is set by the investment plan. Your rank does not change it. Any rank incentive is listed separately, only if SSI has approved and activated it, and is not a guarantee of profit. Returns are not guaranteed and capital is at risk.',
            'terms_recorded_at_investment' => 'When you invest, the plan return, expected profit, start and end dates are recorded on your investment and kept as recorded.',
        ];
    }

    // GET /investor-investment/loans
    public function loans(Request $request)
    {
        return response()->json($this->loans->investorState($request->user()));
    }

    // POST /investor-investment/loans  { requested_amount, purpose? }
    public function apply(Request $request)
    {
        $v = $request->validate(['requested_amount' => 'required|numeric|gt:0|max:999999999', 'purpose' => 'nullable|string|max:1000']);
        $this->loans->apply($request->user(), (float) $v['requested_amount'], $v['purpose'] ?? null);

        return response()->json($this->loans->investorState($request->user()), 201);
    }

    // POST /investor-investment/loans/{application}/withdraw
    public function withdraw(Request $request, LoanApplication $application)
    {
        abort_unless((int) $application->user_id === (int) $request->user()->id, 404);
        $this->loans->withdraw($request->user(), $application);

        return response()->json($this->loans->investorState($request->user()));
    }
}
