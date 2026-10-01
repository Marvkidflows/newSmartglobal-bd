<?php
// LOCATION: app/Http/Controllers/Financial/FinancialInvestorController.php
//
// Financial Team investor records. Replaces the financial routes' previous
// use of AdminUserController (show/adjustBalance). Inspection found that
// controller route-binds ANY user id (an admin's or another staff member's
// account included) and accepts freeze/unfreeze/reset — powers the
// financial role is not meant to have. The admin routes still use it
// unchanged; the /financial/* routes now land here instead, with:
//
//   * every {investor} checked to be role = investor (404 otherwise)
//   * wallet actions limited to add / deduct
//   * investment corrections limited to amount + ROI %, active accounts only
//   * row locks + DB transactions
//   * an append-only audit record written in the same transaction
//
// Response shapes of index/show/adjustBalance are backward compatible with
// the existing FinancialInvestors / FinancialInvestorDetail pages.

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\FinancialAuditLog;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\FinancialAlertNotification;
use App\Services\FinancialAuditService;
use App\Services\FinancialNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FinancialInvestorController extends Controller
{
    public function __construct(
        protected FinancialAuditService $audit,
        protected FinancialNotificationService $financialNotifier,
    ) {}

    // GET /financial/investors
    public function index(Request $request)
    {
        Gate::authorize('financial.view-records');

        $query = User::where('role', 'investor');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->withSum('investmentAccounts as total_invested_sum', 'amount')
            ->latest()->get()->map(fn (User $u) => [
                'id'             => $u->id,
                'name'           => $u->name ?? $u->full_name,
                'email'          => $u->email,
                'phone'          => $u->phone,
                'country'        => $u->country,
                'balance'        => (float) ($u->balance ?? 0),
                'total_invested' => (float) ($u->total_invested_sum ?? 0),
                'status'         => $u->status ?? 'active',
                'role'           => $u->role,
                'last_login_at'  => $u->last_login_at,
                'created_at'     => $u->created_at->toDateString(),
            ]);

        return response()->json(['users' => $users]);
    }

    // GET /financial/investors/{user}
    // Investor Financial Overview. Only financial fields are returned — no
    // KYC documents, ID numbers, password/PIN state, addresses or security
    // settings.
    public function show(Request $request, User $user)
    {
        Gate::authorize('financial.view-records');
        $this->ensureInvestor($user);

        $investments = InvestmentAccount::where('user_id', $user->id)->with('investmentPlan')->latest()->get()
            ->map(fn ($i) => $this->formatInvestment($i));

        $deposits = Deposit::where('user_id', $user->id)->latest()->get()->map(fn ($d) => [
            'id'         => $d->id,
            'reference'  => $d->transaction_reference,
            'amount'     => (float) $d->amount,
            'method'     => $d->payment_method ?? 'N/A',
            'status'     => $d->status,
            'created_at' => $d->created_at->toDateString(),
        ]);

        $withdrawals = Withdrawal::where('user_id', $user->id)->latest()->get()->map(fn ($w) => [
            'id'         => $w->id,
            'amount'     => (float) $w->amount,
            'method'     => $w->method ?? 'N/A',
            'status'     => $w->status,
            'created_at' => $w->created_at->toDateString(),
        ]);

        $balanceHistory = BalanceAdjustment::where('user_id', $user->id)->with('admin:id,name')
            ->latest()->take(50)->get()->map(fn ($b) => [
                'id'             => $b->id,
                'type'           => $b->type,
                'amount'         => (float) $b->amount,
                'balance_before' => (float) $b->balance_before,
                'balance_after'  => (float) $b->balance_after,
                'reason'         => $b->reason,
                'admin_name'     => $b->admin->name ?? 'System',
                'created_at'     => $b->created_at->toDateTimeString(),
            ]);

        // Previous corrections/actions on this investor's records.
        $auditHistory = FinancialAuditLog::where('investor_id', $user->id)->latest()->take(50)->get()
            ->map(fn ($a) => $a->present());

        $active = $investments->where('status', 'active');

        return response()->json([
            'user' => [
                'id'               => $user->id,
                'investor_id'      => 'INVSTR-' . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
                'name'             => $user->name ?? $user->full_name,
                'email'            => $user->email,
                'phone'            => $user->phone,
                'country'          => $user->country,
                'balance'          => (float) ($user->balance ?? 0),
                'total_invested'   => (float) $investments->sum('amount'),
                'active_invested'  => (float) $active->sum('amount'),
                'total_profit'     => (float) $investments->where('status', 'completed')->sum('expected_profit'),
                'total_deposited'  => (float) $deposits->where('status', 'approved')->sum('amount'),
                'total_withdrawn'  => (float) $withdrawals->where('status', 'approved')->sum('amount'),
                'status'           => $user->status ?? 'active',
                'role'             => $user->role,
                'created_at'       => $user->created_at->toDateString(),
                'last_login_at'    => $user->last_login_at,
            ],
            // Mailing / Postal Information — reuses the same fields as the
            // investor's own profile and the KYC review screen
            // (residential_address = Address Line 1). Read-only here;
            // corrections go through correctMailingAddress() below.
            'mailing_address' => [
                'address_line_1' => $user->residential_address ?? null,
                'address_line_2' => $user->address_line_2 ?? null,
                'city'           => $user->city ?? null,
                'state'          => $user->state ?? null,
                'postal_code'    => $user->postal_code ?? null,
                'country'        => $user->country ?? null,
            ],
            'investments'     => $investments,
            'deposits'        => $deposits,
            'withdrawals'     => $withdrawals,
            'balance_history' => $balanceHistory,
            'audit_history'   => $auditHistory,
        ]);
    }

    // POST /financial/investors/{user}/balance   (add | deduct only)
    public function adjustBalance(Request $request, User $user)
    {
        Gate::authorize('financial.adjust-balance');
        $this->ensureInvestor($user);

        $validated = $request->validate([
            'type'   => ['required', 'in:add,deduct'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $actor  = $request->user();
        $amount = round((float) $validated['amount'], 2);

        $result = DB::transaction(function () use ($user, $validated, $actor, $amount) {
            $locked = User::where('id', $user->id)->where('role', 'investor')->lockForUpdate()->first();
            if (!$locked) {
                return ['error' => 'Investor not found.', 'code' => 404];
            }

            $before = (float) ($locked->balance ?? 0);

            if ($validated['type'] === 'deduct' && $amount > $before) {
                return ['error' => 'Cannot deduct more than the current balance.', 'code' => 422];
            }

            $after = $validated['type'] === 'add' ? $before + $amount : $before - $amount;
            $locked->balance = $after;
            $locked->save();

            $adjustment = BalanceAdjustment::create([
                'user_id'        => $locked->id,
                'admin_id'       => $actor->id,
                'type'           => $validated['type'],
                'amount'         => $amount,
                'balance_before' => $before,
                'balance_after'  => $after,
                'reason'         => $validated['reason'],
            ]);

            $log = $this->audit->record(
                $actor, "wallet.{$validated['type']}", 'balance_adjustment', $adjustment->id, $locked->id,
                ['balance' => $before], ['balance' => $after, 'amount' => $amount], $validated['reason']
            );

            return ['ok' => true, 'user' => $locked, 'adjustment' => $adjustment, 'log' => $log];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['code']);
        }

        $this->financialNotifier->balanceAdjusted(
            $result['user']->id,
            $result['user']->name ?? $result['user']->full_name ?? 'Investor',
            $validated['type'], $amount, $actor->name
        );

        return response()->json([
            'message' => 'Balance adjustment applied successfully.',
            'user'    => ['id' => $result['user']->id, 'balance' => (float) $result['user']->balance, 'status' => $result['user']->status],
            'adjustment' => [
                'id'             => $result['adjustment']->id,
                'type'           => $result['adjustment']->type,
                'amount'         => (float) $result['adjustment']->amount,
                'balance_before' => (float) $result['adjustment']->balance_before,
                'balance_after'  => (float) $result['adjustment']->balance_after,
                'reason'         => $result['adjustment']->reason,
                'admin_name'     => $actor->name,
                'created_at'     => $result['adjustment']->created_at->toDateTimeString(),
                'audit_reference'=> $result['log']->reference,
            ],
        ]);
    }

    // POST /financial/investments/{investment}/correct
    //
    // Controlled correction of an investment record. Editable: `amount` and
    // `profit_percentage`. expected_profit / total_return are always
    // RECOMPUTED server-side from those two — never accepted from the
    // client. This corrects the RECORD only; it does not move wallet money
    // (use the audited balance add/deduct for that), and countdown/end-date
    // controls stay admin-only exactly as before.
    public function correctInvestment(Request $request, InvestmentAccount $investment)
    {
        Gate::authorize('financial.correct-records');

        $validated = $request->validate([
            'amount'            => ['nullable', 'numeric', 'min:0.01', 'max:1000000000'],
            'profit_percentage' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'reason'            => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        if (($validated['amount'] ?? null) === null && ($validated['profit_percentage'] ?? null) === null) {
            return response()->json(['message' => 'Provide a corrected amount and/or profit percentage.'], 422);
        }

        $actor = $request->user();

        $result = DB::transaction(function () use ($investment, $validated, $actor) {
            $locked = InvestmentAccount::where('id', $investment->id)->lockForUpdate()->first();
            $owner  = $locked ? User::find($locked->user_id) : null;

            // Never trust the id alone: the owner must be a real investor account.
            if (!$locked || !$owner || $owner->role !== 'investor') {
                return ['error' => 'Investment record not found.', 'code' => 404];
            }
            if ($locked->status !== 'active' || $locked->is_paid) {
                return ['error' => 'Only active, unpaid investment records can be corrected.', 'code' => 422];
            }

            $prev = $this->snapshot($locked);

            $newAmount = isset($validated['amount']) ? round((float) $validated['amount'], 2) : (float) $locked->amount;
            $newPct    = isset($validated['profit_percentage']) ? round((float) $validated['profit_percentage'], 2) : (float) $locked->profit_percentage;

            if ($newAmount === (float) $locked->amount && $newPct === (float) $locked->profit_percentage) {
                return ['error' => 'The corrected values are identical to the current record.', 'code' => 422];
            }

            $expected = round($newAmount * ($newPct / 100), 2);
            $locked->update([
                'amount'            => $newAmount,
                'profit_percentage' => $newPct,
                'expected_profit'   => $expected,
                'total_return'      => round($newAmount + $expected, 2),
            ]);

            $new = $this->snapshot($locked->fresh());

            $log = $this->audit->record(
                $actor, 'investment.record_corrected', 'investment_account', $locked->id, $owner->id,
                $prev, $new, $validated['reason']
            );

            return ['ok' => true, 'investment' => $locked->fresh('investmentPlan'), 'owner' => $owner, 'prev' => $prev, 'new' => $new, 'log' => $log];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['code']);
        }

        // Tell the investor their record changed (transparency).
        $result['owner']->notify(new FinancialAlertNotification(
            'Investment record updated',
            'The Financial Team corrected one of your investment records after a review. '
            . 'Amount: $' . number_format($result['prev']['amount'], 2) . ' → $' . number_format($result['new']['amount'], 2) . '.',
            'investment_correction',
            ['investment_id' => $result['investment']->id]
        ));

        return response()->json([
            'message'         => 'Investment record corrected.',
            'investment'      => $this->formatInvestment($result['investment']),
            'previous_value'  => $result['prev'],
            'new_value'       => $result['new'],
            'audit_reference' => $result['log']->reference,
        ]);
    }

    // POST /financial/investors/{user}/mailing-address
    //
    // Authorized, audited correction of an investor's mailing/postal
    // information. Never silent: requires a reason, validates every field,
    // and records the full before/after address in the same audit system
    // used for investment and wallet corrections. This is PII — access is
    // gated the same as other record corrections, never inferred from the
    // frontend, and the response never includes anything beyond the
    // mailing fields themselves.
    public function correctMailingAddress(Request $request, User $user)
    {
        Gate::authorize('financial.correct-records');
        $this->ensureInvestor($user);

        $validated = $request->validate([
            'address_line_1' => ['required', 'string', 'max:500'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city'           => ['required', 'string', 'max:100'],
            'state'          => ['nullable', 'string', 'max:100'],
            'postal_code'    => ['nullable', 'string', 'max:20'],
            'reason'         => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $actor = $request->user();

        $result = DB::transaction(function () use ($user, $validated, $actor) {
            $locked = User::where('id', $user->id)->where('role', 'investor')->lockForUpdate()->first();
            if (!$locked) {
                return ['error' => 'Investor not found.', 'code' => 404];
            }

            $before = [
                'address_line_1' => $locked->residential_address,
                'address_line_2' => $locked->address_line_2,
                'city'           => $locked->city,
                'state'          => $locked->state,
                'postal_code'    => $locked->postal_code,
            ];
            $after = [
                'address_line_1' => $validated['address_line_1'],
                'address_line_2' => $validated['address_line_2'] ?? null,
                'city'           => $validated['city'],
                'state'          => $validated['state'] ?? null,
                'postal_code'    => $validated['postal_code'] ?? null,
            ];

            if (
                $before['address_line_1'] === $after['address_line_1']
                && ($before['address_line_2'] ?? null) === ($after['address_line_2'] ?? null)
                && $before['city'] === $after['city']
                && ($before['state'] ?? null) === ($after['state'] ?? null)
                && ($before['postal_code'] ?? null) === ($after['postal_code'] ?? null)
            ) {
                return ['error' => 'The corrected values are identical to the current record.', 'code' => 422];
            }

            $locked->update([
                'residential_address' => $after['address_line_1'],
                'address_line_2'      => $after['address_line_2'] ?? null,
                'city'                => $after['city'],
                'state'               => $after['state'] ?? null,
                'postal_code'         => $after['postal_code'] ?? null,
            ]);

            $log = $this->audit->record(
                $actor, 'investor.mailing_address_corrected', 'user', $locked->id, $locked->id,
                $before, $after, $validated['reason']
            );

            return ['ok' => true, 'mailing_address' => $after + ['country' => $locked->country], 'log' => $log];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['code']);
        }

        return response()->json([
            'message'         => 'Mailing information corrected.',
            'mailing_address' => $result['mailing_address'],
            'audit_reference' => $result['log']->reference,
        ]);
    }

    // ── helpers ────────────────────────────────────────────────────────

    protected function ensureInvestor(User $user): void
    {
        abort_unless($user->role === 'investor', 404);
    }

    protected function snapshot(InvestmentAccount $i): array
    {
        return [
            'amount'            => (float) $i->amount,
            'profit_percentage' => (float) $i->profit_percentage,
            'expected_profit'   => (float) $i->expected_profit,
            'total_return'      => (float) $i->total_return,
        ];
    }

    protected function formatInvestment(InvestmentAccount $i): array
    {
        return [
            'id'             => $i->id,
            'reference'      => 'INV-' . str_pad((string) $i->id, 6, '0', STR_PAD_LEFT),
            'plan_name'      => $i->investmentPlan->name ?? 'N/A',
            'amount'         => (float) $i->amount,
            'profit_percent' => (float) ($i->profit_percentage ?? 0),
            'expected_profit'=> (float) ($i->expected_profit ?? 0),
            'total_return'   => (float) ($i->total_return ?? 0),
            'status'         => $i->status,
            'is_paid'        => (bool) $i->is_paid,
            'start_date'     => optional($i->start_date)->toDateString(),
            'end_date'       => optional($i->end_date)->toDateString(),
            'created_at'     => $i->created_at->toDateString(),
        ];
    }
}
