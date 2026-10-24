<?php
// LOCATION: app/Http/Controllers/Admin/RankAdditionalCriteriaController.php
//
// Configuration of the five ADDITIONAL criteria: thresholds, Management's measurement policy, enable / disable.
// Admin only (Gate ranks.view to read, ranks.manage-criteria to change). Every change needs a reason and is audited.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvestorRank;
use App\Models\RankCriterion;
use App\Services\Rank\RankAdditionalCriteriaService;
use App\Services\Rank\RankCriteriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RankAdditionalCriteriaController extends Controller
{
    public function __construct(protected RankAdditionalCriteriaService $additional) {}

    // GET /admin/ranks/additional-criteria
    public function index()
    {
        Gate::authorize('ranks.view');
        return response()->json($this->additional->matrix() + ['history' => $this->additional->history_list()]);
    }

    // PUT /admin/ranks/additional-criteria/{key}/thresholds   { thresholds: {bronze: 1, …}, reason }
    public function thresholds(Request $request, string $key)
    {
        Gate::authorize('ranks.manage-criteria');
        $v = $request->validate(['thresholds' => 'required|array|min:1', 'thresholds.*' => 'required|numeric|min:0', 'reason' => 'required|string|min:5|max:1000']);
        $this->additional->setThresholds($key, $v['thresholds'], $request->user(), $v['reason']);
        return response()->json($this->additional->matrix());
    }

    // PUT /admin/ranks/additional-criteria/{key}/policy       { choice, reason }
    public function policy(Request $request, string $key)
    {
        Gate::authorize('ranks.manage-criteria');
        $v = $request->validate(['choice' => 'required|string|max:40', 'reason' => 'required|string|min:5|max:1000']);
        $this->additional->setPolicy($key, $v['choice'], $request->user(), $v['reason']);
        return response()->json($this->additional->matrix());
    }

    // POST /admin/ranks/additional-criteria/{key}/enable|disable   { reason }
    public function enable(Request $request, string $key)
    {
        return $this->toggle($request, $key, true);
    }

    public function disable(Request $request, string $key)
    {
        return $this->toggle($request, $key, false);
    }

    protected function toggle(Request $request, string $key, bool $on)
    {
        Gate::authorize('ranks.manage-criteria');
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $this->additional->setEnabled($key, $on, $request->user(), $v['reason']);
        return response()->json($this->additional->matrix());
    }

    // POST /admin/ranks/criteria/load-spec-drafts
    // Creates the six CORE requirement sets from the official specification as INACTIVE DRAFTS (never active),
    // only for ranks that have no rule at all, through the same validated + audited service as the dashboard.
    // Activation remains a separate, deliberate Admin action that Management must authorise.
    public function loadCoreDrafts(Request $request, RankCriteriaService $criteria)
    {
        Gate::authorize('ranks.manage-criteria');
        $created = []; $skipped = [];

        foreach (config('ranks_proposed.ranks', []) as $key => $def) {
            $rank = InvestorRank::where('key', $key)->first();
            if (!$rank) continue;
            if (RankCriterion::where('investor_rank_id', $rank->id)->exists()) { $skipped[] = $rank->name; continue; }

            try {
                $criteria->create([
                    'investor_rank_id' => $rank->id,
                    'name'             => "SSI specification — {$rank->name} core criteria (pending Management approval)",
                    'description'      => "From the official SSI specification: investment {$def['min_amount']}" . ($def['band_max'] ? "–{$def['band_max']}" : '+')
                        . " USD and at least {$def['min_cycles']} completed investment cycle(s). INACTIVE until Management authorises activation.",
                    'logic' => 'all', 'is_active' => false,
                    'reason' => 'Loaded core thresholds from the official SSI specification as an inactive draft',
                    'conditions' => [
                        ['field' => 'total_invested', 'operator' => '>=', 'value' => $def['min_amount'], 'group_no' => 1],
                        ['field' => 'completed_investment_count', 'operator' => '>=', 'value' => $def['min_cycles'], 'group_no' => 1],
                    ],
                ], $request->user());
                $created[] = $rank->name;
            } catch (ValidationException $e) {
                return response()->json(['message' => "{$rank->name}: " . collect($e->errors())->flatten()->implode(' | '), 'created' => $created, 'skipped' => $skipped], 422);
            }
        }

        return response()->json(['created' => $created, 'skipped' => $skipped, 'activated' => []]);
    }
}
