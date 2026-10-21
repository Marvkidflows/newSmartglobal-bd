<?php
// LOCATION: app/Services/Rank/RankCriteriaService.php
//
// Create / edit / activate / deactivate / archive rank criteria.
// Every mutation: validates first, runs in one transaction under a lock on the
// rank row, bumps the version, writes an immutable history row (who, when,
// before/after), and — when the active rule set could now promote someone —
// opens a queued re-evaluation run. Nothing here ever lowers a rank.

namespace App\Services\Rank;

use App\Models\InvestorRank;
use App\Models\RankCriterion;
use App\Models\RankEvaluationRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RankCriteriaService
{
    public function __construct(
        protected RankCriteriaValidator $validator,
        protected RankAuditService $audit,
        protected RankEvaluationService $evaluation,
    ) {}

    /** @return array{criterion: RankCriterion, run: ?RankEvaluationRun} */
    public function create(array $payload, User $actor): array
    {
        $activate = (bool) ($payload['is_active'] ?? false);
        $reason   = $payload['reason'] ?? null;

        return DB::transaction(function () use ($payload, $actor, $activate, $reason) {
            $this->lockRank((int) ($payload['investor_rank_id'] ?? 0));
            $n = $this->validateOrFail($payload, null, $activate);

            $criterion = RankCriterion::create([
                'investor_rank_id' => $n['investor_rank_id'], 'name' => $n['name'],
                'description' => $n['description'], 'logic' => $n['logic'],
                'is_active' => false, 'version' => 1,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->syncConditions($criterion, $n['conditions']);
            $criterion->load(['rank', 'conditions']);

            $this->audit->recordCriteria('created', $criterion, $actor, null, $this->audit->criterionSnapshot($criterion), $reason);

            $run = $activate ? $this->activateInternal($criterion, $actor, $reason) : null;

            return ['criterion' => $criterion->fresh(['rank', 'conditions']), 'run' => $run];
        });
    }

    /** @return array{criterion: RankCriterion, run: ?RankEvaluationRun, changed: bool} */
    public function update(RankCriterion $criterion, array $payload, User $actor): array
    {
        $reason = $payload['reason'] ?? null;

        return DB::transaction(function () use ($criterion, $payload, $actor, $reason) {
            $this->lockRank($criterion->investor_rank_id);
            $criterion = RankCriterion::with(['rank', 'conditions'])->lockForUpdate()->findOrFail($criterion->id);

            $before = $this->audit->criterionSnapshot($criterion);
            $n = $this->validateOrFail(
                array_merge($payload, ['investor_rank_id' => $criterion->investor_rank_id]),
                $criterion->id,
                $criterion->is_active,
            );

            $rulesChanged = $before['logic'] !== $n['logic']
                || $this->canon($before['conditions']) !== $this->canon($n['conditions']);
            $metaChanged  = $before['name'] !== $n['name'] || ($before['description'] ?: null) !== ($n['description'] ?: null);

            if (!$rulesChanged && !$metaChanged) {
                return ['criterion' => $criterion, 'run' => null, 'changed' => false];
            }

            $criterion->forceFill([
                'name' => $n['name'], 'description' => $n['description'] ?: null, 'logic' => $n['logic'],
                'version' => $criterion->version + 1, 'updated_by' => $actor->id,
            ])->save();
            if ($rulesChanged) $this->syncConditions($criterion, $n['conditions']);
            $criterion->load(['rank', 'conditions']);

            $this->audit->recordCriteria('updated', $criterion, $actor, $before, $this->audit->criterionSnapshot($criterion), $reason);

            // Edited rules that are LIVE may now promote investors → re-evaluate.
            $run = ($criterion->is_active && $rulesChanged)
                ? $this->evaluation->startRun('criteria_change', $criterion, $actor)
                : null;

            return ['criterion' => $criterion, 'run' => $run, 'changed' => true];
        });
    }

    /** @return array{criterion: RankCriterion, run: ?RankEvaluationRun} */
    public function activate(RankCriterion $criterion, User $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($criterion, $actor, $reason) {
            $this->lockRank($criterion->investor_rank_id);
            $criterion = RankCriterion::with(['rank', 'conditions'])->lockForUpdate()->findOrFail($criterion->id);

            if ($criterion->is_active) {
                return ['criterion' => $criterion, 'run' => null];
            }

            $this->validateOrFail([
                'investor_rank_id' => $criterion->investor_rank_id, 'name' => $criterion->name,
                'description' => $criterion->description, 'logic' => $criterion->logic,
                'conditions' => $criterion->toRule()['conditions'],
            ], $criterion->id, true);

            $run = $this->activateInternal($criterion, $actor, $reason);

            return ['criterion' => $criterion->fresh(['rank', 'conditions']), 'run' => $run];
        });
    }

    public function deactivate(RankCriterion $criterion, User $actor, ?string $reason = null): RankCriterion
    {
        return DB::transaction(function () use ($criterion, $actor, $reason) {
            $this->lockRank($criterion->investor_rank_id);
            $criterion = RankCriterion::with(['rank', 'conditions'])->lockForUpdate()->findOrFail($criterion->id);

            if (!$criterion->is_active) return $criterion;

            $before = $this->audit->criterionSnapshot($criterion);
            $criterion->forceFill([
                'is_active' => false, 'deactivated_at' => now(),
                'version' => $criterion->version + 1, 'updated_by' => $actor->id,
            ])->save();

            $this->audit->recordCriteria('deactivated', $criterion, $actor, $before, $this->audit->criterionSnapshot($criterion), $reason);

            // Deliberately NO re-evaluation and NO rank change: switching a rule
            // off never removes anyone's existing rank.
            return $criterion;
        });
    }

    /** "Remove" = archive. The rule and its full history are retained for audit. */
    public function archive(RankCriterion $criterion, User $actor, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => ['A reason of at least 5 characters is required to remove criteria.']]);
        }

        DB::transaction(function () use ($criterion, $actor, $reason) {
            $this->lockRank($criterion->investor_rank_id);
            $criterion = RankCriterion::with(['rank', 'conditions'])->lockForUpdate()->findOrFail($criterion->id);

            $before = $this->audit->criterionSnapshot($criterion);
            $criterion->forceFill([
                'is_active' => false, 'deactivated_at' => now(),
                'version' => $criterion->version + 1, 'updated_by' => $actor->id,
            ])->save();
            $this->audit->recordCriteria('archived', $criterion, $actor, $before, $this->audit->criterionSnapshot($criterion), $reason);
            $criterion->delete(); // soft delete
        });
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /** Make $criterion the single active rule for its rank (replacing any other) and re-evaluate. */
    protected function activateInternal(RankCriterion $criterion, User $actor, ?string $reason): ?RankEvaluationRun
    {
        $others = RankCriterion::with(['rank', 'conditions'])->where('is_active', true)
            ->where('investor_rank_id', $criterion->investor_rank_id)
            ->where('id', '!=', $criterion->id)->lockForUpdate()->get();

        foreach ($others as $old) {
            $before = $this->audit->criterionSnapshot($old);
            $old->forceFill([
                'is_active' => false, 'deactivated_at' => now(),
                'version' => $old->version + 1, 'updated_by' => $actor->id,
            ])->save();
            $this->audit->recordCriteria('deactivated', $old, $actor, $before, $this->audit->criterionSnapshot($old),
                "Replaced by \"{$criterion->name}\"" . ($reason ? " — $reason" : ''));
        }

        $before = $this->audit->criterionSnapshot($criterion);
        $criterion->forceFill([
            'is_active' => true, 'activated_at' => now(), 'deactivated_at' => null,
            'version' => $criterion->version + 1, 'updated_by' => $actor->id,
        ])->save();
        $criterion->load(['rank', 'conditions']);
        $this->audit->recordCriteria('activated', $criterion, $actor, $before, $this->audit->criterionSnapshot($criterion), $reason);

        return $this->evaluation->startRun('criteria_change', $criterion, $actor);
    }

    protected function lockRank(int $rankId): void
    {
        InvestorRank::query()->whereKey($rankId)->lockForUpdate()->first();
    }

    /** @return array normalized payload; throws ValidationException with readable messages */
    protected function validateOrFail(array $payload, ?int $ignoreId, bool $asActive): array
    {
        $result = $this->validator->validate(array_merge($payload, ['is_active' => $asActive]), $ignoreId);

        if (!$result['valid']) {
            $messages = $result['errors'];
            if ($result['conflicts']) {
                $messages['conflicts'] = array_column($result['conflicts'], 'message');
            }
            throw ValidationException::withMessages($messages);
        }

        return $result['normalized'];
    }

    protected function syncConditions(RankCriterion $criterion, array $conditions): void
    {
        $criterion->conditions()->delete();
        foreach ($conditions as $i => $c) {
            $criterion->conditions()->create([
                'group_no' => $c['group_no'], 'field' => $c['field'],
                'operator' => $c['operator'], 'value' => $c['value'], 'position' => $i,
            ]);
        }
    }

    /** Order/type-insensitive form for "did the rules actually change?" */
    protected function canon(array $conditions): string
    {
        $norm = array_map(fn ($c) => [
            (int) ($c['group_no'] ?? 1), $c['field'], $c['operator'],
            is_array($c['value']) ? array_map(fn ($v) => is_numeric($v) ? (string) (float) $v : (string) $v, $c['value'])
                : (is_numeric($c['value']) ? (string) (float) $c['value'] : json_encode($c['value'])),
        ], $conditions);
        usort($norm, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        return json_encode($norm);
    }
}
