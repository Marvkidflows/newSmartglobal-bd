<?php
// LOCATION: app/Http/Controllers/Investor/InvestorInvestmentController.php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

        if (\App\Support\Decimal::lt(\App\Support\Decimal::fromInput($validated['amount']), \App\Support\Decimal::fromInput($plan->min_amount))) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "Minimum investment for this plan is $" . number_format($plan->min_amount, 2),
                ], 422);
            }
            return back()->withErrors(['amount' => 'Amount is below the minimum for this plan.']);
        }

        if ($plan->max_amount && \App\Support\Decimal::gt(\App\Support\Decimal::fromInput($validated['amount']), \App\Support\Decimal::fromInput($plan->max_amount))) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "Maximum investment for this plan is $" . number_format($plan->max_amount, 2),
                ], 422);
            }
            return back()->withErrors(['amount' => 'Amount exceeds the maximum for this plan.']);
        }

        if (\App\Support\Decimal::lt(\App\Support\Decimal::fromInput($user->balance), \App\Support\Decimal::fromInput($validated['amount']))) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Insufficient balance. Please make a deposit first.',
                ], 422);
            }
            return back()->withErrors(['amount' => 'Insufficient balance.']);
        }

        // F-01 (audit 2 Nov 2026): the pre-checks above are an early, UNLOCKED convenience reject only. The authoritative balance check, the debit and
        // the investment insert happen below inside ONE transaction under a row lock on the investor's user row.
        $idemKey = $this->idempotencyKey($request);
        $idemCacheKey = $idemKey ? "invest-idem:{$user->id}:{$idemKey}" : null;

        try {
            if (app(\App\Services\Ledger\WalletLedger::class)->enabled()) {
                // Financial Team §1A/§1B: validate the wallet, record the selection and plan terms, debit the wallet and create the position as ONE
                // controlled transaction under row locks, with linked ledger entries. Nothing is written if any step fails.
                [$investment, , $profitPercent] = $this->withIdempotency($idemCacheKey, $user->id, fn () =>
                    app(\App\Services\Ledger\InvestmentAllocationService::class)->allocate($user, $plan, $validated['amount']));
                $expectedProfit = (float) $investment->expected_profit;
            } else {
                [$investment, $profitPercent, $expectedProfit] = $this->withIdempotency($idemCacheKey, $user->id, fn () =>
                    $this->investFromWalletAtomically($user, $plan, $validated['amount']));
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Insufficient funds / plan limits discovered UNDER THE LOCK: same 422 contract as the early pre-checks above.
            if ($request->expectsJson()) {
                return response()->json(['message' => collect($e->errors())->flatten()->first() ?? 'Investment could not be created.', 'errors' => $e->errors()], 422);
            }
            throw $e;
        }

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

    /**
     * Legacy (ledger-disabled) wallet → investment path, made atomic (audit F-01).
     * One transaction: lock the investor row, re-read the latest balance, validate under the lock, create the position and debit the wallet.
     * Any failure rolls back BOTH, so an investment can never exist without its debit nor a debit without its investment. All arithmetic is
     * exact-decimal (App\Support\Decimal); no float is compared, multiplied or written. Business rules are unchanged: same plan snapshot
     * (profit_percentage, expected_profit, total_return, start/end dates, remaining_days) as before.
     *
     * @return array{0: InvestmentAccount, 1: string|int|float, 2: float} [position, profit percent, expected profit]
     */
    protected function investFromWalletAtomically(User $user, InvestmentPlan $plan, mixed $rawAmount): array
    {
        return DB::transaction(function () use ($user, $plan, $rawAmount) {
            $amount = Decimal::money($rawAmount);

            $u = User::whereKey($user->id)->lockForUpdate()->first();
            if (!$u) {
                throw ValidationException::withMessages(['amount' => ['Investor account not found.']]);
            }
            // Re-read the plan inside the transaction so limits and terms are those in force at commit time.
            $plan = InvestmentPlan::findOrFail($plan->id);

            if (Decimal::lt($amount, Decimal::money($plan->min_amount))) {
                throw ValidationException::withMessages(['amount' => ['Amount is below the minimum for this plan.']]);
            }
            if ($plan->max_amount && Decimal::gt($amount, Decimal::money($plan->max_amount))) {
                throw ValidationException::withMessages(['amount' => ['Amount exceeds the maximum for this plan.']]);
            }
            if (Decimal::lt(Decimal::money($u->balance), $amount)) {
                throw ValidationException::withMessages(['amount' => ['Insufficient balance. Please make a deposit first.']]);
            }

            $durationDays   = $plan->duration_days ?? (($plan->duration_months ?? 1) * 30);
            $profitPercent  = $plan->profit_percentage ?? $plan->profit_percent ?? 0;
            $expectedProfit = Decimal::percentOf($amount, Decimal::fromInput($profitPercent));
            $totalReturn    = Decimal::add($amount, $expectedProfit);

            $investment = InvestmentAccount::create([
                'user_id'            => $u->id,
                'investment_plan_id' => $plan->id,
                'amount'             => $amount,
                'profit_percentage'  => $profitPercent,
                'expected_profit'    => $expectedProfit,
                'total_return'       => $totalReturn,
                'status'             => 'active',
                'start_date'         => now(),
                'end_date'           => now()->addDays($durationDays),
                'remaining_days'     => $durationDays,
            ]);

            // Conditional decrement: even if something bypassed the lock, the row can never go below zero. A 0-row result aborts and rolls
            // back the investment insert above.
            $debited = User::whereKey($u->id)->where('balance', '>=', $amount)->decrement('balance', $amount);
            if ($debited !== 1) {
                throw ValidationException::withMessages(['amount' => ['Insufficient balance. Please make a deposit first.']]);
            }

            // Traceability while the ledger is OFF: append-only audit row in the same transaction (rolls back with the debit if it fails).
            // Not a BalanceAdjustment, so the wallet history (which lists the investment itself) is not double counted.
            $balanceBefore = Decimal::money($u->balance);
            \App\Models\FinancialAuditLog::create([
                'reference' => 'FAL-' . strtoupper(\Illuminate\Support\Str::random(10)), 'actor_id' => $u->id, 'actor_name' => $u->name ?? $u->full_name ?? 'Investor',
                'actor_role' => $u->role ?? 'investor', 'department' => 'investor', 'action' => 'investment.funded_from_wallet',
                'entity_type' => 'investment_account', 'entity_id' => $investment->id, 'investor_id' => $u->id,
                'previous_value' => ['balance' => $balanceBefore], 'new_value' => ['balance' => Decimal::sub($balanceBefore, $amount), 'amount' => $amount, 'plan_id' => $plan->id],
                'reason' => 'Investor invested from available wallet balance.',
            ]);

            return [$investment, $profitPercent, (float) $expectedProfit];
        });
    }

    /** Optional client-supplied Idempotency-Key (header or body), restricted to a safe character set. Null when absent or malformed. */
    protected function idempotencyKey(Request $request): ?string
    {
        $k = (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', ''));
        return preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $k) ? $k : null;
    }

    /**
     * Prevent duplicate submissions (double-click / retry). With an Idempotency-Key, a repeat of the same key by the same investor is rejected
     * with 409 instead of creating a second position. The marker is written before the work and removed again if the work fails, so a failed
     * attempt can be retried with the same key. Requires a shared cache store (database/redis) in production; the per-user row lock is the
     * authoritative protection and is independent of this marker.
     */
    protected function withIdempotency(?string $cacheKey, int $userId, \Closure $work): array
    {
        if ($cacheKey === null) return $work();

        if (!Cache::add($cacheKey, 'in-flight-or-done', now()->addHours(24))) {
            abort(409, 'This investment request was already submitted.');
        }
        try {
            return $work();
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            throw $e;
        }
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