<?php
// LOCATION: app/Http/Controllers/Investor/InvestorDashboardController.php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\InvestmentAccount;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class InvestorDashboardController extends Controller
{
    // GET /investor-investment/dashboard
    public function dashboard(Request $request)
    {
        $user = Auth::user();

        // ── INVESTMENTS ──────────────────────────────────────────────────────
        $investments = InvestmentAccount::where('user_id', $user->id)
            ->with('investmentPlan')
            ->get();

        $activeInvestments = $investments->where('status', 'active');
        $totalInvested     = $investments->sum('amount');
        $totalProfit       = $investments->sum('expected_profit');
        $activePlans       = $activeInvestments->count();

        // ── DEPOSITS ─────────────────────────────────────────────────────────
        $recentDeposits = Deposit::where('user_id', $user->id)
            ->latest()->take(5)->get()
            ->map(fn($d) => [
                'id'             => $d->id,
                'amount'         => (float) $d->amount,
                'method'         => $d->payment_method ?? $d->method ?? 'N/A',
                'reference'      => $d->transaction_reference ?? $d->reference ?? null,
                'status'         => $d->status,
                'created_at'     => $d->created_at->toDateString(),
            ]);

        // ── ACTIVE INVESTMENTS (live countdown) ───────────────────────────────
        $activeInvestmentList = $activeInvestments->map(fn($inv) => $this->formatInvestment($inv))->values();

        // ── MONTHLY CHART DATA ────────────────────────────────────────────────
        $chartData = collect(range(1, 12))->map(function ($month) use ($user) {
            return [
                'month'  => date('M', mktime(0, 0, 0, $month, 1)),
                'profit' => (float) Deposit::where('user_id', $user->id)
                                ->where('status', 'approved')
                                ->whereMonth('created_at', $month)
                                ->whereYear('created_at', now()->year)
                                ->sum('amount'),
            ];
        });

        // ── ANNOUNCEMENTS ─────────────────────────────────────────────────────
        $announcements = Announcement::where('is_active', true)
            ->latest()->take(3)->get()
            ->map(fn($a) => [
                'id'      => $a->id,
                'title'   => $a->title,
                'content' => $a->content ?? $a->message ?? '',
                'type'    => $a->type ?? 'info',
            ]);

        // ── BALANCE ───────────────────────────────────────────────────────────
        $totalWithdrawn   = Withdrawal::where('user_id', $user->id)
                            ->where('status', 'approved')->sum('amount');
        $withdrawable     = max(0, ($user->balance ?? 0));

        $data = [
            'stats' => [
                'balance'         => (float) ($user->balance ?? 0),
                'total_invested'  => (float) $totalInvested,
                'total_profit'    => (float) $totalProfit,
                'withdrawable'    => (float) $withdrawable,
                'active_plans'    => $activePlans,
                'total_withdrawn' => (float) $totalWithdrawn,
            ],
            'active_investments' => $activeInvestmentList,
            'recent_deposits'    => $recentDeposits,
            'chart_data'         => $chartData->values(),
            'announcements'      => $announcements,
            'user' => [
                'id'            => $user->id,
                'name'          => $user->name ?? $user->full_name,
                'email'         => $user->email,
                'role'          => $user->role,
                'balance'       => (float) ($user->balance ?? 0),
                'referral_code' => $user->referral_code ?? null,
                'status'        => $user->status ?? 'active',
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }
        return view('investor.dashboard', $data);
    }

    /**
     * Build the investment payload with LIVE countdown calculated from end_date.
     * Mirrors InvestorInvestmentController::formatInvestment() so both
     * the dashboard and the investments page always show the same numbers.
     */
    protected function formatInvestment(InvestmentAccount $inv): array
    {
        $plan      = $inv->investmentPlan;
        $totalDays = $plan?->duration_days ?? (($plan?->duration_months ?? 1) * 30);

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
            'plan'             => [
                'id'   => $plan->id ?? null,
                'name' => $plan->name ?? 'N/A',
            ],
        ];
    }
}