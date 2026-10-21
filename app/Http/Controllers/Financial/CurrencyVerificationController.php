<?php
// LOCATION: app/Http/Controllers/Financial/CurrencyVerificationController.php
//
// Where a person states what currency a stored investment/deposit amount is in (and, if not USD, the exchange
// rate used). This is how records become usable for USD rank thresholds, and how unknown / stale / duplicate-
// looking records are surfaced for review. It never edits the investment or deposit itself — it only appends a
// valuation row (and a Financial audit entry). Reachable by the Financial team and Admin (existing route group).

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Services\Rank\CurrencyCatalog;
use App\Services\Rank\RankInvestmentLedger;
use App\Services\Rank\UsdValuationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CurrencyVerificationController extends Controller
{
    public function __construct(
        protected UsdValuationService $valuations,
        protected RankInvestmentLedger $ledger,
        protected CurrencyCatalog $currencies,
    ) {}

    // GET /financial/currency/summary
    public function summary()
    {
        Gate::authorize('financial.view-records');

        return response()->json($this->valuations->summary() + ['ranking_currency' => config('ranks.ranking_currency')]);
    }

    // GET /financial/currency/supported — currency codes that can be stated (ISO-4217 + configured extras)
    public function supported()
    {
        Gate::authorize('financial.view-records');

        return response()->json([
            'ranking_currency' => config('ranks.ranking_currency'),
            'currencies' => collect($this->currencies->all())->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values(),
        ]);
    }

    // GET /financial/currency/rates?currency=NGN — rates already recorded for this currency (to reuse, never auto-applied)
    public function rates(Request $request)
    {
        Gate::authorize('financial.view-records');

        $v = $request->validate(['currency' => 'required|string|max:8']);
        $code = strtoupper(trim($v['currency']));
        abort_unless($this->currencies->isSupported($code), 422, 'Unsupported currency code.');

        return response()->json(['currency' => $code, 'recent' => $this->valuations->recentRates($code)]);
    }

    // GET /financial/currency/records?type=investment|deposit&issue=needs_review|unverified|stale|duplicates|all&search=
    public function index(Request $request)
    {
        Gate::authorize('financial.view-records');

        $v = $request->validate([
            'type'     => 'nullable|in:investment,deposit',
            'issue'    => 'nullable|in:needs_review,unverified,stale,duplicates,all',
            'search'   => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);
        $type  = $v['type'] ?? 'investment';
        $issue = $v['issue'] ?? 'needs_review';
        $table = $type === 'investment' ? 'investment_accounts' : 'deposits';
        $dupIds = $type === 'investment' ? $this->valuations->duplicateSuspectIds() : [];

        $q = DB::table("$table as t")
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('usd_valuations as v', function ($j) use ($type) {
                $j->on('v.valuable_id', '=', 't.id')->where('v.valuable_type', '=', $type)
                  ->whereIn('v.id', function ($q) use ($type) {
                      $q->from('usd_valuations')->selectRaw('MAX(id)')->where('valuable_type', $type)->groupBy('valuable_id');
                  });
            })
            ->when($type === 'investment', fn ($q) => $q->where('t.status', '!=', 'cancelled'), fn ($q) => $q->where('t.status', '=', 'approved'))
            ->select('t.id', 't.user_id', 't.amount', 't.status', 't.created_at',
                     'u.name', 'u.full_name', 'u.email',
                     'v.id as valuation_id', 'v.currency', 'v.original_amount', 'v.fx_rate', 'v.usd_amount');

        $unverified = fn ($w) => $w->whereNull('v.id');
        $stale      = fn ($w) => $w->whereNotNull('v.id')->whereColumn('v.original_amount', '!=', 't.amount');

        match ($issue) {
            'unverified'   => $q->where($unverified),
            'stale'        => $q->where($stale),
            'duplicates'   => $q->whereIn('t.id', $dupIds ?: [0]),
            'needs_review' => $q->where(function ($w) use ($unverified, $stale, $dupIds) {
                $w->where($unverified)->orWhere($stale)->orWhereIn('t.id', $dupIds ?: [0]);
            }),
            default        => null,
        };

        if (!empty($v['search'])) {
            $s  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']);
            $id = User::idFromAccountId($v['search']);
            $q->where(function ($w) use ($s, $id) {
                $w->where('u.name', 'like', "%$s%")->orWhere('u.full_name', 'like', "%$s%")->orWhere('u.email', 'like', "%$s%");
                if ($id) $w->orWhere('t.user_id', $id);
            });
        }

        $page = $q->orderBy('t.id')->paginate($v['per_page'] ?? 25);

        $rows = collect($page->items())->map(function ($r) use ($dupIds) {
            $status = !$r->valuation_id ? 'unverified'
                : (abs((float) $r->original_amount - (float) $r->amount) > 0.004 ? 'stale' : 'verified');
            $acct = config('ranks.account_prefix', 'INVSTR-') . str_pad((string) $r->user_id, (int) config('ranks.account_pad', 5), '0', STR_PAD_LEFT);

            return [
                'id' => (int) $r->id, 'investor_id' => (int) $r->user_id, 'account_id' => $acct,
                'investor_name' => $r->name ?? $r->full_name, 'email' => $r->email,
                'amount' => (float) $r->amount, 'record_status' => $r->status, 'created_at' => $r->created_at,
                'valuation_status' => $status, 'duplicate_suspect' => in_array((int) $r->id, $dupIds, true),
                'currency' => $r->currency, 'fx_rate' => $r->fx_rate !== null ? (float) $r->fx_rate : null,
                'verified_original_amount' => $r->original_amount !== null ? (float) $r->original_amount : null,
                'usd_amount' => $r->usd_amount !== null ? (float) $r->usd_amount : null,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'ranking_currency' => config('ranks.ranking_currency'),
        ]);
    }

    // POST /financial/currency/records/{type}/{id}/verify
    public function verify(Request $request, string $type, int $id)
    {
        Gate::authorize('financial.verify-currency');

        $v = $request->validate([
            'currency'         => 'required|string|max:8',
            'rate'             => 'nullable|numeric',
            'rate_source'      => 'nullable|string|max:160',
            'rate_date'        => 'nullable|date',
            'note'             => 'required|string|min:5|max:1000',
            'confirm_distinct' => 'nullable|boolean',
            'acknowledge_rate_warnings' => 'nullable|boolean',
        ]);

        // The USD figure is computed by the service from the record's own amount — it is never accepted from the client.
        $valuation = $this->valuations->record(
            $type, $id, $v['currency'], $v['rate'] ?? null, $v['rate_source'] ?? null, $v['rate_date'] ?? null,
            $v['note'], $request->user(), (bool) ($v['confirm_distinct'] ?? false), (bool) ($v['acknowledge_rate_warnings'] ?? false)
        );

        return response()->json([
            'message'   => 'Verified: ' . number_format((float) $valuation->original_amount, 2) . " {$valuation->currency} = "
                . number_format((float) $valuation->usd_amount, 2) . ' USD.',
            'valuation' => [
                'id' => $valuation->id, 'currency' => $valuation->currency, 'original_amount' => (float) $valuation->original_amount,
                'fx_rate' => (float) $valuation->fx_rate, 'usd_amount' => (float) $valuation->usd_amount,
            ],
        ], 201);
    }

    // POST /financial/currency/bulk-usd   { type, ids[], note } — an explicit, attributable attestation "these are USD"
    public function bulkUsd(Request $request)
    {
        Gate::authorize('financial.verify-currency');

        $v = $request->validate([
            'type'  => 'required|in:investment,deposit',
            'ids'   => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
            'note'  => 'required|string|min:10|max:1000',
        ]);

        $model   = $v['type'] === 'investment' ? InvestmentAccount::class : Deposit::class;
        $records = $model::query()->whereIn('id', $v['ids'])->get()->keyBy('id');
        $latest  = $this->ledger->latestValuations($v['type'], $records->keys()->all());

        $verified = 0; $skipped = [];
        foreach (array_unique($v['ids']) as $id) {
            $rec = $records->get($id);
            if (!$rec) { $skipped[] = ['id' => $id, 'reason' => 'Record not found.']; continue; }

            $cur = $latest[$id] ?? null;
            if ($cur && abs($cur['original_amount'] - round((float) $rec->amount, 2)) <= 0.004) {
                $skipped[] = ['id' => $id, 'reason' => 'Already verified.']; continue;
            }

            try {
                $this->valuations->record($v['type'], (int) $id, 'USD', null, null, null, $v['note'], $request->user(), false);
                $verified++;
            } catch (ValidationException $e) {
                $skipped[] = ['id' => $id, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['message' => "Verified {$verified} record(s) as USD; skipped " . count($skipped) . '.', 'verified' => $verified, 'skipped' => $skipped]);
    }

    // POST /financial/currency/bulk-verify   { type, ids[], currency, rate?, rate_source?, rate_date?, note, acknowledge_rate_warnings? }
    // One stated currency + ONE recorded rate applied to many records. Each record still gets its own valuation row,
    // its own USD figure (computed from its own amount) and its own Financial audit entry. Records that do not
    // qualify (cancelled, unapproved, duplicate-looking, already verified) are skipped and listed — never forced.
    public function bulkVerify(Request $request)
    {
        Gate::authorize('financial.verify-currency');

        $v = $request->validate([
            'type'        => 'required|in:investment,deposit',
            'ids'         => 'required|array|min:1|max:200',
            'ids.*'       => 'integer',
            'currency'    => 'required|string|max:8',
            'rate'        => 'nullable|numeric',
            'rate_source' => 'nullable|string|max:160',
            'rate_date'   => 'nullable|date',
            'note'        => 'required|string|min:10|max:1000',
            'acknowledge_rate_warnings' => 'nullable|boolean',
        ]);

        $model   = $v['type'] === 'investment' ? InvestmentAccount::class : Deposit::class;
        $records = $model::query()->whereIn('id', $v['ids'])->get()->keyBy('id');
        $latest  = $this->ledger->latestValuations($v['type'], $records->keys()->all());

        $verified = 0; $usdTotal = 0.0; $skipped = [];
        foreach (array_unique($v['ids']) as $id) {
            $rec = $records->get($id);
            if (!$rec) { $skipped[] = ['id' => $id, 'reason' => 'Record not found.']; continue; }

            $cur = $latest[$id] ?? null;
            if ($cur && abs($cur['original_amount'] - round((float) $rec->amount, 2)) <= 0.004) {
                $skipped[] = ['id' => $id, 'reason' => 'Already verified (verify individually to change it).']; continue;
            }

            try {
                $val = $this->valuations->record($v['type'], (int) $id, $v['currency'], $v['rate'] ?? null, $v['rate_source'] ?? null,
                    $v['rate_date'] ?? null, $v['note'], $request->user(), false, (bool) ($v['acknowledge_rate_warnings'] ?? false));
                $verified++; $usdTotal += (float) $val->usd_amount;
            } catch (ValidationException $e) {
                $errs = $e->errors();
                // A problem with the batch itself (currency / rate / source / date / note) applies to every record: stop, change nothing more.
                if ($verified === 0 && collect(['currency', 'rate', 'rate_source', 'rate_date', 'note', 'rate_warnings'])->contains(fn ($k) => isset($errs[$k]))) throw $e;
                $skipped[] = ['id' => $id, 'reason' => collect($errs)->flatten()->first()];
            }
        }

        $cur = strtoupper(trim($v['currency']));
        return response()->json([
            'message'   => "Verified {$verified} record(s) as {$cur} (= " . number_format($usdTotal, 2) . ' USD in total); skipped ' . count($skipped) . '.',
            'verified'  => $verified, 'usd_total' => round($usdTotal, 2), 'skipped' => $skipped,
        ]);
    }
}
