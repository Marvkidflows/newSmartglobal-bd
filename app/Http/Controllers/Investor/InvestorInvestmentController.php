<?php
// LOCATION: app/Http/Controllers/Investor/InvestorInvestmentController.php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class InvestorInvestmentController extends Controller
{
    // GET /investor-investment/investor/investments
    public function index(Request $request)
    {
        $user        = Auth::user();
        $investments = InvestmentAccount::where('user_id', $user->id)
            ->with('investmentPlan')
            ->latest()
            ->get()
            ->map(fn($inv) => $this->formatInvestment($inv));

        if ($request->expectsJson()) {
            return response()->json(['investments' => $investments]);
        }
        return view('investor.investments.index', compact('investments'));
    }

    // GET /investor-investment/investor/investments/plans
    public function plans(Request $request)
    {
        $plans = InvestmentPlan::where('status', 'active')
            ->with('sectorCategory.sector')
            ->orderBy('min_amount')
            ->get()
            ->map(fn($p) => [
                'id'                   => $p->id,
                'name'                 => $p->name,
                'description'          => $p->description ?? null,
                'sector_category_id'   => $p->sector_category_id,
                'sector_category_name' => $p->sectorCategory->name ?? null,
                'sector_id'            => $p->sectorCategory->sector->id ?? null,
                'sector_name'          => $p->sectorCategory->sector->name ?? null,
                'sector_icon'          => $p->sectorCategory->sector->icon ?? null,
                'min_amount'           => (float) $p->min_amount,
                'max_amount'           => $p->max_amount ? (float) $p->max_amount : null,
                'profit_percent'       => (float) ($p->profit_percentage ?? $p->profit_percent ?? 0),
                'roi_percent'          => (float) ($p->profit_percentage ?? $p->profit_percent ?? 0),
                'duration_days'        => $p->duration_days ?? (($p->duration_months ?? 1) * 30),
                'duration_months'      => $p->duration_months ?? null,
                'is_featured'          => (bool) ($p->is_featured ?? false),
                'status'               => $p->status ?? 'active',
            ]);

        if ($request->expectsJson()) {
            return response()->json(['plans' => $plans]);
        }
        return view('investor.investments.plans', compact('plans'));
    }

    // GET /investor-investment/investor/investments/create/{plan}
    public function create(Request $request, InvestmentPlan $plan)
    {
        if ($request->expectsJson()) {
            return response()->json(['plan' => $plan]);
        }
        return view('investor.investments.create', compact('plan'));
    }

    // POST /investor-investment/investor/investments
    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'plan_id' => ['required', 'exists:investment_plans,id'],
            'amount'  => ['required', 'numeric', 'min:1'],
        ]);

        $plan = InvestmentPlan::findOrFail($validated['plan_id']);

        if ($validated['amount'] < $plan->min_amount) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "Minimum investment for this plan is $" . number_format($plan->min_amount, 2),
                ], 422);
            }
            return back()->withErrors(['amount' => 'Amount is below the minimum for this plan.']);
        }

        if ($plan->max_amount && $validated['amount'] > $plan->max_amount) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "Maximum investment for this plan is $" . number_format($plan->max_amount, 2),
                ], 422);
            }
            return back()->withErrors(['amount' => 'Amount exceeds the maximum for this plan.']);
        }

        if ($user->balance < $validated['amount']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Insufficient balance. Please make a deposit first.',
                ], 422);
            }
            return back()->withErrors(['amount' => 'Insufficient balance.']);
        }

        $durationDays   = $plan->duration_days ?? (($plan->duration_months ?? 1) * 30);
        $profitPercent  = $plan->profit_percentage ?? $plan->profit_percent ?? 0;
        $expectedProfit = $validated['amount'] * ($profitPercent / 100);
        $totalReturn    = $validated['amount'] + $expectedProfit;

        $investment = InvestmentAccount::create([
            'user_id'            => $user->id,
            'investment_plan_id' => $plan->id,
            'amount'             => $validated['amount'],
            'profit_percentage'  => $profitPercent,
            'expected_profit'    => $expectedProfit,
            'total_return'       => $totalReturn,
            'status'             => 'active',
            'start_date'         => now(),
            'end_date'           => now()->addDays($durationDays),
            'remaining_days'     => $durationDays,
        ]);

        $user->decrement('balance', $validated['amount']);

        if ($request->expectsJson()) {
            return response()->json([
                'message'    => 'Investment created successfully!',
                'investment' => [
                    'id'              => $investment->id,
                    'plan_name'       => $plan->name,
                    'amount'          => (float) $investment->amount,
                    'profit_percent'  => $profitPercent,
                    'expected_profit' => (float) $expectedProfit,
                    'start_date'      => $investment->start_date->toDateString(),
                    'end_date'        => $investment->end_date->toDateString(),
                    'status'          => $investment->status,
                ],
            ], 201);
        }

        return redirect()->route('investor-investment.investments.index')
            ->with('success', 'Investment created!');
    }

    // GET /investor-investment/investor/investments/{investmentAccount}
    public function show(Request $request, InvestmentAccount $investmentAccount)
    {
        if ($investmentAccount->user_id !== Auth::id()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
            abort(403);
        }

        $investmentAccount->load('investmentPlan');

        $data = ['investment' => $this->formatInvestment($investmentAccount)];

        if ($request->expectsJson()) {
            return response()->json($data);
        }
        return view('investor.investments.show', $data);
    }

    /**
     * Build the investment payload with LIVE countdown calculated from end_date,
     * rather than relying on the static remaining_days column (which is only
     * set once at creation and otherwise only changes via admin adjustments).
     */
    protected function formatInvestment(InvestmentAccount $inv): array
    {
        $plan      = $inv->investmentPlan;
        $totalDays = $plan?->duration_days ?? (($plan?->duration_months ?? 1) * 30);

        // Live remaining days — calculated fresh from end_date every request
        $liveRemaining = $inv->end_date
            ? max(0, (int) ceil(Carbon::today()->diffInDays(Carbon::parse($inv->end_date), false)))
            : ($inv->remaining_days ?? $totalDays);

        $elapsed  = max(0, $totalDays - $liveRemaining);
        $progress = $totalDays > 0 ? round(($elapsed / $totalDays) * 100) : 0;

        $isPaid = (bool) ($inv->is_paid ?? false);
        $countdownStatus = $isPaid
            ? 'paid'
            : ($liveRemaining <= 0
                ? 'matured'
                : ($liveRemaining <= 7 ? 'maturing_soon' : 'active'));

        return [
            'id'               => $inv->id,
            'plan_name'        => $plan->name ?? 'N/A',
            'amount'           => (float) $inv->amount,
            'profit_percent'   => (float) ($plan->profit_percentage ?? $plan->profit_percent ?? 0),
            'expected_profit'  => (float) ($inv->expected_profit ?? 0),
            'total_return'     => (float) ($inv->total_return ?? 0),
            'start_date'       => optional($inv->start_date)->toDateString(),
            'end_date'         => optional($inv->end_date)->toDateString(),
            'days_remaining'   => $liveRemaining,
            'total_days'       => $totalDays,
            'days_passed'      => $elapsed,
            'progress'         => min(100, max(0, $progress)),
            'countdown_status' => $countdownStatus,
            'is_paid'          => $isPaid,
            'status'           => $inv->status,
            'created_at'       => $inv->created_at->toDateString(),
            'plan'             => [
                'id'   => $plan->id ?? null,
                'name' => $plan->name ?? 'N/A',
            ],
        ];
    }
}