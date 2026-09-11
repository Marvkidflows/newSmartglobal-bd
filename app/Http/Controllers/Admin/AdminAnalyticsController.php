<?php
// LOCATION: app/Http/Controllers/Admin/AdminAnalyticsController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\Deposit;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

class AdminAnalyticsController extends Controller
{
    // GET /admin/analytics
    public function index(Request $request)
    {
        $year = $request->get('year', now()->year);

        // ── MONTHLY DATA (12 months) ─────────────────────────────────────────
        $monthlyData = collect(range(1, 12))->map(function ($month) use ($year) {
            return [
                'month'       => date('M', mktime(0, 0, 0, $month, 1)),
                'revenue'     => (float) Deposit::where('status', 'approved')
                                    ->whereMonth('created_at', $month)
                                    ->whereYear('created_at', $year)
                                    ->sum('amount'),
                'deposits'    => (float) Deposit::whereMonth('created_at', $month)
                                    ->whereYear('created_at', $year)
                                    ->sum('amount'),
                'withdrawals' => (float) Withdrawal::whereMonth('created_at', $month)
                                    ->whereYear('created_at', $year)
                                    ->sum('amount'),
                'investors'   => User::where('role', 'investor')
                                    ->whereMonth('created_at', $month)
                                    ->whereYear('created_at', $year)
                                    ->count(),
            ];
        });

        // ── PLAN DISTRIBUTION ────────────────────────────────────────────────
        $planDistribution = InvestmentPlan::withCount([
            'investmentAccounts as active_count' => fn($q) => $q->where('status', 'active'),
        ])->get()->map(fn($p) => [
            'name'  => $p->name,
            'value' => $p->active_count,
        ]);

        // ── SUMMARY TOTALS ───────────────────────────────────────────────────
        $summary = [
            'total_revenue'      => (float) Deposit::where('status', 'approved')->sum('amount'),
            'total_deposits'     => (float) Deposit::sum('amount'),
            'total_withdrawals'  => (float) Withdrawal::where('status', 'approved')->sum('amount'),
            'total_investors'    => User::where('role', 'investor')->count(),
            'active_investments' => InvestmentAccount::where('status', 'active')->count(),
            'total_profit_paid'  => (float) InvestmentAccount::where('status', 'completed')->sum('expected_profit'),
        ];

        // ── TOP INVESTORS ────────────────────────────────────────────────────
        $topInvestors = User::where('role', 'investor')
            ->withSum('investmentAccounts as total_invested', 'amount')
            ->orderByDesc('total_invested')
            ->take(5)
            ->get()
            ->map(fn($u) => [
                'name'           => $u->name ?? $u->full_name,
                'email'          => $u->email,
                'total_invested' => (float) ($u->total_invested ?? 0),
            ]);

        $data = [
            'monthly_data'      => $monthlyData->values(),
            'plan_distribution' => $planDistribution->values(),
            'summary'           => $summary,
            'top_investors'     => $topInvestors,
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }
        return view('admin.analytics', $data);
    }
}
