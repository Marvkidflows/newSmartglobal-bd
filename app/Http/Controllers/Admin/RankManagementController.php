<?php
// LOCATION: app/Http/Controllers/Admin/RankManagementController.php
//
// Admin Rank Management: overview, investor ranks, manual assign/downgrade,
// downgrade-review queue, audit history, alert delivery status.
// Every action is authorized through a Gate on the BACKEND.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendDowngradeReviewNotificationJob;
use App\Jobs\SendRankChangeNotificationJob;
use App\Models\InvestorRank;
use App\Models\InvestorRankChange;
use App\Models\InvestorRankHold;
use App\Models\RankChangeNotification;
use App\Models\RankCriterion;
use App\Models\RankDowngradeReview;
use App\Models\RankEvaluationRun;
use App\Models\User;
use App\Services\Rank\RankChangeService;
use App\Services\Rank\RankEligibility;
use App\Services\Rank\RankInvestmentLedger;
use App\Services\Rank\UsdValuationService;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankFieldRegistry;
use App\Services\Rank\RankPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RankManagementController extends Controller
{
    public function __construct(
        protected RankChangeService $changes,
        protected RankEvaluationService $evaluation,
        protected RankFieldRegistry $registry,
        protected RankPresenter $present,
        protected RankEligibility $eligibility,
        protected UsdValuationService $valuations,
        protected RankInvestmentLedger $ledger,
    ) {}

    // GET /admin/ranks
    public function overview()
    {
        Gate::authorize('ranks.view');

        $counts = User::query()->where('role', 'investor')->selectRaw('investor_rank_id, COUNT(*) as c')
            ->groupBy('investor_rank_id')->pluck('c', 'investor_rank_id');
        $active = RankCriterion::where('is_active', true)->with(['conditions'])->get()->keyBy('investor_rank_id');

        $ranks = InvestorRank::orderBy('level')->get()->map(function ($rank) use ($counts, $active) {
            $c = $active->get($rank->id);
            return $rank->badge() + [
                'description'    => $rank->description,
                'investor_count' => (int) ($counts[$rank->id] ?? 0),
                'active_criteria' => $c ? $this->present->criterion($c) : null,
            ];
        });

        return response()->json([
            'ranks'              => $ranks,
            'unranked_count'     => User::where('role', 'investor')->whereNull('investor_rank_id')->count(),
            'open_reviews'       => RankDowngradeReview::open()->count(),
            'failed_alerts'      => RankChangeNotification::where('status', 'failed')->count(),
            'latest_run'         => ($r = RankEvaluationRun::latest('id')->first()) ? $this->present->run($r) : null,
            'override_allowed'   => (bool) config('ranks.allow_manual_override'),
            'has_active_criteria' => $active->isNotEmpty(),
            // Compliance gates applied to every rank grant, in addition to the criteria below.
            'prerequisites'      => $this->eligibility->requirements(),
            'ranking_currency'   => config('ranks.ranking_currency'),
            'currency_confirmed' => (bool) config('ranks.currency_confirmed'),
            // Records whose USD value is unknown/stale, or that look like duplicates — excluded from ranking until reviewed.
            'currency_review'    => $this->valuations->summary(),
        ]);
    }

    // GET /admin/ranks/investors?search=&rank=&needs_review=&per_page=
    public function investors(Request $request)
    {
        Gate::authorize('ranks.view');

        $v = $request->validate([
            'search' => 'nullable|string|max:100', 'rank' => 'nullable|string|max:20',
            'needs_review' => 'nullable|boolean', 'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $q = User::query()->where('role', 'investor')->with('investorRank')->orderBy('id');

        if (!empty($v['search'])) {
            $s  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']);
            $id = User::idFromAccountId($v['search']);
            $q->where(function ($w) use ($s, $id) {
                $w->where('name', 'like', "%$s%")->orWhere('full_name', 'like', "%$s%")->orWhere('email', 'like', "%$s%");
                if ($id) $w->orWhere('id', $id);
            });
        }
        if (!empty($v['rank'])) {
            if ($v['rank'] === 'none') {
                $q->whereNull('investor_rank_id');
            } else {
                $rankId = InvestorRank::where('key', $v['rank'])->orWhere('id', ctype_digit($v['rank']) ? (int) $v['rank'] : 0)->value('id');
                $q->where('investor_rank_id', $rankId ?: 0);
            }
        }
        if ($request->boolean('needs_review')) {
            $q->whereIn('id', RankDowngradeReview::open()->select('investor_id'));
        }

        $page = $q->paginate($v['per_page'] ?? 25);
        $flagged = RankDowngradeReview::open()->whereIn('investor_id', $page->getCollection()->pluck('id'))->pluck('investor_id')->flip();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($u) => $this->present->investorRow($u, $flagged->has($u->id)))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    // GET /admin/ranks/investors/{user}
    public function show(User $user)
    {
        Gate::authorize('ranks.view');
        abort_unless($user->role === 'investor', 404);
        $user->load('investorRank');

        $history = InvestorRankChange::with(['previousRank', 'newRank', 'notification'])
            ->where('investor_id', $user->id)->orderByDesc('id')->limit(200)->get()
            ->map(fn ($c) => $this->present->change($c));

        $review = RankDowngradeReview::open()->with('rank')->where('investor_id', $user->id)->latest('id')->first();

        // Where the investor stands against EVERY active rule today.
        $criteria = $this->evaluation->activeCriteria();
        $fields   = $criteria->flatMap(fn ($c) => $c->conditions->pluck('field'))->unique()->values()->all();
        $values   = $this->registry->resolveMany([$user->id], $fields)[$user->id] ?? [];
        $evaluator = app(\App\Services\Rank\RankRuleEvaluator::class);

        $standing = $criteria->sortBy(fn ($c) => $c->rank->level)->map(function ($c) use ($evaluator, $values, $user) {
            $res = $evaluator->evaluate($c->toRule(), $values);
            return [
                'rank'      => $c->rank->badge(),
                'criterion' => ['id' => $c->id, 'name' => $c->name, 'version' => $c->version],
                'satisfied' => $res['satisfied'],
                'is_current' => $user->investor_rank_id === $c->investor_rank_id,
                'conditions' => $res['conditions'],
            ];
        })->values();

        // Ranks automatic evaluation is deliberately NOT allowed to restore (see InvestorRankHold).
        $holdRow = InvestorRankHold::query()->active()->where('investor_id', $user->id)->latest('id')->first();
        $hold = $holdRow ? [
            'since'  => $holdRow->created_at?->toIso8601String(),
            'ranks'  => collect($holdRow->blocked)->map(fn ($b) => InvestorRank::find($b['rank_id'])?->badge())->filter()->values(),
        ] : null;

        // Which of THIS investor's records are being left out of ranking, and why.
        $facts = $this->ledger->forUsers([$user->id])[$user->id];
        $depositFacts = $this->ledger->depositsForUsers([$user->id])[$user->id];

        return response()->json([
            'currency' => [
                'investments_unverified' => $facts['unverified'],
                'investments_stale'      => $facts['stale'],
                'duplicate_suspects'     => $facts['duplicates'],
                'deposits_unverified'    => $depositFacts['unverified'],
                'deposits_stale'         => $depositFacts['stale'],
                'verified_total_usd'     => $facts['total_usd'],
                'completed_cycles'       => $facts['cycles'],
                'ranking_currency'       => config('ranks.ranking_currency'),
                'by_currency'            => $facts['by_currency'],
                // How this investor's APPROVED deposits were actually paid (pending / held / rejected are not shown or counted).
                'deposit_funding'        => DB::table('deposits')->where('user_id', $user->id)->where('status', 'approved')
                    ->selectRaw('paid_currency, COUNT(*) as records, SUM(paid_amount) as paid_total, SUM(amount) as approved_usd, SUM(CASE WHEN payment_verified_at IS NULL THEN 0 ELSE 1 END) as verified')
                    ->groupBy('paid_currency')->orderBy('paid_currency')->get()
                    ->map(fn ($r) => ['currency' => $r->paid_currency, 'records' => (int) $r->records, 'paid_total' => $r->paid_total !== null ? (float) $r->paid_total : null,
                                      'approved_usd' => (float) $r->approved_usd, 'verified' => (int) $r->verified])->values(),
            ],
            'prerequisites' => $this->eligibility->check($user),
            'hold'     => $hold,
            'investor' => $this->present->investorRow($user, (bool) $review) + [
                'kyc_status' => $user->kyc_status_safe,
            ],
            'standing' => $standing,
            'open_review' => $review ? $this->present->review($review) : null,
            'history'  => $history,
            'ranks'    => InvestorRank::orderBy('level')->get()->map->badge(),
            'override_allowed' => (bool) config('ranks.allow_manual_override'),
        ]);
    }

    // POST /admin/ranks/investors/{user}/assign — assign (first rank) or raise
    public function assign(Request $request, User $user)
    {
        Gate::authorize('ranks.assign');
        abort_unless($user->role === 'investor', 404);

        $v = $this->manualRules($request);
        $target = InvestorRank::findOrFail($v['rank_id']);

        $currentLevel = $user->investorRank?->level ?? 0;
        if ($target->level < $currentLevel) {
            throw ValidationException::withMessages(['rank_id' => ['Use the downgrade action to lower a rank.']]);
        }

        $change = $this->changes->applyManual($user, $target, $request->user(), $v['reason'], (bool) ($v['override'] ?? false), $v['override_justification'] ?? null);

        return $this->changeResponse($change, 'Rank updated.');
    }

    // POST /admin/ranks/investors/{user}/downgrade
    public function downgrade(Request $request, User $user)
    {
        Gate::authorize('ranks.downgrade');
        abort_unless($user->role === 'investor', 404);

        $v = $this->manualRules($request);
        $target = InvestorRank::findOrFail($v['rank_id']);

        $current = $user->investorRank;
        if (!$current || $target->level >= $current->level) {
            throw ValidationException::withMessages(['rank_id' => ['Choose a rank lower than the investor\'s current rank.']]);
        }

        $change = $this->changes->applyManual($user, $target, $request->user(), $v['reason'], (bool) ($v['override'] ?? false), $v['override_justification'] ?? null);

        return $this->changeResponse($change, 'Investor downgraded.');
    }

    // GET /admin/ranks/reviews?status=open|retained|downgraded|cleared|all
    public function reviews(Request $request)
    {
        Gate::authorize('ranks.view');
        $status = $request->input('status', 'open');

        $page = RankDowngradeReview::with(['investor:id,name,full_name,email', 'rank'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => $this->present->review($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    // POST /admin/ranks/reviews/{review}/retain
    public function retainReview(Request $request, RankDowngradeReview $review)
    {
        Gate::authorize('ranks.downgrade');
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        $change = $this->changes->retainReview($review, $request->user(), $v['reason']);

        return $this->changeResponse($change, 'Current rank retained. Decision recorded.');
    }

    // GET /admin/ranks/audit?investor=&rank_id=&change_type=&actor=&automatic=&run_id=&from=&to=
    public function audit(Request $request)
    {
        Gate::authorize('ranks.view-audit');

        $v = $request->validate([
            'investor' => 'nullable|string|max:100', 'rank_id' => 'nullable|integer',
            'change_type' => 'nullable|string|in:' . implode(',', InvestorRankChange::TYPES),
            'actor' => 'nullable|string|max:50', 'automatic' => 'nullable|boolean', 'run_id' => 'nullable|integer',
            'from' => 'nullable|date', 'to' => 'nullable|date', 'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $q = InvestorRankChange::with(['investor:id,name,full_name', 'previousRank', 'newRank', 'notification'])->orderByDesc('id');

        if (!empty($v['investor'])) {
            $term = $v['investor'];
            $id   = User::idFromAccountId($term);
            $esc  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $q->where(function ($w) use ($id, $esc, $term) {
                $w->where('account_id', 'like', "%$esc%")
                  ->orWhereIn('investor_id', User::where('role', 'investor')->where(fn ($u) => $u
                        ->where('name', 'like', "%$esc%")->orWhere('full_name', 'like', "%$esc%")->orWhere('email', 'like', "%$esc%"))->select('id'));
                if ($id) $w->orWhere('investor_id', $id);
            });
        }
        if (!empty($v['rank_id'])) $q->where(fn ($w) => $w->where('new_rank_id', $v['rank_id'])->orWhere('previous_rank_id', $v['rank_id']));
        if (!empty($v['change_type'])) $q->where('change_type', $v['change_type']);
        if (!empty($v['actor'])) {
            $v['actor'] === 'system' ? $q->whereNull('actor_id') : $q->where('actor_id', (int) $v['actor']);
        }
        if ($request->has('automatic') && $request->input('automatic') !== '') $q->where('is_automatic', $request->boolean('automatic'));
        if (!empty($v['run_id'])) $q->where('evaluation_run_id', $v['run_id']);
        if (!empty($v['from'])) $q->where('created_at', '>=', $v['from']);
        if (!empty($v['to']))   $q->where('created_at', '<=', $v['to'] . ' 23:59:59');

        $page = $q->paginate($v['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($c) => $this->present->change($c))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'actors' => InvestorRankChange::whereNotNull('actor_id')->select('actor_id', 'actor_name')->distinct()->get(),
        ]);
    }

    // GET /admin/ranks/alerts — delivery state of the admin alerts (find + fix failures)
    public function alerts(Request $request)
    {
        Gate::authorize('ranks.view');
        $status = $request->input('status', 'failed');

        $rows = RankChangeNotification::with('change')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->limit(100)->get()->map(fn ($n) => [
                'id' => $n->id, 'status' => $n->status, 'attempts' => $n->attempts, 'last_error' => $n->last_error,
                'sent_at' => $n->sent_at?->toIso8601String(),
                'rank_change_id' => $n->investor_rank_change_id,
                'reference' => $n->change?->reference, 'account_id' => $n->change?->account_id,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => $rows,
            'counts' => RankChangeNotification::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    // POST /admin/ranks/alerts/retry
    public function retryAlerts()
    {
        // State-changing (re-queues notification jobs), so it needs more than read access.
        Gate::authorize('ranks.manage-criteria');

        $stale = now()->subMinutes((int) config('ranks.notification_stale_minutes', 10));
        $ids = RankChangeNotification::where(fn ($q) => $q->where('status', 'failed')
            ->orWhere(fn ($q2) => $q2->where('status', 'pending')->where('created_at', '<', $stale)))->pluck('investor_rank_change_id');
        foreach ($ids as $id) SendRankChangeNotificationJob::dispatch($id);

        $reviews = RankDowngradeReview::open()->whereNull('notified_at')->where('created_at', '<', $stale)->pluck('id');
        foreach ($reviews as $id) SendDowngradeReviewNotificationJob::dispatch($id);

        return response()->json(['message' => "Re-queued {$ids->count()} upgrade alert(s) and {$reviews->count()} review alert(s)."]);
    }

    protected function manualRules(Request $request): array
    {
        return $request->validate([
            'rank_id'                => 'required|integer|exists:investor_ranks,id',
            'reason'                 => 'required|string|min:5|max:1000',
            'override'               => 'nullable|boolean',
            'override_justification' => 'nullable|string|max:1000',
        ]);
    }

    protected function changeResponse(InvestorRankChange $change, string $message)
    {
        $change->load(['previousRank', 'newRank', 'investor:id,name,full_name']);
        return response()->json(['message' => $message, 'change' => $this->present->change($change)]);
    }
}
