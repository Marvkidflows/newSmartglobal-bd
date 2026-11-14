<?php
// LOCATION: app/Http/Controllers/Admin/AdminDashboardController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\InvestmentPlan;
use App\Models\InvestmentAccount;
use App\Models\Deposit;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        try {
            $year     = now()->year;
            $monthSql = $this->monthExpression();

            // ── STATS ─────────────────────────────────────────────────────
            $totalInvestors     = User::where('role', 'investor')->count();
            $activeInvestors    = User::where('role', 'investor')->where('status', 'active')->count();
            $totalInvested      = (float) (InvestmentAccount::sum('amount') ?? 0);
            $totalProfit        = (float) (InvestmentAccount::where('status', 'completed')->sum('expected_profit') ?? 0);
            $totalDeposits      = (float) (Deposit::where('status', 'approved')->sum('amount') ?? 0);
            $totalWithdrawals   = (float) (Withdrawal::where('status', 'approved')->sum('amount') ?? 0);
            $pendingWithdrawals = Withdrawal::where('status', 'pending')->count();
            $pendingDeposits    = Deposit::where('status', 'pending')->count();
            $companyRevenue     = $totalDeposits - $totalWithdrawals;
            $monthlyProfit      = (float) (Deposit::where('status', 'approved')
                                    ->whereMonth('created_at', now()->month)
                                    ->whereYear('created_at', $year)
                                    ->sum('amount') ?? 0);

            // ── MONTHLY CHART DATA — one grouped query per table instead of 48 ──
            $depositsByMonth = Deposit::selectRaw($monthSql . ' as m, SUM(amount) as total')
                ->whereYear('created_at', $year)
                ->groupBy('m')
                ->pluck('total', 'm');

            $approvedDepositsByMonth = Deposit::selectRaw($monthSql . ' as m, SUM(amount) as total')
                ->where('status', 'approved')
                ->whereYear('created_at', $year)
                ->groupBy('m')
                ->pluck('total', 'm');

            $withdrawalsByMonth = Withdrawal::selectRaw($monthSql . ' as m, SUM(amount) as total')
                ->whereYear('created_at', $year)
                ->groupBy('m')
                ->pluck('total', 'm');

            $investorsByMonth = User::where('role', 'investor')
                ->selectRaw($monthSql . ' as m, COUNT(*) as total')
                ->whereYear('created_at', $year)
                ->groupBy('m')
                ->pluck('total', 'm');

            $months = collect(range(1, 12))->map(function ($month) use (
                $depositsByMonth, $approvedDepositsByMonth, $withdrawalsByMonth, $investorsByMonth
            ) {
                return [
                    'month'       => date('M', mktime(0, 0, 0, $month, 1)),
                    'revenue'     => (float) ($approvedDepositsByMonth[$month] ?? 0),
                    'deposits'    => (float) ($depositsByMonth[$month] ?? 0),
                    'withdrawals' => (float) ($withdrawalsByMonth[$month] ?? 0),
                    'investors'   => (int) ($investorsByMonth[$month] ?? 0),
                ];
            });

            // ── PENDING WITHDRAWALS ────────────────────────────────────────
            $pendingWithdrawalList = Withdrawal::where('status', 'pending')
                ->with(['user:id,name,email,investor_rank_id', 'user.investorRank'])
                ->latest()
                ->take(10)
                ->get()
                ->map(fn($w) => [
                    'id'     => $w->id,
                    'amount' => (float) $w->amount,
                    'method' => $w->method ?? $w->withdrawal_method ?? 'N/A',
                    'status' => $w->status,
                    'date'   => $w->created_at->toDateString(),
                    'user'   => [
                        'id'    => $w->user->id ?? null,
                        'name'  => $w->user->name ?? 'Unknown',
                        'email' => $w->user->email ?? '',
                        'rank'  => $w->user?->rankBadge(),
                    ],
                ]);

            // ── RECENT INVESTORS ───────────────────────────────────────────
            $recentInvestors = User::where('role', 'investor')->with('investorRank')
                ->latest()
                ->take(8)
                ->get()
                ->map(fn($u) => [
                    'id'            => $u->id,
                    'name'          => $u->name ?? $u->full_name,
                    'rank'          => $u->rankBadge(),
                    'email'         => $u->email,
                    'balance'       => (float) ($u->balance ?? 0),
                    'status'        => $u->status ?? 'active',
                    'last_login_at' => $u->last_login_at ?? null,
                    'created_at'    => $u->created_at->toDateString(),
                ]);

            // ── PLAN DISTRIBUTION ──────────────────────────────────────────
            $planDistribution = InvestmentPlan::withCount([
                    'investmentAccounts as active_count' => fn($q) => $q->where('status', 'active')
                ])
                ->get()
                ->map(fn($p) => [
                    'name'  => $p->name,
                    'value' => $p->active_count,
                ]);

            // ── RESPONSE ────────────────────────────────────────────────────
            $data = [
                'stats' => [
                    'total_investors'     => $totalInvestors,
                    'active_investors'    => $activeInvestors,
                    'total_investments'   => $totalInvested,
                    'total_deposits'      => $totalDeposits,
                    'total_withdrawals'   => $totalWithdrawals,
                    'pending_withdrawals' => $pendingWithdrawals,
                    'pending_deposits'    => $pendingDeposits,
                    'company_revenue'     => $companyRevenue,
                    'monthly_profit'      => $monthlyProfit,
                    'total_profit'        => $totalProfit,
                ],
                'chart_data'          => $months->values(),
                'plan_distribution'   => $planDistribution->values(),
                'pending_withdrawals' => $pendingWithdrawalList,
                'recent_investors'    => $recentInvestors,
            ];

            if ($request->expectsJson()) {
                return response()->json($data);
            }
            return view('admin.dashboard', $data);

        } catch (\Throwable $e) {
            // Log the real exception so it is never silently swallowed, but do
            // not hand server file paths / SQL text to the caller outside debug.
            report($e);

            return response()->json(
                config('app.debug')
                    ? ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
                    : ['error' => 'Unable to load the dashboard.'],
                500
            );
        }
    }

    /**
     * SQL expression yielding the calendar month (1-12) of created_at for the
     * active database driver. MONTH() exists in MySQL/MariaDB/SQL Server but
     * not in SQLite (used by the test suite), which made this endpoint throw
     * "no such function: MONTH".
     */
    private function monthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%m', created_at) AS INTEGER)",
            'pgsql'  => 'CAST(EXTRACT(MONTH FROM created_at) AS INTEGER)',
            default  => 'MONTH(created_at)',
        };
    }
}