<?php
// LOCATION: app/Http/Controllers/Admin/RankCriteriaController.php
//
// Admin Rank Criteria Management. Thin on purpose: validation and persistence
// rules live in RankCriteriaValidator / RankCriteriaService.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvestorRank;
use App\Models\RankCriteriaHistory;
use App\Models\RankCriterion;
use App\Services\Rank\RankCriteriaService;
use App\Services\Rank\RankCriteriaValidator;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankFieldRegistry;
use App\Services\Rank\RankPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RankCriteriaController extends Controller
{
    public function __construct(
        protected RankCriteriaService $criteria,
        protected RankCriteriaValidator $validator,
        protected RankEvaluationService $evaluation,
        protected RankFieldRegistry $registry,
        protected RankPresenter $present,
    ) {}

    // GET /admin/ranks/criteria/schema — what the builder may offer (fields, operators, logic)
    public function schema()
    {
        Gate::authorize('ranks.view');
        return response()->json($this->registry->schema());
    }

    // GET /admin/ranks/criteria — every rank with ALL its criteria (active, inactive)
    public function index(Request $request)
    {
        Gate::authorize('ranks.view');

        $all = RankCriterion::query()->with(['rank', 'conditions', 'creator:id,name,full_name', 'updater:id,name,full_name'])
            ->orderByDesc('is_active')->orderByDesc('updated_at')->get()->groupBy('investor_rank_id');

        $ranks = InvestorRank::orderBy('level')->get()->map(function ($rank) use ($all) {
            $list = ($all[$rank->id] ?? collect())->map(fn ($c) => $this->present->criterion($c))->values();
            return [
                'rank'     => $rank->badge(),
                'active'   => $list->firstWhere('is_active', true),
                'criteria' => $list->all(),
            ];
        });

        return response()->json(['ranks' => $ranks]);
    }

    // POST /admin/ranks/criteria
    public function store(Request $request)
    {
        Gate::authorize('ranks.manage-criteria');
        $request->validate($this->shape());

        $out = $this->criteria->create($request->all(), $request->user());

        return response()->json([
            'message'   => $out['criterion']->is_active
                ? 'Criteria saved and activated. Re-evaluating investors in the background.'
                : 'Criteria saved as inactive.',
            'criterion' => $this->present->criterion($out['criterion']),
            'run'       => $out['run'] ? $this->present->run($out['run']) : null,
        ], 201);
    }

    // PUT /admin/ranks/criteria/{criterion}
    public function update(Request $request, RankCriterion $criterion)
    {
        Gate::authorize('ranks.manage-criteria');
        $request->validate($this->shape(false));

        $out = $this->criteria->update($criterion, $request->all(), $request->user());

        return response()->json([
            'message'   => !$out['changed'] ? 'No changes to save.'
                : ($out['run'] ? 'Criteria updated. Re-evaluating investors in the background.' : 'Criteria updated.'),
            'criterion' => $this->present->criterion($out['criterion']),
            'run'       => $out['run'] ? $this->present->run($out['run']) : null,
        ]);
    }

    // POST /admin/ranks/criteria/{criterion}/activate
    public function activate(Request $request, RankCriterion $criterion)
    {
        Gate::authorize('ranks.manage-criteria');
        $request->validate(['reason' => 'nullable|string|max:1000']);

        $out = $this->criteria->activate($criterion, $request->user(), $request->input('reason'));

        return response()->json([
            'message'   => $out['run'] ? 'Criteria activated. Re-evaluating investors in the background.' : 'Criteria already active.',
            'criterion' => $this->present->criterion($out['criterion']),
            'run'       => $out['run'] ? $this->present->run($out['run']) : null,
        ]);
    }

    // POST /admin/ranks/criteria/{criterion}/deactivate
    public function deactivate(Request $request, RankCriterion $criterion)
    {
        Gate::authorize('ranks.manage-criteria');
        $request->validate(['reason' => 'nullable|string|max:1000']);

        $c = $this->criteria->deactivate($criterion, $request->user(), $request->input('reason'));

        return response()->json([
            'message'   => 'Criteria deactivated. Existing investor ranks are unchanged.',
            'criterion' => $this->present->criterion($c),
        ]);
    }

    // DELETE /admin/ranks/criteria/{criterion} — archives; history is retained
    public function destroy(Request $request, RankCriterion $criterion)
    {
        Gate::authorize('ranks.manage-criteria');
        $request->validate(['reason' => 'required|string|min:5|max:1000']);

        $this->criteria->archive($criterion, $request->user(), $request->input('reason'));

        return response()->json(['message' => 'Criteria removed. Its history has been retained for audit.']);
    }

    // POST /admin/ranks/criteria/validate — dry validation, nothing saved
    public function validateOnly(Request $request)
    {
        Gate::authorize('ranks.manage-criteria');
        $request->validate(['investor_rank_id' => 'required|integer|exists:investor_ranks,id']);

        $result = $this->validator->validate($request->all(), $request->integer('criterion_id') ?: null);

        return response()->json([
            'valid'     => $result['valid'],
            'errors'    => $result['errors'],
            'conflicts' => $result['conflicts'],
            'warnings'  => $result['warnings'],
            'rule_text' => $result['valid'] ? app(\App\Services\Rank\RankRuleEvaluator::class)->describeRule($result['normalized']) : null,
        ]);
    }

    // POST /admin/ranks/criteria/preview — who would qualify if this were active? (bounded dry run)
    public function preview(Request $request)
    {
        Gate::authorize('ranks.manage-criteria');
        $request->validate(['investor_rank_id' => 'required|integer|exists:investor_ranks,id']);

        // Preview changes nothing, so it is allowed while the currency is unconfirmed.
        $result = $this->validator->validate(array_merge($request->all(), ['is_active' => true]), $request->integer('criterion_id') ?: null, null, false);
        if ($result['errors'] || $result['conflicts']) {
            return response()->json([
                'message' => 'Fix the configuration before previewing.',
                'errors'  => $result['errors'] + ($result['conflicts'] ? ['conflicts' => array_column($result['conflicts'], 'message')] : []),
            ], 422);
        }

        $rank = InvestorRank::findOrFail($result['normalized']['investor_rank_id']);

        return response()->json($this->evaluation->preview($result['normalized'], $rank));
    }

    // GET /admin/ranks/criteria/history?rank_id=&criterion_id=&actor_id=&action=&from=&to=
    public function history(Request $request)
    {
        Gate::authorize('ranks.view-audit');

        $v = $request->validate([
            'rank_id' => 'nullable|integer', 'criterion_id' => 'nullable|integer', 'actor_id' => 'nullable|integer',
            'action' => 'nullable|string|max:20', 'from' => 'nullable|date', 'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $q = RankCriteriaHistory::query()->with('rank')->orderByDesc('id')
            ->when($v['rank_id'] ?? null, fn ($q, $x) => $q->where('investor_rank_id', $x))
            ->when($v['criterion_id'] ?? null, fn ($q, $x) => $q->where('rank_criterion_id', $x))
            ->when($v['actor_id'] ?? null, fn ($q, $x) => $q->where('actor_id', $x))
            ->when($v['action'] ?? null, fn ($q, $x) => $q->where('action', $x))
            ->when($v['from'] ?? null, fn ($q, $x) => $q->where('created_at', '>=', $x))
            ->when($v['to'] ?? null, fn ($q, $x) => $q->where('created_at', '<=', $x . ' 23:59:59'));

        $page = $q->paginate($v['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($h) => $this->present->criteriaHistory($h))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    protected function shape(bool $withRank = true): array
    {
        return array_filter([
            'investor_rank_id' => $withRank ? 'required|integer|exists:investor_ranks,id' : null,
            'name'             => 'required|string|max:120',
            'description'      => 'nullable|string|max:1000',
            'logic'            => 'required|string|in:all,any,grouped',
            'is_active'        => 'nullable|boolean',
            'reason'           => 'nullable|string|max:1000',
            'conditions'       => 'required|array|min:1|max:' . RankCriteriaValidator::MAX_CONDITIONS,
            'conditions.*'     => 'array',
        ]);
    }
}
