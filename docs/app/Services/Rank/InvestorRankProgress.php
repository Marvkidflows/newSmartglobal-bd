<?php
// LOCATION: app/Services/Rank/InvestorRankProgress.php
// READ-ONLY. Shapes ONE investor's own progress toward the next rank, using the existing rank engine
// (RankInvestmentLedger for verified-USD amount/cycles, RankRuleEvaluator for qualification, RankEligibility
// for prerequisites). No calculation is re-implemented here and nothing is written.

namespace App\Services\Rank;

use App\Models\InvestorRank;
use App\Models\InvestorRankHold;
use App\Models\RankEvaluationRun;
use App\Models\User;

class InvestorRankProgress
{
    public function __construct(
        protected RankEvaluationService $engine,
        protected RankInvestmentLedger $ledger,
        protected RankFieldRegistry $registry,
        protected RankRuleEvaluator $evaluator,
        protected RankEligibility $eligibility,
        protected RankPresenter $presenter,
    ) {}

    public function for(User $user): array
    {
        $facts   = $this->ledger->forUsers([$user->id], true)[$user->id];
        $ranks   = InvestorRank::orderBy('level')->get();
        $current = $user->investor_rank_id ? $ranks->firstWhere('id', $user->investor_rank_id) : null;
        $next    = $ranks->first(fn ($r) => $r->level > ($current?->level ?? 0));
        $usable  = $this->engine->activeCriteria()->keyBy('investor_rank_id');

        $out = [
            'current_amount_usd'   => $facts['current_principal'],      // Current eligible principal (verified) — the monetary rank measure
            'historical_total_usd' => $facts['total_usd'],              // reporting only; never decides rank
            'calculation'          => [
                'calculated_at' => $facts['calculated_at'], 'positions' => $facts['breakdown'], 'cycle_references' => $facts['cycle_references'],
            ],
            'completed_cycles'     => $facts['cycles'],
            'excluded' => [
                'awaiting_verification' => count($facts['unverified']) + count($facts['stale']),
                'under_review'          => count($facts['blocked']),
                'cycles_awaiting_verification' => count($facts['cycles_awaiting_verification']),
                'possible_duplicates'   => count($facts['duplicates']),
            ],
            'last_evaluation_at'   => RankEvaluationRun::whereNull('target_level')->whereIn('status', ['completed', 'completed_with_errors'])
                                        ->latest('finished_at')->first()?->finished_at?->toIso8601String(),
            'rank_changed_at'      => $user->rank_changed_at?->toIso8601String(),
            'next_rank'            => $next?->badge(),
            'approval_required'    => (bool) config('ranks.require_approval', true),   // true: eligibility raises a review; a person approves the rank
            'state'                => 'highest',
            'amount'               => null,
            'cycles'               => null,
            'percent'              => null,
        ];
        if (!$next) return $out;

        $criterion = $usable->get($next->id);
        if (!$criterion) { $out['state'] = 'awaiting_approval'; return $out; }

        $c = $this->presenter->criterion($criterion);
        $minAmount = $minCycles = null;
        if ($c['logic'] === 'all') {
            foreach ($c['conditions'] as $x) {
                if ($x['operator'] !== '>=') continue;
                if ($x['field'] === \App\Services\Rank\CoreRequirements::AMOUNT_FIELD) $minAmount = (float) $x['value'];
                if ($x['field'] === 'completed_investment_count') $minCycles = (int) $x['value'];
            }
        }

        $parts = [];
        if ($minAmount !== null) {
            $out['amount'] = $this->bar($facts['current_principal'], $minAmount, 2); $parts[] = $out['amount']['percent'];
        }
        if ($minCycles !== null) {
            $out['cycles'] = $this->bar($facts['cycles'], $minCycles, 0); $parts[] = $out['cycles']['percent'];
        }
        $out['percent'] = $parts ? (int) floor(array_sum($parts) / count($parts)) : null;

        // Qualification comes from the engine's own evaluator, not from the bars above.
        $result = $this->engine->evaluateRuleFor($user->id, $criterion->toRule());
        if ($result['satisfied']) {
            // Admin review is needed when a prerequisite fails, or an active hold blocks THIS rank.
            $hold    = InvestorRankHold::active()->where('investor_id', $user->id)->latest('id')->first();
            $held    = $hold && collect($hold->blocked)->contains(fn ($e) => (int) ($e['rank_id'] ?? 0) === (int) $next->id);
            $blocked = !$this->eligibility->check($user)['eligible'] || $held;
            $out['state'] = $blocked ? 'needs_review' : 'qualifies';
            if ($out['percent'] !== null) $out['percent'] = 100;
        } else {
            $out['state'] = 'in_progress';
        }

        return $out;
    }

    protected function bar(float|int $current, float|int $required, int $dp): array
    {
        $n = fn ($v) => $dp === 0 ? (int) round($v) : round((float) $v, $dp);   // whole numbers stay integers for cycles
        return [
            'current'   => $n($current), 'required' => $n($required), 'remaining' => $n(max(0, $required - $current)),
            'percent'   => $required > 0 ? (int) min(100, floor($current / $required * 100)) : 100,
        ];
    }
}
