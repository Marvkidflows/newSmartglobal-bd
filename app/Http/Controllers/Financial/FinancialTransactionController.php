<?php
// LOCATION: app/Http/Controllers/Financial/FinancialTransactionController.php
//
// Read-only unified Transactions view for the Financial Team.
//
// The project's `transactions` table/model is not used anywhere (nothing
// writes to it), so a second "transaction system" is NOT created on top of
// it. The platform's real transaction records are:
//
//   deposits            (DEP-)  pending | hold | approved | rejected
//   withdrawals         (WDR-)  pending | hold | approved | rejected
//   investment_accounts (INV-)  active | completed | cancelled
//   balance_adjustments (ADJ-)  wallet add/deduct/reset ledger (final)
//
// This controller reads all four into one list and NORMALISES their
// statuses for display/filtering only. Raw statuses are never altered and
// are returned alongside (`raw_status`):
//
//   deposit/withdrawal  pending→pending, hold→pending (on_hold=true),
//                       approved→approved, rejected→rejected
//   investment          active→approved, completed→completed, cancelled→cancelled
//   adjustment          →completed
//
// Approve / reject / hold are NOT reimplemented here: they keep going
// through the existing, row-locked /financial/deposits|withdrawals/{id}/*
// endpoints (AdminDepositController / AdminWithdrawalController), which now
// also write the audit record. `actions` in the detail payload tells the UI
// which of them currently apply.

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\FinancialAuditLog;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class FinancialTransactionController extends Controller
{
    private const TYPES    = ['deposit', 'withdrawal', 'investment', 'adjustment'];
    private const STATUSES = ['pending', 'approved', 'rejected', 'completed', 'cancelled'];
    private const CAP      = 500; // per-type rows considered per request

    // GET /financial/transactions
    public function index(Request $request)
    {
        Gate::authorize('financial.review-transactions');

        $v = $request->validate([
            'search'    => ['nullable', 'string', 'max:100'],
            'type'      => ['nullable', 'in:' . implode(',', self::TYPES)],
            'status'    => ['nullable', 'in:' . implode(',', self::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $rows = collect();
        foreach (self::TYPES as $type) {
            if (!empty($v['type']) && $v['type'] !== $type) continue;
            $rows = $rows->merge($this->rowsFor($type, $v));
        }

        if (!empty($v['status'])) {
            $rows = $rows->where('status', $v['status']);
        }

        $rows    = $rows->sortByDesc('sort_at')->values();
        $perPage = (int) ($v['per_page'] ?? 25);
        $page    = (int) ($v['page'] ?? 1);
        $total   = $rows->count();

        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->map(function ($r) {
            unset($r['sort_at']);
            return $r;
        })->values();

        return response()->json([
            'summary'  => $this->summary(),
            'recent'   => $this->recent(),
            'data'     => $slice,
            'meta'     => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => (int) max(1, ceil($total / $perPage))],
        ]);
    }

    // GET /financial/transactions/{type}/{id}
    public function show(Request $request, string $type, int $id)
    {
        Gate::authorize('financial.review-transactions');
        abort_unless(in_array($type, self::TYPES, true), 404);

        $model = $this->find($type, $id);
        abort_unless($model, 404);

        $investor = User::where('id', $model->user_id)->where('role', 'investor')->first();
        abort_unless($investor, 404); // never expose non-investor records here

        $row = $this->formatRow($type, $model, $investor);
        unset($row['sort_at']);

        $audit = FinancialAuditLog::where('entity_type', $this->entityType($type))
            ->where('entity_id', $model->id)->latest()->get()->map(fn ($a) => $a->present());

        // Full audit trail entries recorded against the investor's own
        // records — context for a reviewer, not just this row.
        $investorHistory = FinancialAuditLog::where('investor_id', $investor->id)->latest()->take(10)->get()
            ->map(fn ($a) => $a->present());

        $detail = $this->extraDetail($type, $model);

        return response()->json([
            'transaction' => array_merge($row, $detail),
            'investor'    => [
                'id'             => $investor->id,
                'name'           => $investor->name ?? $investor->full_name,
                'email'          => $investor->email,
                'status'         => $investor->status ?? 'active',
                'balance'        => (float) ($investor->balance ?? 0),
                'total_invested' => (float) InvestmentAccount::where('user_id', $investor->id)->sum('amount'),
            ],
            'actions'          => $this->actions($type, $model),
            'audit'            => $audit,
            'investor_history' => $investorHistory,
        ]);
    }

    // GET /financial/audit-logs   (read-only; no write endpoint exists)
    public function auditLogs(Request $request)
    {
        Gate::authorize('financial.view-audit');

        $v = $request->validate([
            'investor_id' => ['nullable', 'integer'],
            'entity_type' => ['nullable', 'string', 'max:60'],
            'action'      => ['nullable', 'string', 'max:60'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date', 'after_or_equal:date_from'],
            'search'      => ['nullable', 'string', 'max:100'],
            'per_page'    => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $q = FinancialAuditLog::with('investor:id,name')->latest();
        if (!empty($v['investor_id'])) $q->where('investor_id', $v['investor_id']);
        if (!empty($v['entity_type'])) $q->where('entity_type', $v['entity_type']);
        if (!empty($v['action']))      $q->where('action', 'like', $v['action'] . '%');
        if (!empty($v['date_from']))   $q->whereDate('created_at', '>=', $v['date_from']);
        if (!empty($v['date_to']))     $q->whereDate('created_at', '<=', $v['date_to']);
        if (!empty($v['search'])) {
            $s = $v['search'];
            $q->where(fn ($qq) => $qq->where('reference', 'like', "%{$s}%")
                ->orWhere('actor_name', 'like', "%{$s}%")
                ->orWhere('reason', 'like', "%{$s}%"));
        }

        $page = $q->paginate((int) ($v['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn ($a) => $a->present())->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    // ── internals ──────────────────────────────────────────────────────

    protected function rowsFor(string $type, array $v): Collection
    {
        $q = match ($type) {
            'deposit'    => Deposit::query(),
            'withdrawal' => Withdrawal::query(),
            'investment' => InvestmentAccount::query(),
            'adjustment' => BalanceAdjustment::query(),
        };

        // Investor-only: the join to users enforces role=investor.
        $q->whereHas('user', fn ($u) => $u->where('role', 'investor'));

        if (!empty($v['date_from'])) $q->whereDate('created_at', '>=', $v['date_from']);
        if (!empty($v['date_to']))   $q->whereDate('created_at', '<=', $v['date_to']);

        if (!empty($v['search'])) {
            $s = trim($v['search']);
            $idFromRef = $this->idFromReference($s, $type);
            $q->where(function ($qq) use ($s, $type, $idFromRef) {
                $qq->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
                if ($idFromRef !== null) $qq->orWhere('id', $idFromRef);
                if (ctype_digit($s))     $qq->orWhere('id', (int) $s);
                if ($type === 'deposit') $qq->orWhere('transaction_reference', 'like', "%{$s}%");
            });
        }

        // Pre-filter by normalised status at the DB level to keep the cap meaningful.
        if (!empty($v['status'])) {
            $this->applyStatus($q, $type, $v['status']);
        }

        return $q->with('user:id,name,email')->latest()->take(self::CAP)->get()
            ->map(fn ($m) => $this->formatRow($type, $m, $m->user));
    }

    protected function applyStatus($q, string $type, string $status): void
    {
        if (in_array($type, ['deposit', 'withdrawal'], true)) {
            match ($status) {
                'pending'  => $q->whereIn('status', ['pending', 'hold']),
                'approved' => $q->where('status', 'approved'),
                'rejected' => $q->where('status', 'rejected'),
                default    => $q->whereRaw('1 = 0'),
            };
        } elseif ($type === 'investment') {
            match ($status) {
                'approved'  => $q->where('status', 'active'),
                'completed' => $q->where('status', 'completed'),
                'cancelled' => $q->where('status', 'cancelled'),
                default     => $q->whereRaw('1 = 0'),
            };
        } else { // adjustment
            if ($status !== 'completed') $q->whereRaw('1 = 0');
        }
    }

    protected function normaliseStatus(string $type, string $raw): string
    {
        if (in_array($type, ['deposit', 'withdrawal'], true)) {
            return $raw === 'hold' ? 'pending' : $raw;
        }
        if ($type === 'investment') {
            return match ($raw) { 'active' => 'approved', 'completed' => 'completed', 'cancelled' => 'cancelled', default => $raw };
        }
        return 'completed';
    }

    protected function formatRow(string $type, $m, ?User $investor): array
    {
        $prefix = ['deposit' => 'DEP', 'withdrawal' => 'WDR', 'investment' => 'INV', 'adjustment' => 'ADJ'][$type];
        $raw    = $type === 'adjustment' ? 'recorded' : (string) $m->status;

        return [
            'key'         => "{$type}:{$m->id}",
            'type'        => $type,
            'id'          => $m->id,
            'reference'   => $prefix . '-' . str_pad((string) $m->id, 6, '0', STR_PAD_LEFT),
            'investor'    => ['id' => $investor?->id, 'name' => $investor?->name ?? $investor?->full_name ?? 'Unknown', 'email' => $investor?->email ?? ''],
            'amount'      => (float) $m->amount,
            'method'      => $type === 'deposit' ? $m->payment_method : ($type === 'withdrawal' ? $m->method : ($type === 'adjustment' ? $m->type : null)),
            'status'      => $this->normaliseStatus($type, $raw),
            'raw_status'  => $raw,
            'on_hold'     => $raw === 'hold',
            'created_at'  => $m->created_at->toIso8601String(),
            'processed_at'=> in_array($type, ['deposit', 'withdrawal'], true) ? optional($m->processed_at)->toIso8601String() : null,
            'sort_at'     => $m->created_at->timestamp,
        ];
    }

    protected function extraDetail(string $type, $m): array
    {
        return match ($type) {
            'deposit' => [
                'payment_reference' => $m->transaction_reference,
                'admin_notes'       => $m->admin_notes,
                'plan'              => $m->investmentPlan?->name,
                'held_at'           => optional($m->held_at)->toIso8601String(),
                'processed_by'      => $m->processedBy?->name,
            ],
            'withdrawal' => [
                'account_details' => $m->account_details_array,
                'admin_notes'     => $m->admin_notes,
                'held_at'         => optional($m->held_at)->toIso8601String(),
                'processed_by'    => $m->processedBy?->name,
            ],
            'investment' => [
                'plan'              => $m->investmentPlan?->name,
                'profit_percentage' => (float) $m->profit_percentage,
                'expected_profit'   => (float) $m->expected_profit,
                'total_return'      => (float) $m->total_return,
                'start_date'        => optional($m->start_date)->toDateString(),
                'end_date'          => optional($m->end_date)->toDateString(),
                'is_paid'           => (bool) $m->is_paid,
                'correctable'       => $m->status === 'active' && !$m->is_paid,
            ],
            'adjustment' => [
                'balance_before' => (float) $m->balance_before,
                'balance_after'  => (float) $m->balance_after,
                'reason'         => $m->reason,
                'performed_by'   => $m->admin?->name ?? 'System',
            ],
        };
    }

    // Which existing workflow actions apply right now (UI hint only —
    // the action endpoints re-check state and role server-side).
    protected function actions(string $type, $m): array
    {
        if (!in_array($type, ['deposit', 'withdrawal'], true)) {
            return ['approve' => false, 'reject' => false, 'hold' => false];
        }
        $open = in_array($m->status, ['pending', 'hold'], true);
        return ['approve' => $open, 'reject' => $open, 'hold' => $m->status === 'pending'];
    }

    protected function find(string $type, int $id)
    {
        return match ($type) {
            'deposit'    => Deposit::with(['investmentPlan', 'processedBy'])->find($id),
            'withdrawal' => Withdrawal::with('processedBy')->find($id),
            'investment' => InvestmentAccount::with('investmentPlan')->find($id),
            'adjustment' => BalanceAdjustment::with('admin:id,name')->find($id),
        };
    }

    protected function entityType(string $type): string
    {
        return ['deposit' => 'deposit', 'withdrawal' => 'withdrawal', 'investment' => 'investment_account', 'adjustment' => 'balance_adjustment'][$type];
    }

    protected function idFromReference(string $s, string $type): ?int
    {
        $prefix = ['deposit' => 'DEP', 'withdrawal' => 'WDR', 'investment' => 'INV', 'adjustment' => 'ADJ'][$type];
        if (preg_match('/^' . $prefix . '-?0*(\d+)$/i', $s, $m)) return (int) $m[1];
        return null;
    }

    protected function summary(): array
    {
        $dep = fn ($s) => Deposit::whereIn('status', (array) $s)->whereHas('user', fn ($u) => $u->where('role', 'investor'))->count();
        $wdr = fn ($s) => Withdrawal::whereIn('status', (array) $s)->whereHas('user', fn ($u) => $u->where('role', 'investor'))->count();
        $inv = fn ($s) => InvestmentAccount::whereIn('status', (array) $s)->whereHas('user', fn ($u) => $u->where('role', 'investor'))->count();
        $investorScope = fn ($u) => $u->where('role', 'investor');

        $adjustments = BalanceAdjustment::whereHas('user', $investorScope)->count();

        $pending  = $dep(['pending', 'hold']) + $wdr(['pending', 'hold']);
        $approved = $dep('approved') + $wdr('approved') + $inv('active');
        $rejected = $dep('rejected') + $wdr('rejected');
        $completed = $inv('completed') + $adjustments;
        $cancelled = $inv('cancelled');

        return [
            'total'     => $pending + $approved + $rejected + $completed + $cancelled,
            'pending'   => $pending,
            'approved'  => $approved,
            'rejected'  => $rejected,
            'completed' => $completed,
            'cancelled' => $cancelled,
        ];
    }

    protected function recent(): Collection
    {
        return FinancialAuditLog::latest()->take(8)->get()->map(fn ($a) => $a->present());
    }
}
