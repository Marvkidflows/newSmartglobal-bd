<?php
// LOCATION: app/Http/Controllers/Financial/FinancialControlsController.php
//
// Staff API for the Financial Team's controls (27 Oct 2026): position-level principal handling, withdrawal classification, investment flags,
// the currency reconciliation queue and the approved-rate register. Reachable by the admin AND Financial roles (the `financial` route group),
// each action behind its own gate and audited. Every action is a no-op/403 unless the matching feature flag is on; nothing here decides a
// rank — the rank engine only READS the records these actions create.

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\FxApprovedSource;
use App\Models\FxReconciliationItem;
use App\Models\InvestmentAccount;
use App\Models\PrincipalMovement;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalComponent;
use App\Models\WalletLedgerEntry;
use App\Services\Ledger\PrincipalService;
use App\Services\Ledger\WithdrawalClassificationService;
use App\Services\Rank\FxControlService;
use App\Services\Rank\InvestmentFlagService;
use App\Services\Rank\RankBenefitService;
use App\Services\Rank\RankInvestmentLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FinancialControlsController extends Controller
{
    public function __construct(
        protected PrincipalService $principal, protected WithdrawalClassificationService $classifier, protected InvestmentFlagService $flags,
        protected FxControlService $fx, protected RankBenefitService $audit, protected RankInvestmentLedger $ledger,
    ) {}

    // GET /financial/ranks/investors/{investor}/positions
    public function positions(User $investor)
    {
        Gate::authorize('financial.manage-positions');
        $facts = $this->ledger->forUsers([$investor->id], true)[$investor->id];
        $bd = collect($facts['breakdown'])->keyBy('investment_id');
        $movements = PrincipalMovement::where('user_id', $investor->id)->orderByDesc('id')->limit(100)->get()->groupBy('investment_id');

        $positions = InvestmentAccount::where('user_id', $investor->id)->orderBy('id')->get()->map(function (InvestmentAccount $i) use ($bd, $movements) {
            $flag = $this->flags->current($i->id);
            return [
                'id' => $i->id, 'reference' => 'INV-' . str_pad((string) $i->id, 6, '0', STR_PAD_LEFT), 'cycle_reference' => 'CYC-' . str_pad((string) $i->id, 6, '0', STR_PAD_LEFT),
                'amount' => (float) $i->amount, 'status' => $i->status, 'derived_status' => $bd[$i->id]['status'] ?? null,
                'remaining_principal' => $this->principal->remaining($i), 'principal_usd_counted' => $bd[$i->id]['principal_usd_counted'] ?? 0,
                'exclusions' => $bd[$i->id]['exclusions'] ?? [], 'flag' => $flag ? ['type' => $flag->flag_type, 'reason' => $flag->reason, 'by' => $flag->actor_name, 'at' => $flag->created_at?->toIso8601String()] : null,
                'movements' => ($movements[$i->id] ?? collect())->map(fn ($m) => ['id' => $m->id, 'kind' => $m->kind, 'amount' => (float) $m->amount, 'reference' => $m->reference, 'by' => $m->recorded_by_name, 'at' => $m->created_at?->toIso8601String()])->values()->all(),
            ];
        })->values()->all();

        $withdrawals = Withdrawal::where('user_id', $investor->id)->whereIn('status', ['pending', 'hold'])->orderBy('id')->get()->map(fn ($w) => [
            'id' => $w->id, 'amount' => (float) $w->amount, 'status' => $w->status,
            'classified' => \App\Services\Ledger\LedgerReaders::has('withdrawal_components') && WithdrawalComponent::where('withdrawal_id', $w->id)->exists(),
        ])->all();

        return response()->json([
            'investor' => ['id' => $investor->id, 'name' => $investor->name], 'enabled' => $this->principal->enabled(),
            'summary' => ['current_eligible_principal' => $facts['current_principal'], 'historical_total_usd' => $facts['total_usd'], 'completed_cycles' => $facts['cycles'], 'calculated_at' => $facts['calculated_at']],
            'positions' => $positions, 'pending_withdrawals' => $withdrawals,
            'ledger' => \App\Services\Ledger\LedgerReaders::has('wallet_ledger_entries')
                ? WalletLedgerEntry::where('user_id', $investor->id)->selectRaw('classification, direction, SUM(amount) as total, COUNT(*) as entries')->groupBy('classification', 'direction')->get()->all() : [],
        ]);
    }

    // POST /financial/positions/{investment}/flag  { flag_type, reason }   |   /unflag { reason }
    public function flag(Request $request, InvestmentAccount $investment)
    {
        Gate::authorize('financial.manage-positions');
        $v = $request->validate(['flag_type' => 'required|in:' . implode(',', InvestmentFlagService::TYPES), 'reason' => 'required|string|min:5|max:1000']);
        $row = $this->flags->set($investment, $v['flag_type'], $v['reason'], $request->user());
        return response()->json(['flag' => $row->flag_type, 'investment_id' => $investment->id], 201);
    }

    public function unflag(Request $request, InvestmentAccount $investment)
    {
        Gate::authorize('financial.manage-positions');
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $this->flags->clear($investment, $v['reason'], $request->user());
        return response()->json(['flag' => null, 'investment_id' => $investment->id]);
    }

    // POST /financial/positions/{investment}/release  { note }
    public function release(Request $request, InvestmentAccount $investment)
    {
        Gate::authorize('financial.manage-positions');
        $v = $request->validate(['note' => 'required|string|min:5|max:1000']);
        return response()->json($this->movementJson($this->principal->release($investment, $v['note'], $request->user())), 201);
    }

    // POST /financial/positions/{investment}/return-to-wallet  { amount, idempotency_key, reference, evidence, withdrawal_id? }
    public function returnToWallet(Request $request, InvestmentAccount $investment)
    {
        Gate::authorize('financial.manage-positions');
        $v = $this->redemptionRules($request);
        return response()->json($this->movementJson($this->principal->returnToWallet($investment, (float) $v['amount'], $v['idempotency_key'], $v['reference'], $v['evidence'], $request->user(), $v['withdrawal_id'] ?? null)), 201);
    }

    // POST /financial/positions/{investment}/external-payout  { amount, idempotency_key, reference, evidence, withdrawal_id? }
    public function externalPayout(Request $request, InvestmentAccount $investment)
    {
        Gate::authorize('financial.manage-positions');
        $v = $this->redemptionRules($request);
        return response()->json($this->movementJson($this->principal->externalPayout($investment, (float) $v['amount'], $v['idempotency_key'], $v['reference'], $v['evidence'], $request->user(), $v['withdrawal_id'] ?? null)), 201);
    }

    // POST /financial/positions/{investment}/correction  { signed_amount, idempotency_key, approval_reference, evidence }
    public function correct(Request $request, InvestmentAccount $investment)
    {
        Gate::authorize('financial.manage-positions');
        $v = $request->validate(['signed_amount' => 'required|numeric', 'idempotency_key' => 'required|string|min:8|max:64', 'approval_reference' => 'required|string|min:5|max:160', 'evidence' => 'required|string|min:10|max:2000']);
        return response()->json($this->movementJson($this->principal->correct($investment, (float) $v['signed_amount'], $v['idempotency_key'], $v['approval_reference'], $v['evidence'], $request->user())), 201);
    }

    // POST /financial/withdrawals/{withdrawal}/classify  { components: [{component, amount, investment_id?, verification_note?}] }
    public function classifyWithdrawal(Request $request, Withdrawal $withdrawal)
    {
        Gate::authorize('financial.manage-positions');
        $v = $request->validate([
            'components' => 'required|array|min:1|max:20', 'components.*.component' => 'required|in:principal,profit', 'components.*.amount' => 'required|numeric|gt:0',
            'components.*.investment_id' => 'nullable|integer', 'components.*.verification_note' => 'nullable|string|max:2000',
        ]);
        $rows = $this->classifier->classify($withdrawal, $v['components'], $request->user());
        return response()->json(['withdrawal_id' => $withdrawal->id, 'components' => collect($rows)->map(fn ($c) => ['component' => $c->component, 'investment_id' => $c->investment_id, 'amount' => (float) $c->amount])->all()], 201);
    }

    // ── reconciliation queue ────────────────────────────────────────────────────────────────────────────

    // GET /financial/reconciliation?status=open|resolved|unresolved
    public function reconciliation(Request $request)
    {
        Gate::authorize('financial.reconcile-currency');
        $v = $request->validate(['status' => 'nullable|in:open,resolved,unresolved']);
        $page = FxReconciliationItem::when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->paginate(25);
        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => FxReconciliationItem::select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')]);
    }

    // POST /financial/reconciliation/scan — read-only on financial records; only queue rows are written
    public function scan()
    {
        Gate::authorize('financial.reconcile-currency');
        return response()->json(['opened' => $this->fx->scan()]);
    }

    // POST /financial/reconciliation/{item}/resolve  { outcome, evidence, correction? }
    public function resolve(Request $request, FxReconciliationItem $item)
    {
        Gate::authorize('financial.reconcile-currency');
        $v = $request->validate([
            'outcome' => 'required|in:adjusted,verified_as_recorded,unresolved', 'evidence' => 'required|string|min:10|max:3000',
            'correction' => 'nullable|array', 'correction.currency' => 'required_if:outcome,adjusted|string|size:3', 'correction.rate' => 'nullable|numeric|gt:0',
            'correction.rate_source' => 'nullable|string|max:160', 'correction.rate_date' => 'nullable|date',
        ]);
        $it = $this->fx->resolve($item, $v['outcome'], $v['evidence'], $request->user(), $v['correction'] ?? null);
        return response()->json(['id' => $it->id, 'status' => $it->status, 'snapshot_id' => $it->snapshot_id]);
    }

    // ── approved exchange-rate register (no provider is approved until a documented entry exists) ──────

    public function sources()
    {
        Gate::authorize('financial.manage-fx-register');
        return response()->json(['data' => FxApprovedSource::orderBy('currency')->orderByDesc('effective_from')->get(),
            'note' => 'No exchange-rate provider is treated as approved until the Financial Team has documented its approval here. A source without a maximum rate age cannot be assessed for staleness.']);
    }

    public function storeSource(Request $request)
    {
        Gate::authorize('financial.manage-fx-register');
        $v = $request->validate([
            'currency' => 'required|string|size:3', 'reference_currency' => 'nullable|string|size:3', 'primary_source' => 'required|string|min:3|max:160', 'fallback_source' => 'nullable|string|max:160',
            'rate_type' => 'required|string|min:3|max:40', 'max_age_hours' => 'nullable|integer|min:1|max:8760', 'approval_reference' => 'required|string|min:5|max:160',
            'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ]);
        if (in_array(strtoupper($v['currency']), FxControlService::CRYPTO_CODES, true)) {
            throw ValidationException::withMessages(['currency' => ['Cryptocurrency valuation is not approved for rank eligibility; it cannot be added to the register.']]);
        }
        $row = FxApprovedSource::create($v + ['approved_by' => $request->user()->id, 'approved_by_name' => $request->user()->name ?? 'Staff', 'created_at' => now()]);
        $this->audit->audit('reconciliation', $row->id, 'fx_source_registered', $request->user(), null, $row->only(['currency', 'primary_source', 'rate_type', 'max_age_hours', 'effective_from']), $v['approval_reference']);
        return response()->json($row, 201);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────

    protected function redemptionRules(Request $request): array
    {
        return $request->validate([
            'amount' => 'required|numeric|gt:0', 'idempotency_key' => 'required|string|min:8|max:64', 'reference' => 'required|string|min:3|max:120',
            'evidence' => 'required|string|min:10|max:2000', 'withdrawal_id' => 'nullable|integer',
        ]);
    }

    protected function movementJson(PrincipalMovement $m): array
    {
        return ['id' => $m->id, 'investment_id' => $m->investment_id, 'kind' => $m->kind, 'amount' => (float) $m->amount, 'reference' => $m->reference, 'entry_group' => $m->entry_group];
    }
}
