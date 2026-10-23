<?php
// LOCATION: app/Http/Controllers/Investor/InvestorRankController.php
// READ-ONLY: an investor can see their own rank; nothing here can change it.
// The frontend re-fetches this to refresh the badge without a re-login.

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\InvestorRank;
use App\Models\RankCriterion;
use App\Services\Rank\InvestorRankProgress;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankPresenter;
use Illuminate\Http\Request;

class InvestorRankController extends Controller
{
    // GET /investor-investment/rank
    public function show(Request $request)
    {
        $user = $request->user()->load('investorRank');

        return response()->json([
            'rank'            => $user->rankBadge(),
            'account_id'      => $user->account_id,
            'rank_changed_at' => $user->rank_changed_at?->toIso8601String(),
        ]);
    }

    // GET /investor-investment/rank/criteria
    // READ-ONLY, identical for every investor except which rank is flagged "current".
    // Reads the existing criteria; exposes only investor-safe fields (no ids, authors, notes, history).
    // A rank's requirements are shown as ACTIVE only when the rank engine actually uses them;
    // drafts, disabled rules and money rules blocked by the currency sign-off are "awaiting approval".
    public function criteria(Request $request, RankEvaluationService $engine, RankPresenter $presenter, InvestorRankProgress $progress)
    {
        $user      = $request->user();
        $usableIds = $engine->activeCriteria()->keyBy('investor_rank_id');
        $anyActive = RankCriterion::where('is_active', true)->get()->keyBy('investor_rank_id');

        $ranks = InvestorRank::orderBy('level')->get()->map(function (InvestorRank $rank) use ($usableIds, $anyActive, $presenter, $user) {
            $criterion = $usableIds->get($rank->id);
            $status = $criterion ? 'active' : ($anyActive->has($rank->id) ? 'pending_currency_approval' : 'awaiting_approval');

            $requirements = []; $minAmount = null; $minCycles = null;
            if ($criterion) {
                $c = $presenter->criterion($criterion);
                $requirements = collect($c['conditions'])->map(fn ($x) => ['label' => $x['label'], 'text' => $x['text']])->all();
                if ($c['logic'] === 'all') {
                    foreach ($c['conditions'] as $x) {
                        if ($x['operator'] !== '>=') continue;
                        if ($x['field'] === 'total_invested') $minAmount = (float) $x['value'];
                        if ($x['field'] === 'completed_investment_count') $minCycles = (int) $x['value'];
                    }
                }
            }

            return $rank->badge() + [
                'status'               => $status,
                'is_current'           => (int) $user->investor_rank_id === (int) $rank->id,
                'min_investment_usd'   => $minAmount,
                'min_completed_cycles' => $minCycles,
                'requirements'         => $requirements,
            ];
        })->values();

        return response()->json([
            'current_rank_key' => $user->investorRank?->key,
            'ranks'            => $ranks,
            'progress'         => $progress->for($user),   // authenticated investor only; no id is read from the request
        ]);
    }
}
