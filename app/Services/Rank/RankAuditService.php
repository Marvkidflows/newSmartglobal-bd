<?php
// LOCATION: app/Services/Rank/RankAuditService.php
//
// Single writer of investor_rank_changes and rank_criteria_history. Call it
// INSIDE the same DB::transaction as the change being audited so the change
// and its trail commit (or roll back) together.

namespace App\Services\Rank;

use App\Models\InvestorRankChange;
use App\Models\RankCriteriaHistory;
use App\Models\RankCriterion;
use App\Models\User;
use Illuminate\Support\Str;

class RankAuditService
{
    public const SYSTEM_ACTOR_NAME = 'System (Rank Engine)';

    public function nextSequence(int $investorId): int
    {
        return ((int) InvestorRankChange::where('investor_id', $investorId)->max('sequence')) + 1;
    }

    public function recordChange(
        User $investor,
        ?int $previousRankId,
        int $newRankId,
        string $changeType,
        ?User $actor,
        string $reason,
        ?array $criteriaSnapshot = null,
        ?string $overrideJustification = null,
        ?int $evaluationRunId = null,
    ): InvestorRankChange {
        return InvestorRankChange::create([
            'reference'              => 'RK-' . strtoupper(Str::random(10)),
            'investor_id'            => $investor->id,
            'account_id'             => $investor->account_id,
            'sequence'               => $this->nextSequence($investor->id),
            'previous_rank_id'       => $previousRankId,
            'new_rank_id'            => $newRankId,
            'change_type'            => $changeType,
            'is_automatic'           => $actor === null,
            'actor_id'               => $actor?->id,
            'actor_name'             => $actor ? ($actor->name ?? $actor->full_name ?? 'Admin') : self::SYSTEM_ACTOR_NAME,
            'actor_role'             => $actor ? $actor->role : 'system',
            'reason'                 => $reason,
            'override_justification' => $overrideJustification,
            'criteria_snapshot'      => $criteriaSnapshot,
            'evaluation_run_id'      => $evaluationRunId,
            'created_at'             => now(),
        ]);
    }

    /** Full, self-contained snapshot of a criterion (rule + metadata) at a point in time. */
    public function criterionSnapshot(RankCriterion $c): array
    {
        $c->loadMissing(['rank', 'conditions']);

        return [
            'id'         => $c->id,
            'rank_id'    => $c->investor_rank_id,
            'rank'       => $c->rank?->name,
            'rank_level' => $c->rank?->level,
            'name'       => $c->name,
            'description' => $c->description,
            'logic'      => $c->logic,
            'is_active'  => (bool) $c->is_active,
            'version'    => (int) $c->version,
            'conditions' => $c->conditions->map(fn ($x) => [
                'group_no' => (int) $x->group_no,
                'field'    => $x->field,
                'operator' => $x->operator,
                'value'    => $x->value,
            ])->values()->all(),
        ];
    }

    public function recordCriteria(
        string $action,
        RankCriterion $criterion,
        ?User $actor,
        ?array $previous,
        ?array $new,
        ?string $reason = null,
    ): RankCriteriaHistory {
        return RankCriteriaHistory::create([
            'rank_criterion_id' => $criterion->id,
            'investor_rank_id'  => $criterion->investor_rank_id,
            'version'           => $criterion->version,
            'action'            => $action,
            'actor_id'          => $actor?->id,
            'actor_name'        => $actor ? ($actor->name ?? $actor->full_name ?? 'Admin') : self::SYSTEM_ACTOR_NAME,
            'previous'          => $previous,
            'new'               => $new,
            'reason'            => $reason,
            'created_at'        => now(),
        ]);
    }
}
