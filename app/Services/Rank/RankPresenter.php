<?php
// LOCATION: app/Services/Rank/RankPresenter.php
// One place that shapes rank data for API responses.

namespace App\Services\Rank;

use App\Models\InvestorRankChange;
use App\Models\RankCriteriaHistory;
use App\Models\RankCriterion;
use App\Models\RankDowngradeReview;
use App\Models\RankEvaluationRun;
use App\Models\User;

class RankPresenter
{
    public function __construct(protected RankFieldRegistry $registry, protected RankRuleEvaluator $evaluator) {}

    public function criterion(RankCriterion $c): array
    {
        $c->loadMissing(['rank', 'conditions', 'creator:id,name,full_name', 'updater:id,name,full_name']);
        $rule = $c->toRule();

        return [
            'id'          => $c->id,
            'rank'        => $c->rank?->badge(),
            'name'        => $c->name,
            'description' => $c->description,
            'logic'       => $c->logic,
            'is_active'   => (bool) $c->is_active,
            'version'     => $c->version,
            'rule_text'   => $this->evaluator->describeRule($rule),
            'conditions'  => collect($rule['conditions'])->map(fn ($x) => $x + [
                'label' => $this->registry->get($x['field'])['label'] ?? $x['field'],
                'text'  => $this->evaluator->describeCondition($x),
            ])->all(),
            'created_by'  => $c->creator ? ($c->creator->name ?? $c->creator->full_name) : null,
            'updated_by'  => $c->updater ? ($c->updater->name ?? $c->updater->full_name) : null,
            'activated_at'   => $c->activated_at?->toIso8601String(),
            'deactivated_at' => $c->deactivated_at?->toIso8601String(),
            'created_at'  => $c->created_at?->toIso8601String(),
            'updated_at'  => $c->updated_at?->toIso8601String(),
        ];
    }

    public function criteriaHistory(RankCriteriaHistory $h): array
    {
        return [
            'id'         => $h->id,
            'criterion_id' => $h->rank_criterion_id,
            'rank'       => $h->rank?->badge(),
            'version'    => $h->version,
            'action'     => $h->action,
            'actor_id'   => $h->actor_id,
            'actor_name' => $h->actor_name,
            'previous'   => $h->previous,
            'new'        => $h->new,
            'reason'     => $h->reason,
            'created_at' => $h->created_at?->toIso8601String(),
        ];
    }

    public function change(InvestorRankChange $c): array
    {
        return [
            'id'            => $c->id,
            'reference'     => $c->reference,
            'investor_id'   => $c->investor_id,
            'account_id'    => $c->account_id,
            'investor_name' => $c->relationLoaded('investor') ? ($c->investor?->name ?? $c->investor?->full_name) : null,
            'sequence'      => $c->sequence,
            'previous_rank' => $c->previousRank?->badge(),
            'new_rank'      => $c->newRank?->badge(),
            'change_type'   => $c->change_type,
            'is_automatic'  => (bool) $c->is_automatic,
            'actor_id'      => $c->actor_id,
            'actor_name'    => $c->actor_name,
            'actor_role'    => $c->actor_role,
            'reason'        => $c->reason,
            'override_justification' => $c->override_justification,
            'criteria_snapshot'      => $c->criteria_snapshot,
            'evaluation_run_id'      => $c->evaluation_run_id,
            'notification_status'    => $c->relationLoaded('notification') ? $c->notification?->status : null,
            'created_at'    => $c->created_at?->toIso8601String(),
        ];
    }

    public function review(RankDowngradeReview $r): array
    {
        return [
            'id'                => $r->id,
            'investor_id'       => $r->investor_id,
            'account_id'        => $r->relationLoaded('investor') && $r->investor ? $r->investor->account_id : null,
            'investor_name'     => $r->relationLoaded('investor') ? ($r->investor?->name ?? $r->investor?->full_name) : null,
            'rank'              => $r->rank?->badge(),
            'criterion_id'      => $r->rank_criterion_id,
            'criterion_version' => $r->criterion_version,
            'failed_conditions' => $r->failed_conditions,
            'status'            => $r->status,
            'alert_delivered'   => (bool) $r->notified_at,
            'resolved_by_name'  => $r->resolved_by_name,
            'resolution_reason' => $r->resolution_reason,
            'resolved_at'       => $r->resolved_at?->toIso8601String(),
            'created_at'        => $r->created_at?->toIso8601String(),
        ];
    }

    public function run(RankEvaluationRun $r): array
    {
        $finished = $r->isFinished();
        $stalled  = !$finished && $r->heartbeat_at && $r->heartbeat_at->lt(now()->subMinutes(10));
        $done     = $r->processed + $r->failed;

        return [
            'id'         => $r->id,
            'reference'  => $r->reference,
            'trigger'    => $r->trigger,
            'status'     => $r->status,
            // true ONLY when every in-scope investor was attempted and none errored
            'is_complete' => $r->status === 'completed',
            'is_stalled' => $stalled,
            'total'      => $r->total,
            'processed'  => $r->processed,
            'upgraded'   => $r->upgraded,
            'failed'     => $r->failed,
            'percent'    => $r->total > 0 ? min(100, (int) floor($done / $r->total * 100)) : 100,
            'failures'   => $r->failures ?? [],
            'error'      => $r->error,
            'rank_criterion_id' => $r->rank_criterion_id,
            'started_at'  => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
            'created_at'  => $r->created_at?->toIso8601String(),
        ];
    }

    public function investorRow(User $u, bool $needsReview = false): array
    {
        return [
            'id'              => $u->id,
            'account_id'      => $u->account_id,
            'name'            => $u->name ?? $u->full_name,
            'email'           => $u->email,
            'status'          => $u->status ?? 'active',
            // ADMIN view: the assigned rank is always shown, with its status. (Investor-facing badges use
            // User::rankBadge(), which is null unless the status is 'active'.)
            'rank'            => $u->investorRank?->badge(),
            'rank_status'     => $u->rank_status ?? 'active',
            'rank_changed_at' => $u->rank_changed_at?->toIso8601String(),
            'needs_review'    => $needsReview,
        ];
    }
}
