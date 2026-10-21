<?php
// LOCATION: app/Http/Controllers/Admin/RankEvaluationRunController.php
// Status + control of queued re-evaluation runs.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessRankEvaluationRunJob;
use App\Models\RankEvaluationRun;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RankEvaluationRunController extends Controller
{
    public function __construct(protected RankEvaluationService $evaluation, protected RankPresenter $present) {}

    // GET /admin/ranks/runs
    public function index()
    {
        Gate::authorize('ranks.view');
        return response()->json([
            'data' => RankEvaluationRun::orderByDesc('id')->limit(30)->get()->map(fn ($r) => $this->present->run($r)),
        ]);
    }

    // GET /admin/ranks/runs/{run}
    public function show(RankEvaluationRun $run)
    {
        Gate::authorize('ranks.view');
        return response()->json(['run' => $this->present->run($run)]);
    }

    // POST /admin/ranks/runs — manually re-evaluate all investors against active criteria
    public function store(Request $request)
    {
        Gate::authorize('ranks.manage-criteria');

        if ($this->evaluation->activeCriteria()->isEmpty()) {
            return response()->json(['message' => 'There are no active criteria to evaluate against.'], 422);
        }

        $busy = RankEvaluationRun::whereIn('status', ['pending', 'running'])->where('heartbeat_at', '>', now()->subMinutes(10))->first();
        if ($busy) {
            return response()->json(['message' => 'A re-evaluation is already in progress.', 'run' => $this->present->run($busy)], 409);
        }

        $run = $this->evaluation->startRun('manual', null, $request->user());

        return response()->json(['message' => 'Re-evaluation queued.', 'run' => $this->present->run($run)], 202);
    }

    // POST /admin/ranks/runs/{run}/resume — continue a stalled or failed run from its cursor
    public function resume(RankEvaluationRun $run)
    {
        Gate::authorize('ranks.manage-criteria');

        if ($run->status === 'completed' || $run->status === 'completed_with_errors') {
            return response()->json(['message' => 'This run has already finished.'], 422);
        }

        $run->update(['status' => 'running', 'error' => null, 'finished_at' => null, 'heartbeat_at' => now()]);
        ProcessRankEvaluationRunJob::dispatch($run->id);

        return response()->json(['message' => 'Run resumed.', 'run' => $this->present->run($run->fresh())]);
    }
}
