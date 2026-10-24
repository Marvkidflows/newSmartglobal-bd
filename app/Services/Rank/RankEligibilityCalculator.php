<?php
// LOCATION: app/Services/Rank/RankEligibilityCalculator.php
//
// CALCULATED eligibility — a READ-ONLY view of where each investor stands against every rank, built from the same
// rule evaluator, field registry, verified-USD ledger and additional-criteria service the engine uses, so the
// backend, the Admin dashboard and the export always agree. It assigns nothing and writes nothing.
//
// For each rank it reports: the core (mandatory) requirements, all five additional criteria (with whether each is
// enabled), the compliance prerequisites, whether the investor qualifies, and every unmet requirement.

namespace App\Services\Rank;

use App\Models\InvestorRank;
use App\Models\RankCriterion;
use App\Models\RankReview;
use App\Models\User;

class RankEligibilityCalculator
{
    public function __construct(
        protected RankFieldRegistry $registry,
        protected RankRuleEvaluator $evaluator,
        protected RankEvaluationService $evaluation,
        protected RankEligibility $eligibility,
        protected RankAdditionalCriteriaService $additional,
        protected RankInvestmentLedger $ledger,
        protected RankReviewService $reviews,
    ) {}

    public function calculate(User $user): array
    {
        return $this->calculateMany([$user])[$user->id];
    }

    /**
     * @param  User[]  $users  (investorRank relation optional)
     * @return array<int, array>
     */
    public function calculateMany(iterable $users): array
    {
        $users = collect($users)->values();
        $ids   = $users->pluck('id')->all();
        if (!$ids) return [];

        $ranks    = InvestorRank::orderBy('level')->get();
        $criteria = RankCriterion::where('is_active', true)->with(['rank', 'conditions'])->get()->keyBy('investor_rank_id');
        $fields   = $criteria->flatMap(fn ($c) => $c->conditions->pluck('field'))->unique()->values()->all();
        $coreVals = $this->registry->resolveMany($ids, $fields);
        $addlVals = $this->additional->valuesFor($ids);
        $ledger   = $this->ledger->forUsers($ids);
        $pending  = RankReview::pending()->whereIn('investor_id', $ids)->with(['proposedRank', 'currentRank', 'investor'])->get()->keyBy('investor_id');

        $out = [];
        foreach ($users as $user) {
            $prereq = $this->eligibility->check($user);
            $perRank = []; $eligibleRank = null;

            foreach ($ranks as $rank) {
                $c = $criteria->get($rank->id);
                $usable = $c && $this->evaluation->usable($c);
                $coreRows = []; $coreMet = false;

                if ($usable) {
                    $res = $this->evaluator->evaluate($c->toRule(), $coreVals[$user->id] ?? []);
                    $coreRows = $res['conditions']; $coreMet = $res['satisfied'];
                }

                $addRows = $this->additional->conditionsFor($user->id, $rank->id, false, $addlVals[$user->id] ?? []);
                $addMet  = collect($addRows)->where('enabled', true)->every(fn ($r) => $r['passed']);

                $unmet = [];
                foreach ($prereq['failures'] as $f) $unmet[] = $f['text'];
                if (!$c)           $unmet[] = 'No active core requirement set is configured for this rank.';
                elseif (!$usable)  $unmet[] = 'The core requirement set cannot be used yet (money criteria await currency confirmation).';
                else {
                    foreach ($coreRows as $r) if (!$r['passed'] && ($res['logic'] ?? 'all') === 'all') $unmet[] = $r['text'] . ' — actual: ' . $this->fmt($r['actual']);
                    if (!$coreMet && !$unmet) $unmet[] = 'The core requirements are not met.';
                }
                foreach ($addRows as $r) if ($r['enabled'] && !$r['passed']) $unmet[] = $r['text'] . ' — actual: ' . $this->fmt($r['actual']);

                $qualifies = $prereq['eligible'] && $usable && $coreMet && $addMet;
                if ($qualifies) $eligibleRank = $rank;

                $perRank[] = [
                    'rank' => $rank->badge(),
                    'rule_state' => !$c ? 'no_active_rule' : ($usable ? 'active' : 'blocked_pending_currency_confirmation'),
                    'core' => [
                        'met' => $coreMet,
                        'conditions' => collect($coreRows)->map(fn ($r) => [
                            'label' => $r['label'], 'text' => $r['text'], 'expected' => $r['expected'], 'actual' => $r['actual'],
                            'passed' => $r['passed'], 'mandatory' => true, 'enabled' => true,
                        ])->values()->all(),
                    ],
                    'additional' => collect($addRows)->map(fn ($r) => [
                        'key' => $r['criterion_key'], 'label' => $r['label'], 'text' => $r['text'], 'expected' => $r['expected'],
                        'actual' => $r['actual'], 'passed' => $r['passed'], 'enabled' => $r['enabled'],
                        'approval_status' => $r['approval_status'], 'provisional' => $r['provisional'], 'unit' => $r['unit'],
                    ])->values()->all(),
                    'prerequisites_met' => $prereq['eligible'],
                    'qualifies' => $qualifies,
                    'unmet' => array_values(array_unique($unmet)),
                ];
            }

            $assigned = $user->investor_rank_id ? $ranks->firstWhere('id', $user->investor_rank_id) : null;
            $pend = $pending->get($user->id);

            $out[$user->id] = [
                'investor_id' => $user->id, 'account_id' => $user->account_id, 'name' => $user->name ?? $user->full_name, 'email' => $user->email,
                'assigned_rank' => $assigned?->badge(),
                'rank_status' => $user->rank_status ?? 'active',
                'calculated_rank' => $eligibleRank?->badge(),
                'pending_review' => $pend ? $this->reviews->present($pend) : null,
                'core_values' => ['verified_total_invested_usd' => $ledger[$user->id]['total_usd'], 'completed_cycles' => $ledger[$user->id]['cycles']],
                'additional_values' => $addlVals[$user->id] ?? [],
                'prerequisites' => $prereq,
                'ranks' => $perRank,
            ];
        }

        return $out;
    }

    protected function fmt(mixed $v): string
    {
        return is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 2), '0'), '.') : (string) ($v ?? 'n/a');
    }
}
