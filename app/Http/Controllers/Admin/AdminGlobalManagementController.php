<?php
// LOCATION: app/Http/Controllers/Admin/AdminGlobalManagementController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\InvestmentAccount;
use App\Models\BalanceAdjustment;
use App\Models\ProfitAdjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminGlobalManagementController extends Controller
{
    // ── PROFIT RATE ADJUSTMENT ────────────────────────────────────────────
    // Adjusts the profit_percentage (and recalculates expected_profit / total_return)
    // on currently active investments, scoped globally, by sector, by plan, or by specific users.

    // POST /admin/global/profit-adjustments
    public function adjustProfit(Request $request)
    {
        $validated = $request->validate([
            'scope'               => ['required', 'in:global,sector,plan,users'],
            'sector_id'           => ['required_if:scope,sector', 'nullable', 'exists:sectors,id'],
            'investment_plan_id'  => ['required_if:scope,plan', 'nullable', 'exists:investment_plans,id'],
            'user_ids'            => ['required_if:scope,users', 'nullable', 'array'],
            'user_ids.*'          => ['exists:users,id'],
            'percentage_change'   => ['required', 'numeric', 'between:-100,100'],
            'reason'              => ['required', 'string', 'max:1000'],
        ]);

        $query = InvestmentAccount::where('status', 'active');

        switch ($validated['scope']) {
            case 'sector':
                $sectorId = $validated['sector_id'];
                $query->whereHas('investmentPlan.sectorCategory', function ($q) use ($sectorId) {
                    $q->where('sector_id', $sectorId);
                });
                break;

            case 'plan':
                $query->where('investment_plan_id', $validated['investment_plan_id']);
                break;

            case 'users':
                $query->whereIn('user_id', $validated['user_ids']);
                break;

            case 'global':
            default:
                // no extra filter — every active investment
                break;
        }

        $pctChange     = (float) $validated['percentage_change'];
        $affectedCount = 0;

        DB::transaction(function () use ($query, $pctChange, &$affectedCount) {
            $investments = $query->get();

            foreach ($investments as $inv) {
                $newPct      = max(0, (float) $inv->profit_percentage + $pctChange);
                $newExpected = (float) $inv->amount * ($newPct / 100);
                $newTotal    = (float) $inv->amount + $newExpected;

                $inv->update([
                    'profit_percentage' => $newPct,
                    'expected_profit'   => $newExpected,
                    'total_return'      => $newTotal,
                ]);

                $affectedCount++;
            }
        });

        $adjustment = ProfitAdjustment::create([
            'admin_id'            => $request->user()->id,
            'scope'                => $validated['scope'],
            'sector_id'            => $validated['sector_id'] ?? null,
            'investment_plan_id'   => $validated['investment_plan_id'] ?? null,
            'percentage_change'    => $pctChange,
            'affected_count'       => $affectedCount,
            'reason'               => $validated['reason'],
        ]);

        return response()->json([
            'message' => "Profit rate adjusted on {$affectedCount} active investment(s).",
            'adjustment' => $adjustment,
        ]);
    }

    // GET /admin/global/profit-adjustments
    public function profitAdjustmentHistory(Request $request)
    {
        $history = ProfitAdjustment::with(['admin:id,name', 'sector:id,name', 'investmentPlan:id,name'])
            ->latest()
            ->take(50)
            ->get()
            ->map(fn($a) => [
                'id'                 => $a->id,
                'scope'              => $a->scope,
                'sector_name'        => $a->sector->name ?? null,
                'plan_name'          => $a->investmentPlan->name ?? null,
                'percentage_change'  => (float) $a->percentage_change,
                'affected_count'     => $a->affected_count,
                'reason'             => $a->reason,
                'admin_name'         => $a->admin->name ?? 'System',
                'created_at'         => $a->created_at->toDateTimeString(),
            ]);

        return response()->json(['history' => $history]);
    }

    // ── MASS BALANCE OPERATIONS ───────────────────────────────────────────
    // Credits or deducts many investors' balances at once, scoped to everyone,
    // a hand-picked list, or everyone with an active investment in a sector.

    // POST /admin/global/balance-bulk
    public function bulkBalance(Request $request)
    {
        $validated = $request->validate([
            'scope'       => ['required', 'in:all,selected,sector'],
            'user_ids'    => ['required_if:scope,selected', 'nullable', 'array'],
            'user_ids.*'  => ['exists:users,id'],
            'sector_id'   => ['required_if:scope,sector', 'nullable', 'exists:sectors,id'],
            'type'        => ['required', 'in:add,deduct'],
            'amount_type' => ['required', 'in:fixed,percentage'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'reason'      => ['required', 'string', 'max:500'],
        ]);

        $usersQuery = User::where('role', 'investor');

        switch ($validated['scope']) {
            case 'selected':
                $usersQuery->whereIn('id', $validated['user_ids']);
                break;

            case 'sector':
                $sectorId = $validated['sector_id'];
                $usersQuery->whereHas('investmentAccounts', function ($q) use ($sectorId) {
                    $q->where('status', 'active')
                      ->whereHas('investmentPlan.sectorCategory', function ($q2) use ($sectorId) {
                          $q2->where('sector_id', $sectorId);
                      });
                });
                break;

            case 'all':
            default:
                // every investor
                break;
        }

        $affected = 0;
        $skipped  = 0;
        $adminId  = $request->user()->id;

        DB::transaction(function () use ($usersQuery, $validated, &$affected, &$skipped, $adminId) {
            $users = $usersQuery->get();

            foreach ($users as $user) {
                $balanceBefore = (float) ($user->balance ?? 0);
                $delta = $validated['amount_type'] === 'percentage'
                    ? round($balanceBefore * ((float) $validated['amount'] / 100), 2)
                    : (float) $validated['amount'];

                if ($validated['type'] === 'deduct' && $delta > $balanceBefore) {
                    $skipped++;
                    continue;
                }

                $balanceAfter = $validated['type'] === 'add'
                    ? $balanceBefore + $delta
                    : $balanceBefore - $delta;

                $user->balance = $balanceAfter;
                $user->save();

                BalanceAdjustment::create([
                    'user_id'        => $user->id,
                    'admin_id'       => $adminId,
                    'type'           => $validated['type'],
                    'amount'         => $delta,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $balanceAfter,
                    'reason'         => $validated['reason'],
                ]);

                $affected++;
            }
        });

        return response()->json([
            'message'  => "Balance {$validated['type']} applied to {$affected} investor(s)."
                          . ($skipped > 0 ? " {$skipped} skipped (insufficient balance)." : ''),
            'affected' => $affected,
            'skipped'  => $skipped,
        ]);
    }
}
