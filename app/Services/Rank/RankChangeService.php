<?php
// LOCATION: app/Services/Rank/RankChangeService.php
//
// MANUAL rank decisions by an authorized Admin: assign, upgrade, downgrade,
// authorized override, and "retain" decisions on downgrade reviews.
// Every path takes a row lock, validates against the configured criteria,
// requires a reason, and writes the audit record in the same transaction.

namespace App\Services\Rank;

use App\Models\InvestorRank;
use App\Models\InvestorRankChange;
use App\Models\InvestorRankHold;
use App\Models\RankCriterion;
use App\Models\RankDowngradeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RankChangeService
{
    public function __construct(
        protected RankEvaluationService $evaluation,
        protected RankAuditService $audit,
        protected RankEligibility $eligibility,
    ) {}

    public function applyManual(
        User $investor,
        InvestorRank $target,
        User $admin,
        string $reason,
        bool $override = false,
        ?string $overrideJustification = null,
    ): InvestorRankChange {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => ['A reason of at least 5 characters is required.']]);
        }

        return DB::transaction(function () use ($investor, $target, $admin, $reason, $override, $overrideJustification) {
            $user = User::query()->where('role', 'investor')->whereKey($investor->id)->lockForUpdate()->firstOrFail();

            $current  = $user->investor_rank_id ? InvestorRank::find($user->investor_rank_id) : null;
            $curLevel = $current?->level ?? 0;

            if ($current && $current->id === $target->id) {
                throw ValidationException::withMessages(['rank_id' => ["The investor already holds the {$target->name} rank."]]);
            }

            $isDowngrade = $target->level < $curLevel;
            $snapshot = null; $needsOverride = false; $shortfall = null; $shortfallCriterion = null;
            $holdBlocked = [];

            if (!$isDowngrade) {
                // Assigning / raising: the investor must pass the compliance PREREQUISITES (active,
                // verified) and satisfy the TARGET rank's active criteria. Anything unmet makes this
                // an exception to the rules → override policy applies, never a silent bypass.
                $prereq    = $this->eligibility->check($user);
                $criterion = $this->activeCriterion($target->id);
                $res       = $criterion ? $this->evaluation->evaluateRuleFor($user->id, $criterion->toRule()) : null;

                $snapshot = ['prerequisites' => $prereq]
                    + ($criterion ? ['criterion' => $this->audit->criterionSnapshot($criterion), 'evaluation' => $res] : []);

                $unmet = $this->evaluation->shortfall($prereq, $res);
                if ($unmet) { $needsOverride = true; $shortfall = $unmet; $shortfallCriterion = $criterion; }
            } elseif ($current) {
                // Lowering: record how the investor stands against the CURRENT rank's criteria.
                $criterion = $this->activeCriterion($current->id);
                if ($criterion) {
                    $res = $this->evaluation->evaluateRuleFor($user->id, $criterion->toRule());
                    $snapshot = ['criterion' => $this->audit->criterionSnapshot($criterion), 'evaluation' => $res];
                    // Lowering someone who STILL qualifies is an exception to the rules (the
                    // engine would otherwise re-upgrade them), so it needs the override path.
                    if ($res['satisfied']) $needsOverride = true;
                }
                // Every rank above the target (up to what they held) that they STILL qualify for
                // is held, so the next automatic evaluation cannot undo this decision.
                $holdBlocked = $this->qualifyingRanksToHold($user->id, $target->level, $curLevel);
                if ($holdBlocked) $needsOverride = true;   // leaving them below a rank they qualify for = exception
            }

            if ($holdBlocked) {
                // Self-explaining audit record: which ranks the system was told NOT to restore.
                $snapshot = ($snapshot ?? []) + ['held_ranks' => $holdBlocked];
            }

            if ($needsOverride) {
                $heldNames = collect($holdBlocked)->map(fn ($h) => InvestorRank::find($h['rank_id'])?->name)->filter()->implode(', ');
                $this->assertOverrideAllowed($admin, $override, $overrideJustification, $isDowngrade, $target, $shortfall, $heldNames);
            }

            $type = $needsOverride
                ? InvestorRankChange::AUTHORIZED_OVERRIDE
                : ($isDowngrade ? InvestorRankChange::DOWNGRADE
                    : ($current ? InvestorRankChange::MANUAL_UPGRADE : InvestorRankChange::MANUAL_ASSIGNMENT));

            DB::table('users')->where('id', $user->id)->update(['investor_rank_id' => $target->id, 'rank_changed_at' => now()]);

            $change = $this->audit->recordChange(
                $user, $current?->id, $target->id, $type, $admin, $reason,
                $snapshot, $needsOverride ? trim((string) $overrideJustification) : null,
            );

            // This Admin decision supersedes any earlier hold; a new one protects a downgrade
            // of an investor who still qualifies (empty for ordinary downgrades → no row).
            InvestorRankHold::query()->active()->where('investor_id', $user->id)->update([
                'status' => 'released', 'released_at' => now(),
                'release_reason' => $isDowngrade ? 'Replaced by a newer manual downgrade.' : 'Admin set the rank manually.',
                'updated_at' => now(),
            ]);
            if ($isDowngrade && $holdBlocked) {
                InvestorRankHold::create([
                    'investor_id' => $user->id, 'investor_rank_change_id' => $change->id,
                    'blocked' => $holdBlocked, 'status' => 'active',
                ]);
            }

            // Any open review about the OLD rank is now decided.
            RankDowngradeReview::open()->where('investor_id', $user->id)->update([
                'status' => $isDowngrade ? 'downgraded' : 'cleared',
                'resolved_by' => $admin->id, 'resolved_by_name' => $admin->name ?? $admin->full_name,
                'resolution_reason' => $reason, 'resolved_at' => now(), 'updated_at' => now(),
            ]);

            if ($shortfall) {
                $this->evaluation->acknowledgeShortfall($user, $target->id, $shortfallCriterion, $shortfall, $admin, 'Authorized override: ' . $overrideJustification);
            }

            return $change;
        });
    }

    /** Admin decides to KEEP the current rank despite a failed-criteria review. */
    public function retainReview(RankDowngradeReview $review, User $admin, string $reason): InvestorRankChange
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => ['A reason of at least 5 characters is required.']]);
        }

        return DB::transaction(function () use ($review, $admin, $reason) {
            $review = RankDowngradeReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($review->status !== 'open') {
                throw ValidationException::withMessages(['review' => ['This review has already been decided.']]);
            }

            $user = User::whereKey($review->investor_id)->lockForUpdate()->firstOrFail();
            if ((int) $user->investor_rank_id !== (int) $review->investor_rank_id) {
                $review->update(['status' => 'cleared', 'resolved_at' => now(), 'resolution_reason' => 'Rank changed since this review was raised.']);
                throw ValidationException::withMessages(['review' => ['The investor\'s rank has changed since this review was raised; it has been closed.']]);
            }

            $review->update([
                'status' => 'retained', 'resolved_by' => $admin->id,
                'resolved_by_name' => $admin->name ?? $admin->full_name,
                'resolution_reason' => $reason, 'resolved_at' => now(),
            ]);

            // Decision record; the rank itself is unchanged (previous == new).
            return $this->audit->recordChange(
                $user, $user->investor_rank_id, $user->investor_rank_id, InvestorRankChange::REVIEW_RETAINED,
                $admin, $reason, ['failed_conditions' => $review->failed_conditions, 'review_id' => $review->id],
            );
        });
    }

    /** @return array<int, array{rank_id:int, level:int, rule_hash:string}> */
    protected function qualifyingRanksToHold(int $userId, int $targetLevel, int $previousLevel): array
    {
        $held = [];
        $criteria = RankCriterion::where('is_active', true)->with(['rank', 'conditions'])->get()
            ->filter(fn ($c) => $c->rank->level > $targetLevel && $c->rank->level <= $previousLevel && $this->evaluation->usable($c));

        foreach ($criteria as $c) {
            if ($this->evaluation->evaluateRuleFor($userId, $c->toRule())['satisfied']) {
                $held[] = ['rank_id' => $c->investor_rank_id, 'level' => $c->rank->level, 'rule_hash' => $c->ruleHash()];
            }
        }
        return $held;
    }

    protected function activeCriterion(int $rankId): ?RankCriterion
    {
        $criterion = RankCriterion::where('is_active', true)->where('investor_rank_id', $rankId)->with(['rank', 'conditions'])->first();

        return $criterion && $this->evaluation->usable($criterion) ? $criterion : null;
    }

    protected function assertOverrideAllowed(User $admin, bool $override, ?string $justification, bool $isDowngrade, InvestorRank $target, ?array $shortfall, string $heldNames = ''): void
    {
        // $shortfall is the list of unmet requirements (failed prerequisites first, then criteria).
        $failedText = $shortfall ? collect($shortfall)->pluck('text')->implode('; ') : null;

        if (!config('ranks.allow_manual_override')) {
            $why = $isDowngrade
                ? 'This investor still satisfies the active criteria for ' . ($heldNames ?: 'a higher rank') . '. Lowering them is an exception to the configured rules. Manual exceptions are disabled.'
                : "The investor does not currently meet the requirements for {$target->name}" . ($failedText ? " ($failedText)" : '') . '. Manual exceptions to the configured rules are disabled.';
            throw ValidationException::withMessages(['override' => [$why]]);
        }

        Gate::forUser($admin)->authorize('ranks.override');

        if (!$override) {
            throw ValidationException::withMessages(['override' => [
                'This change is an exception to the configured requirements' . ($failedText ? " (not met: $failedText)" : '')
                . '. Confirm the override and provide a justification.',
            ]]);
        }
        if (mb_strlen(trim((string) $justification)) < 10) {
            throw ValidationException::withMessages(['override_justification' => ['Provide an override justification of at least 10 characters.']]);
        }
    }
}
