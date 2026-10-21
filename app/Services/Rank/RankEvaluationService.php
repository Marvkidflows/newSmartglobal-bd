<?php
// LOCATION: app/Services/Rank/RankEvaluationService.php
//
// The rank engine. Rules of the road (all deliberate):
//  • UPGRADE ONLY. This class can raise a rank; it can never lower one.
//  • The investor row is locked (SELECT … FOR UPDATE) for the whole decision, the
//    decision is re-derived from the CURRENT rank under that lock, and a rank is
//    only written if it is strictly higher — so a stale/parallel evaluation can
//    never overwrite a newer rank.
//  • (investor_id, sequence) is UNIQUE on the audit table, so even a missed lock
//    cannot produce two transitions from the same state.
//  • Read-only with respect to money: it reads investments/deposits, writes only
//    rank columns, audit rows and notification state. It never touches balances.

namespace App\Services\Rank;

use App\Jobs\ProcessRankEvaluationRunJob;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RankEvaluationService
{
    protected ?Collection $rankCache = null;

    public function __construct(
        protected RankFieldRegistry $registry,
        protected RankRuleEvaluator $evaluator,
        protected RankAuditService $audit,
        protected RankEligibility $eligibility,
    ) {}

    // ── Single investor ───────────────────────────────────────────────────────

    /** @return Collection<int, RankCriterion> active criteria, highest rank first */
    public function activeCriteria(): Collection
    {
        return RankCriterion::query()->where('is_active', true)->with(['rank', 'conditions'])->get()
            ->filter(fn ($c) => $this->usable($c))
            ->sortByDesc(fn ($c) => $c->rank->level)->values();
    }

    /**
     * While the currency is unconfirmed, a criterion that tests a money amount is NOT used — even if
     * it somehow became active — so no rank is ever granted on amounts of unknown currency.
     */
    public function usable(RankCriterion $criterion): bool
    {
        if (config('ranks.currency_confirmed')) return true;

        $criterion->loadMissing('conditions');
        return !$criterion->conditions->contains(fn ($c) => $this->registry->isMonetary($c->field));
    }

    /**
     * Evaluate one investor and apply a qualifying UPGRADE.
     * Idempotent: repeating it with unchanged data returns null and writes nothing.
     */
    public function evaluateUser(int $userId, string $trigger = 'event', ?RankEvaluationRun $run = null, ?Collection $criteria = null): ?InvestorRankChange
    {
        $criteria ??= $this->activeCriteria();

        try {
            return DB::transaction(fn () => $this->evaluateLocked($userId, $trigger, $run, $criteria));
        } catch (QueryException $e) {
            // Lost a race to an identical transition: the other writer already
            // recorded it, so there is nothing left to do.
            if ($this->isDuplicateKey($e)) return null;
            throw $e;
        }
    }

    protected function evaluateLocked(int $userId, string $trigger, ?RankEvaluationRun $run, Collection $criteria): ?InvestorRankChange
    {
        $user = User::query()->where('role', 'investor')->whereKey($userId)->lockForUpdate()->first();
        if (!$user) return null;

        $ranks        = $this->ranks();
        $currentLevel = $user->investor_rank_id ? ($ranks[$user->investor_rank_id]->level ?? 0) : 0;

        $fields = $criteria->flatMap(fn ($c) => $c->conditions->pluck('field'))->unique()->values()->all();
        $values = $this->registry->resolveMany([$user->id], $fields)[$user->id] ?? [];

        // Compliance prerequisites (active account, verified). While one fails, NOTHING is
        // granted automatically — but nothing is taken away either (see syncDowngradeReview).
        $eligibility = $this->eligibility->check($user);

        // A manual downgrade may carry a hold on ranks the investor STILL qualified for
        // (an authorized exception). Automatic evaluation must not silently restore them.
        $hold    = InvestorRankHold::query()->active()->where('investor_id', $user->id)->latest('id')->first();
        $blocked = $hold ? collect($hold->blocked)->keyBy('rank_id') : collect();
        $holdChanged = false;

        $target = null; $targetResult = null;
        foreach (($eligibility["eligible"] ? $criteria : []) as $criterion) {   // highest rank first → first hit is the highest qualifying
            if ($criterion->rank->level <= $currentLevel) break;
            $result = $this->evaluator->evaluate($criterion->toRule(), $values);

            $entry = $blocked->get($criterion->investor_rank_id);
            if ($entry) {
                if ($result['satisfied'] && $entry['rule_hash'] === $criterion->ruleHash()) {
                    continue;                          // same rule, still qualifies → Admin's decision stands
                }
                // Eligibility legitimately changed (no longer qualifies, or the rule itself changed).
                $blocked->forget($criterion->investor_rank_id);
                $holdChanged = true;
            }

            if ($result['satisfied']) { $target = $criterion; $targetResult = $result; break; }
        }

        $change = null;
        if ($target) {
            $change = $this->applyAutomaticUpgrade($user, $target, $targetResult, $run);
            if ($hold) { $this->releaseHold($hold, 'Superseded by an automatic upgrade.'); $hold = null; }
        }

        if ($hold) {
            // Entries whose rank no longer has an active rule have no basis left → drop them.
            $activeRankIds = $criteria->pluck('investor_rank_id');
            $pruned = $blocked->filter(fn ($e) => $activeRankIds->contains($e['rank_id']));
            if ($pruned->count() !== $blocked->count()) { $blocked = $pruned; $holdChanged = true; }

            if ($blocked->isEmpty()) {
                $this->releaseHold($hold, 'Investor eligibility changed.');
            } elseif ($holdChanged) {
                $hold->update(['blocked' => $blocked->values()->all()]);
            }
        }

        $this->syncDowngradeReview($user, $criteria, $values, $eligibility);

        return $change;
    }

    protected function applyAutomaticUpgrade(User $user, RankCriterion $criterion, array $result, ?RankEvaluationRun $run): InvestorRankChange
    {
        $previousId = $user->investor_rank_id;
        $rank       = $criterion->rank;

        $snapshot = [
            'criterion'  => $this->audit->criterionSnapshot($criterion),
            'evaluation' => $result,
            'evaluated_at' => now()->toIso8601String(),
        ];

        // Query-builder write: no model events, no updated_at churn.
        DB::table('users')->where('id', $user->id)->update([
            'investor_rank_id' => $criterion->investor_rank_id,
            'rank_changed_at'  => now(),
        ]);
        $user->setRawAttributes(array_merge($user->getAttributes(), ['investor_rank_id' => $criterion->investor_rank_id]), true);

        $from   = $previousId ? $this->ranks()[$previousId]->name : 'Unranked';
        $detail = collect($result['conditions'])->map(fn ($c) => $c['text'] . ' (actual: '
            . $this->evaluator->formatValue($this->registry->get($c['field'])['type'] ?? null, $c['actual'], $this->registry->get($c['field'])['unit'] ?? null) . ')')->implode('; ');
        $reason = "Automatically upgraded from {$from} to {$rank->name}. Satisfied active criteria \"{$criterion->name}\" (v{$criterion->version}): {$detail}.";

        $change = $this->audit->recordChange(
            $user, $previousId, $criterion->investor_rank_id, InvestorRankChange::AUTOMATIC_UPGRADE,
            null, $reason, $snapshot, null, $run?->id,
        );

        RankChangeNotification::create(['investor_rank_change_id' => $change->id, 'status' => 'pending']);

        // Only after the whole transaction has COMMITTED. A delivery failure can
        // never roll the upgrade back; it is tracked and retryable.
        DB::afterCommit(fn () => SendRankChangeNotificationJob::dispatch($change->id));

        return $change;
    }

    protected function releaseHold(InvestorRankHold $hold, string $reason): void
    {
        $hold->update(['status' => 'released', 'released_at' => now(), 'release_reason' => $reason]);
    }

    // ── Downgrade review (never changes a rank) ───────────────────────────────

    protected function syncDowngradeReview(User $user, Collection $criteria, array $values, array $eligibility): void
    {
        if (!$user->investor_rank_id) return;

        // Shortfall = failed prerequisites + unmet conditions of the CURRENT rank's active rule.
        $criterion = $criteria->firstWhere('investor_rank_id', $user->investor_rank_id);
        $result    = $criterion ? $this->evaluator->evaluate($criterion->toRule(), $values) : null;
        $failed    = $this->shortfall($eligibility, $result);

        if (!$failed) {
            RankDowngradeReview::open()->where('investor_id', $user->id)->update([
                'status' => 'cleared', 'resolved_at' => now(),
                'resolution_reason' => 'Investor meets the prerequisites and the active criteria for the current rank again.',
                'updated_at' => now(),
            ]);
            return;
        }

        $fingerprint = $this->fingerprint($user->investor_rank_id, $criterion, $failed);

        if (RankDowngradeReview::where('investor_id', $user->id)->where('fingerprint', $fingerprint)->exists()) {
            return; // same unchanged shortfall already raised (open OR already decided) → no repeat alert
        }

        try {
            $review = DB::transaction(fn () => RankDowngradeReview::create([
                'investor_id' => $user->id, 'investor_rank_id' => $user->investor_rank_id,
                'rank_criterion_id' => $criterion?->id ?? 0, 'criterion_version' => $criterion?->version ?? 0,
                'fingerprint' => $fingerprint, 'failed_conditions' => $failed, 'status' => 'open',
            ]));
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) return;
            throw $e;
        }

        DB::afterCommit(fn () => SendDowngradeReviewNotificationJob::dispatch($review->id));
    }

    /** Everything that currently disqualifies the investor from the rank: prerequisites first, then criteria. */
    public function shortfall(array $eligibility, ?array $result): array
    {
        return array_merge(
            $eligibility['failures'] ?? [],
            $result ? collect($result['conditions'])->where('passed', false)->values()->all() : []
        );
    }

    /** Identity of "this investor misses THESE requirements of THIS criterion version". */
    public function fingerprint(int $rankId, ?RankCriterion $criterion, array $failed): string
    {
        return sha1($rankId . '|' . ($criterion?->id ?? 0) . '|' . ($criterion?->version ?? 0) . '|'
            . json_encode(collect($failed)->map(fn ($f) => [$f['field'], $f['operator'], $f['expected']])->all()));
    }

    /**
     * An Admin knowingly placed (or kept) an investor on a rank they do not currently
     * meet. Record that shortfall as already decided so the engine does not raise a
     * downgrade alert for the very thing Admin just approved.
     */
    public function acknowledgeShortfall(User $user, int $rankId, ?RankCriterion $criterion, array $failed, User $admin, string $reason): void
    {
        if (!$failed) return;

        RankDowngradeReview::firstOrCreate(
            ['investor_id' => $user->id, 'fingerprint' => $this->fingerprint($rankId, $criterion, $failed)],
            [
                'investor_rank_id' => $rankId, 'rank_criterion_id' => $criterion?->id ?? 0,
                'criterion_version' => $criterion?->version ?? 0, 'failed_conditions' => $failed,
                'status' => 'retained', 'resolved_by' => $admin->id,
                'resolved_by_name' => $admin->name ?? $admin->full_name, 'resolution_reason' => $reason,
                'resolved_at' => now(), 'notified_at' => now(),
            ]
        );
    }

    // ── Evaluating ONE criterion for ONE investor (manual-change checks, detail view) ──

    public function evaluateRuleFor(int $userId, array $rule): array
    {
        $fields = array_values(array_unique(array_column($rule['conditions'] ?? [], 'field')));
        $values = $this->registry->resolveMany([$userId], $fields)[$userId] ?? [];
        return $this->evaluator->evaluate($rule, $values);
    }

    // ── Batched / queued runs ─────────────────────────────────────────────────

    /** Investors that could possibly be upgraded by a criterion for $targetLevel. */
    public function scopeQuery(?int $targetLevel = null, bool $includeCurrentHolders = false): Builder
    {
        $q = User::query()->where('role', 'investor');
        if ($targetLevel) {
            // A criteria change can also affect investors who ALREADY hold that rank (a stricter rule
            // must flag them for review), so those runs include the rank's current holders.
            $below = InvestorRank::where('level', $includeCurrentHolders ? '<=' : '<', $targetLevel)->pluck('id');
            $q->where(fn ($w) => $w->whereNull('investor_rank_id')->orWhereIn('investor_rank_id', $below));
        }
        return $q;
    }

    public function startRun(string $trigger, ?RankCriterion $criterion = null, ?User $actor = null): RankEvaluationRun
    {
        $criterion?->loadMissing('rank');
        $level = $criterion?->rank?->level;
        $total = $this->scopeQuery($level, $trigger === 'criteria_change')->count();

        $run = RankEvaluationRun::create([
            'reference'         => 'ER-' . strtoupper(Str::random(8)),
            'trigger'           => $trigger,
            'investor_rank_id'  => $criterion?->investor_rank_id,
            'target_level'      => $level,
            'rank_criterion_id' => $criterion?->id,
            'triggered_by'      => $actor?->id,
            'status'            => 'pending',
            'total'             => $total,
            'heartbeat_at'      => now(),
        ]);

        if ($total === 0) {
            $run->update(['status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
            return $run;
        }

        DB::afterCommit(fn () => ProcessRankEvaluationRunJob::dispatch($run->id));

        return $run;
    }

    /**
     * Process one chunk of a run. Returns true when more work remains.
     * The cursor is advanced with compare-and-set, so a retried/duplicated job
     * can never double count or skip investors.
     */
    public function processNextChunk(RankEvaluationRun $run): bool
    {
        $run->refresh();
        if ($run->isFinished()) return false;

        if ($run->status === 'pending') {
            $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);
        }

        $cursor = (int) $run->cursor_user_id;
        $ids = $this->scopeQuery($run->target_level, $run->trigger === 'criteria_change')->where('id', '>', $cursor)
            ->orderBy('id')->limit(max(1, (int) config('ranks.chunk_size', 200)))->pluck('id');

        if ($ids->isEmpty()) {
            $this->finalize($run);
            return false;
        }

        $criteria = $this->activeCriteria();
        $processed = 0; $upgraded = 0; $failed = 0; $failures = [];

        foreach ($ids as $id) {
            try {
                if ($this->evaluateUser((int) $id, 'run', $run, $criteria)) $upgraded++;
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                $failures[] = ['investor_id' => (int) $id, 'error' => Str::limit($e->getMessage(), 200)];
                report($e);
            }
        }

        $merged = array_slice(array_merge($run->failures ?? [], $failures), 0, 50);

        RankEvaluationRun::where('id', $run->id)->where('cursor_user_id', $cursor)->update([
            'cursor_user_id' => $ids->last(),
            'processed'      => DB::raw('processed + ' . (int) $processed),
            'upgraded'       => DB::raw('upgraded + ' . (int) $upgraded),
            'failed'         => DB::raw('failed + ' . (int) $failed),
            'failures'       => $merged ? json_encode($merged) : null,
            'heartbeat_at'   => now(),
        ]);

        return true;
    }

    protected function finalize(RankEvaluationRun $run): void
    {
        $run->refresh();
        $run->update([
            'status'      => $run->failed > 0 ? 'completed_with_errors' : 'completed',
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    // ── Dry run for the Admin UI ──────────────────────────────────────────────

    /**
     * Who WOULD meet this (possibly unsaved) rule today? Bounded by
     * ranks.preview_limit so it can never become an unbounded scan.
     */
    public function preview(array $rule, InvestorRank $rank): array
    {
        $limit  = max(1, (int) config('ranks.preview_limit', 2000));
        $fields = array_values(array_unique(array_column($rule['conditions'], 'field')));
        $scope  = $this->scopeQuery($rank->level);
        $inScope = (clone $scope)->count();

        $examined = 0; $qualify = 0; $sample = [];

        (clone $scope)->with('investorRank')->select('id', 'name', 'full_name', 'email', 'investor_rank_id')
            ->chunkById(200, function ($users) use (&$examined, &$qualify, &$sample, $limit, $rule, $fields) {
                $users = $users->take($limit - $examined);
                $values = $this->registry->resolveMany($users->pluck('id')->all(), $fields);
                foreach ($users as $u) {
                    $examined++;
                    if ($this->evaluator->evaluate($rule, $values[$u->id] ?? [])['satisfied']) {
                        $qualify++;
                        if (count($sample) < 25) {
                            $sample[] = [
                                'id' => $u->id, 'account_id' => $u->account_id,
                                'name' => $u->name ?? $u->full_name, 'email' => $u->email,
                                'current_rank' => $u->investorRank?->badge(),
                            ];
                        }
                    }
                }
                return $examined < $limit;
            });

        return [
            'rank'          => $rank->badge(),
            'in_scope'      => $inScope,
            'examined'      => $examined,
            'would_qualify' => $qualify,
            'truncated'     => $inScope > $examined,
            'sample'        => $sample,
        ];
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    protected function ranks(): Collection
    {
        return $this->rankCache ??= InvestorRank::all()->keyBy('id');
    }

    // True ONLY for unique-constraint violations. SQLSTATE 23000 alone is not
    // enough: on MySQL it also covers foreign-key failures, which must surface.
    protected function isDuplicateKey(QueryException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $msg = strtolower($e->getMessage());

        return $driverCode === 1062                       // MySQL/MariaDB duplicate entry
            || (string) $e->getCode() === '23505'         // PostgreSQL unique_violation
            || str_contains($msg, 'unique constraint failed')  // SQLite
            || str_contains($msg, 'duplicate entry');
    }
}
