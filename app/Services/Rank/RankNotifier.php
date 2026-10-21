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
