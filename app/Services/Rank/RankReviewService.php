<?php
// LOCATION: app/Services/Rank/RankReviewService.php
//
// Separates CALCULATED eligibility from AUTHORIZED rank assignment (task §2).
//
//   engine calculates ──► pending review ──► Admin / Financial Team approves ──► rank assigned + audited + investor notified
//                                      └──► rejected (reason recorded; not re-raised for the same rules)
//                                      └──► withdrawn by the engine (investor no longer eligible / a different rank now applies)
//
//  • The engine (syncPending) can ONLY create or withdraw pending reviews. It can never award a rank here.
//  • approve() re-validates eligibility under a row lock, so a stale review can never grant a rank the investor
//    no longer qualifies for. Authorisation is checked here AND at the route (Gate ranks.review).
//  • Writes only: users.investor_rank_id / rank_changed_at, rank audit tables, notifications. Never money.

namespace App\Services\Rank;

use App\Jobs\SendInvestorRankNotificationJob;
use App\Jobs\SendRankReviewNotificationJob;
use App\Models\InvestorRank;
use App\Models\InvestorRankChange;
use App\Models\InvestorRankHold;
use App\Models\RankCriterion;
use App\Models\RankDowngradeReview;
use App\Models\RankEvaluationRun;
use App\Models\RankReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RankReviewService
{
    public function __construct(
        protected RankEvaluationService $evaluation,
        protected RankAuditService $audit,
        protected RankEligibility $eligibility,
        protected RankAdditionalCriteriaService $additional,
    ) {}

    // ── Called by the engine (inside its per-investor transaction) ────────────

    public function syncPending(User $user, ?RankCriterion $target, ?array $result, ?RankEvaluationRun $run): void
    {
        $pending = RankReview::pending()->where('investor_id', $user->id)->get();

        foreach ($pending as $p) {
            if ($target && (int) $p->proposed_rank_id === (int) $target->investor_rank_id) continue;
            $this->withdraw($p, $target
                ? 'Superseded: the investor now qualifies for a different rank.'
                : 'No longer eligible: the investor does not currently meet the requirements for this rank.');
        }

        if (!$target) return;

        $same = $pending->first(fn ($p) => (int) $p->proposed_rank_id === (int) $target->investor_rank_id);
        $snapshot = $this->snapshot($target, $result);
        $basis    = $this->basisHash($target);

        if ($same) {
            // Already waiting for a decision: no new review and NO new alert. Keep everything that describes the
            // evidence in step with it, so the review never claims rules/run it was not evaluated under:
            //   • evaluation         – the calculation the reviewer will read;
            //   • rank_criterion_id / basis_hash – the rules that calculation used. basis_hash is what makes a later
            //     REJECTION stand "under these exact rules"; a stale hash would let the engine re-raise a review
            //     the reviewer has just rejected whenever the criteria changed while it was pending;
            //   • evaluation_run_id – the run that produced it (kept as-is when this evaluation has no run).
            // Identity (id, reference, status, pending_key, created_at) and notified_at are deliberately untouched.
            $same->forceFill([
                'evaluation'        => $snapshot,
                'rank_criterion_id' => $target->id,
                'basis_hash'        => $basis,
                'evaluation_run_id' => $run?->id ?? $same->evaluation_run_id,
            ])->save();
            return;
        }

        $rejectedSameRules = RankReview::where('investor_id', $user->id)->where('proposed_rank_id', $target->investor_rank_id)
            ->where('status', RankReview::REJECTED)->where('basis_hash', $basis)->exists();
        if ($rejectedSameRules) return;                  // a human already said no under these exact rules

        $review = RankReview::forceCreate([
            'reference'         => 'RR-' . strtoupper(\Illuminate\Support\Str::random(10)),
            'investor_id'       => $user->id,
            'account_id'        => $user->account_id,
            'current_rank_id'   => $user->investor_rank_id,
            'proposed_rank_id'  => $target->investor_rank_id,
            'status'            => RankReview::PENDING,
            'pending_key'       => $user->id . ':' . $target->investor_rank_id,
            'basis_hash'        => $basis,
            'rank_criterion_id' => $target->id,
            'evaluation'        => $snapshot,
            'evaluation_run_id' => $run?->id,
        ]);

        DB::afterCommit(fn () => SendRankReviewNotificationJob::dispatch($review->id));
    }

    // ── Human decisions ──────────────────────────────────────────────────────

    public function approve(RankReview $review, User $actor, ?string $note = null): RankReview
    {
        Gate::forUser($actor)->authorize('ranks.review');
        $note = $note !== null ? trim($note) : null;

        return DB::transaction(function () use ($review, $actor, $note) {
            $review = RankReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($review->status !== RankReview::PENDING) {
                throw ValidationException::withMessages(['review' => ['This review has already been decided.']]);
            }

            $user = User::query()->where('role', 'investor')->whereKey($review->investor_id)->lockForUpdate()->firstOrFail();
            if (($user->rank_status ?? 'active') !== 'active') {
                throw ValidationException::withMessages(['review' => ["This investor's rank is {$user->rank_status}. Reinstate it before approving a new rank."]]);
            }

            $proposed = InvestorRank::findOrFail($review->proposed_rank_id);
            $current  = $user->investor_rank_id ? InvestorRank::find($user->investor_rank_id) : null;
            if ($current && $current->level >= $proposed->level) {
                throw ValidationException::withMessages(['review' => ["The investor already holds {$current->name}; this review is out of date."]]);
            }

            // Re-validate against TODAY's data, under the lock — never approve on a stale calculation.
            $criterion = RankCriterion::where('is_active', true)->where('investor_rank_id', $proposed->id)->with(['rank', 'conditions'])->first();
            $usable    = $criterion && $this->evaluation->usable($criterion);
            $prereq    = $this->eligibility->check($user);
            $result    = $usable ? $this->evaluation->evaluateRuleFor($user->id, $criterion->toRule(), $proposed->id) : null;

            if (!$usable) {
                throw ValidationException::withMessages(['review' => ["There is no active requirement set for {$proposed->name}, so it cannot be approved."]]);
            }
            $unmet = $this->evaluation->shortfall($prereq, $result);
            if ($unmet) {
                throw ValidationException::withMessages(['review' => [
                    "The investor no longer meets the requirements for {$proposed->name}: " . collect($unmet)->pluck('text')->implode('; '),
                ]]);
            }

            $from   = $current?->name ?? 'Unranked';
            $reason = ($note ?: "Calculated eligibility confirmed.") . " Approved {$from} → {$proposed->name} (review {$review->reference}).";

            DB::table('users')->where('id', $user->id)->update(['investor_rank_id' => $proposed->id, 'rank_changed_at' => now()]);

            $change = $this->audit->recordChange(
                $user, $current?->id, $proposed->id, InvestorRankChange::APPROVED_UPGRADE, $actor, $reason,
                ['review_id' => $review->id, 'review_reference' => $review->reference, 'prerequisites' => $prereq,
                 'criterion' => $this->audit->criterionSnapshot($criterion), 'evaluation' => $result,
                 'proposed_evaluation' => $review->evaluation],
                null, $review->evaluation_run_id,
            );

            // This approval supersedes any older hold and settles any open review about the old rank.
            InvestorRankHold::query()->active()->where('investor_id', $user->id)->update([
                'status' => 'released', 'released_at' => now(), 'release_reason' => 'Superseded by an approved rank.', 'updated_at' => now(),
            ]);
            RankDowngradeReview::open()->where('investor_id', $user->id)->update([
                'status' => 'cleared', 'resolved_by' => $actor->id, 'resolved_by_name' => $actor->name ?? $actor->full_name,
                'resolution_reason' => 'A higher rank was approved.', 'resolved_at' => now(), 'updated_at' => now(),
            ]);

            $review->forceFill([
                'status' => RankReview::APPROVED, 'pending_key' => null, 'decided_by' => $actor->id,
                'decided_by_name' => $actor->name ?? $actor->full_name ?? 'Reviewer', 'decided_by_role' => $actor->role,
                'decision_reason' => $note ?: 'Approved.', 'decided_at' => now(), 'rank_change_id' => $change->id,
            ])->save();

            DB::afterCommit(fn () => SendInvestorRankNotificationJob::dispatch($change->id));

            return $review->fresh();
        });
    }

    public function reject(RankReview $review, User $actor, string $reason): RankReview
    {
        Gate::forUser($actor)->authorize('ranks.review');
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => ['A reason of at least 5 characters is required.']]);
        }

        return DB::transaction(function () use ($review, $actor, $reason) {
            $review = RankReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($review->status !== RankReview::PENDING) {
                throw ValidationException::withMessages(['review' => ['This review has already been decided.']]);
            }

            $review->forceFill([
                'status' => RankReview::REJECTED, 'pending_key' => null, 'decided_by' => $actor->id,
                'decided_by_name' => $actor->name ?? $actor->full_name ?? 'Reviewer', 'decided_by_role' => $actor->role,
                'decision_reason' => $reason, 'decided_at' => now(),
            ])->save();

            return $review->fresh();
        });
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    public function present(RankReview $r): array
    {
        $r->loadMissing(['investor', 'currentRank', 'proposedRank']);
        $conds = collect($r->evaluation['evaluation']['conditions'] ?? []);

        return [
            'id' => $r->id, 'reference' => $r->reference, 'status' => $r->status,
            'investor_id' => $r->investor_id, 'account_id' => $r->account_id,
            'investor_name' => $r->investor?->name ?? $r->investor?->full_name,
            'current_rank' => $r->currentRank?->badge(), 'proposed_rank' => $r->proposedRank?->badge(),
            'criteria_met' => $conds->map(fn ($c) => [
                'label' => $c['label'] ?? $c['field'], 'text' => $c['text'] ?? '', 'expected' => $c['expected'] ?? null,
                'actual' => $c['actual'] ?? null, 'passed' => (bool) ($c['passed'] ?? false), 'additional' => (bool) ($c['additional'] ?? false),
            ])->values()->all(),
            'decided_by_name' => $r->decided_by_name, 'decided_by_role' => $r->decided_by_role,
            'decision_reason' => $r->decision_reason, 'decided_at' => $r->decided_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    protected function withdraw(RankReview $p, string $why): void
    {
        $p->forceFill([
            'status' => RankReview::WITHDRAWN, 'pending_key' => null, 'decided_by_name' => RankAuditService::SYSTEM_ACTOR_NAME,
            'decided_by_role' => 'system', 'decision_reason' => $why, 'decided_at' => now(),
        ])->save();
    }

    protected function snapshot(RankCriterion $c, ?array $result): array
    {
        return ['criterion' => $this->audit->criterionSnapshot($c), 'evaluation' => $result, 'evaluated_at' => now()->toIso8601String()];
    }

    /** Identity of "these rules for this rank" — a rejection stands until the rules themselves change. */
    protected function basisHash(RankCriterion $c): string
    {
        return sha1($c->investor_rank_id . '|' . $c->id . '|' . $c->version . '|' . $c->ruleHash() . '|' . $this->additional->signature($c->investor_rank_id));
    }
}