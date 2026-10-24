<?php
// LOCATION: app/Services/Rank/RankNotifier.php
//
// Delivers rank alerts to Admin users through the existing notifications table.
// Safe to call repeatedly: per-admin notification ids are deterministic UUIDv5
// values, so a retry can only fill in admins who have not been notified yet.

namespace App\Services\Rank;

use App\Models\InvestorRankChange;
use App\Models\RankChangeNotification;
use App\Models\RankDowngradeReview;
use App\Models\User;
use App\Models\RankReview;
use App\Notifications\InvestorRankNotification;
use App\Notifications\RankDowngradeReviewNotification;
use App\Notifications\RankUpgradedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

class RankNotifier
{
    public function __construct(protected RankRuleEvaluator $evaluator) {}

    public function admins()
    {
        return User::query()->where('role', 'admin')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->get();
    }

    /** Admin + Financial Team members who may approve a pending rank. */
    public function reviewers()
    {
        return User::query()->whereIn('role', ['admin', 'financial'])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->get();
    }

    /** Tell reviewers a calculated eligibility is waiting for a decision. Safe to repeat (deterministic ids). */
    public function deliverPendingReview(int $reviewId): void
    {
        $review = RankReview::with(['proposedRank', 'currentRank'])->find($reviewId);
        if (!$review || $review->status !== RankReview::PENDING) return;

        $investor = User::find($review->investor_id);
        $name = $investor?->name ?? $investor?->full_name ?? 'An investor';
        $payload = [
            'type'          => 'rank_review_pending',
            'title'         => 'Rank approval required',
            'message'       => sprintf('%s (%s) is calculated as eligible for %s (from %s). Awaiting approval — no rank has been awarded.',
                                       $name, $review->account_id, $review->proposedRank?->name, $review->currentRank?->name ?? 'Unranked'),
            'investor_id'   => $review->investor_id, 'account_id' => $review->account_id, 'investor_name' => $name,
            'proposed_rank' => $review->proposedRank?->name, 'current_rank' => $review->currentRank?->name,
            'review_id'     => $review->id, 'reference' => $review->reference,
            'occurred_at'   => $review->created_at?->toIso8601String(),
            'link'          => '/admin/ranks/approvals',
        ];

        foreach ($this->reviewers() as $reviewer) {
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "ssi-rank-approval:{$reviewId}:{$reviewer->id}")->toString();
            if (DatabaseNotification::query()->whereKey($id)->exists()) continue;
            $reviewer->notify(new RankDowngradeReviewNotification($id, $payload));   // same generic database-notification carrier
        }

        \Illuminate\Support\Facades\DB::table('rank_reviews')->where('id', $reviewId)->update(['notified_at' => now()]);   // bookkeeping only
    }

    /** Tell the INVESTOR their rank was officially awarded / upgraded / changed / suspended / revoked / reinstated. */
    public function deliverInvestor(int $changeId): void
    {
        $change = InvestorRankChange::with(['previousRank', 'newRank'])->find($changeId);
        if (!$change || $change->change_type === InvestorRankChange::REVIEW_RETAINED) return;

        $investor = User::find($change->investor_id);
        if (!$investor || $investor->role !== 'investor') return;

        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "ssi-rank-investor:{$changeId}")->toString();
        if (DatabaseNotification::query()->whereKey($id)->exists()) return;          // already delivered

        $to = $change->newRank?->name; $from = $change->previousRank?->name;
        [$event, $title, $message] = match ($change->change_type) {
            InvestorRankChange::SUSPENDED  => ['suspended',  "Your {$to} rank badge has been suspended",  "Your {$to} rank is temporarily suspended. Contact SSI support if you have questions."],
            InvestorRankChange::REVOKED    => ['revoked',    "Your {$to} rank has been revoked",         "Your {$to} rank has been withdrawn by SSI. Contact SSI support if you have questions."],
            InvestorRankChange::REINSTATED => ['reinstated', "Your {$to} rank has been reinstated",      "Your {$to} rank is active again."],
            default => match (true) {
                !$from                                                   => ['awarded',  "You have been awarded the {$to} rank",   "Congratulations! SSI has officially awarded you the {$to} rank."],
                ($change->newRank?->level ?? 0) > ($change->previousRank?->level ?? 0) => ['upgraded', "You have been upgraded to {$to}", "Congratulations! Your investor rank has been upgraded from {$from} to {$to}."],
                default                                                  => ['changed',  "Your investor rank is now {$to}",        "Your investor rank has changed from {$from} to {$to}."],
            },
        };

        $investor->notify(new InvestorRankNotification($id, [
            'type' => 'rank_update', 'event' => $event, 'title' => $title, 'message' => $message,
            'previous_rank' => $from, 'new_rank' => $to, 'reference' => $change->reference,
            'occurred_at' => $change->created_at?->toIso8601String(), 'link' => '/investor/rank',
        ]));
    }

    public function deliverChange(int $changeId): void
    {
        $delivery = RankChangeNotification::firstOrCreate(['investor_rank_change_id' => $changeId], ['status' => 'pending']);
        if ($delivery->status === 'sent') return;

        $change = InvestorRankChange::with(['previousRank', 'newRank'])->find($changeId);
        if (!$change) {
            $delivery->update(['status' => 'failed', 'last_error' => 'Rank change record not found.']);
            return;
        }

        $admins = $this->admins();
        if ($admins->isEmpty()) {
            $delivery->update(['status' => 'failed', 'attempts' => $delivery->attempts + 1, 'last_error' => 'No active Admin recipients.']);
            return;
        }

        try {
            $payload = $this->changePayload($change);
            foreach ($admins as $admin) {
                $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "ssi-rank-change:{$changeId}:{$admin->id}")->toString();
                if (DatabaseNotification::query()->whereKey($id)->exists()) continue; // already delivered
                $admin->notify(new RankUpgradedNotification($id, $payload));
            }
            $delivery->update(['status' => 'sent', 'attempts' => $delivery->attempts + 1, 'last_error' => null, 'sent_at' => now()]);
        } catch (Throwable $e) {
            $delivery->update(['status' => 'failed', 'attempts' => $delivery->attempts + 1, 'last_error' => Str::limit($e->getMessage(), 500)]);
            throw $e; // let the queue retry with backoff
        }
    }

    public function markChangeFailed(int $changeId, Throwable $e): void
    {
        RankChangeNotification::updateOrCreate(
            ['investor_rank_change_id' => $changeId],
            ['status' => 'failed', 'last_error' => Str::limit($e->getMessage(), 500)]
        );
    }

    public function deliverReview(int $reviewId): void
    {
        $review = RankDowngradeReview::with('rank')->find($reviewId);
        if (!$review || $review->notified_at || $review->status !== 'open') return;

        $investor = User::find($review->investor_id);
        $payload = [
            'type'           => 'rank_downgrade_review',
            'title'          => 'Rank review required',
            'message'        => sprintf('%s (%s) no longer meets the criteria for %s. No change has been made — please review.',
                                    $investor?->name ?? $investor?->full_name ?? 'An investor', $investor?->account_id, $review->rank?->name),
            'investor_id'    => $review->investor_id,
            'account_id'     => $investor?->account_id,
            'investor_name'  => $investor?->name ?? $investor?->full_name,
            'current_rank'   => $review->rank?->name,
            'criteria_not_met' => collect($review->failed_conditions)->pluck('text')->all(),
            'automatic'      => true,
            'occurred_at'    => now()->toIso8601String(),
            'review_id'      => $review->id,
            'link'           => "/admin/ranks/investors/{$review->investor_id}",
        ];

        foreach ($this->admins() as $admin) {
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "ssi-rank-review:{$reviewId}:{$admin->id}")->toString();
            if (DatabaseNotification::query()->whereKey($id)->exists()) continue;
            $admin->notify(new RankDowngradeReviewNotification($id, $payload));
        }

        $review->update(['notified_at' => now()]);
    }

    /** No balances/amounts: only identity, ranks, and the RULE that was satisfied. */
    protected function changePayload(InvestorRankChange $change): array
    {
        $investor  = User::find($change->investor_id);
        $snap      = $change->criteria_snapshot ?? [];
        $criterion = $snap['criterion'] ?? [];
        $name      = $investor?->name ?? $investor?->full_name ?? 'Investor';
        $from      = $change->previousRank?->name ?? 'Unranked';
        $to        = $change->newRank?->name;

        $satisfied = collect($snap['evaluation']['conditions'] ?? [])->pluck('text')->all();

        return [
            'type'               => 'rank_upgrade',
            'title'              => "Investor upgraded to {$to}",
            'message'            => "{$name} ({$change->account_id}) was automatically upgraded from {$from} to {$to}.",
            'investor_id'        => $change->investor_id,
            'investor_name'      => $name,
            'account_id'         => $change->account_id,
            'previous_rank'      => $from,
            'new_rank'           => $to,
            'reason'             => $criterion
                ? "Satisfied the active {$to} criteria \"{$criterion['name']}\" (v{$criterion['version']})."
                : 'Satisfied the active rank criteria.',
            'criteria_satisfied' => $satisfied,
            'automatic'          => (bool) $change->is_automatic,
            'occurred_at'        => $change->created_at?->toIso8601String(),
            'rank_change_id'     => $change->id,
            'reference'          => $change->reference,
            'link'               => "/admin/ranks/investors/{$change->investor_id}",
        ];
    }
}
