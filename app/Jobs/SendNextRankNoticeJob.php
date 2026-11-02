<?php
// LOCATION: app/Jobs/SendNextRankNoticeJob.php
// Bronze → Silver eligibility notice. Dispatched only AFTER the review's transaction has committed. Retries like the other rank
// notifications, and is idempotent: RankBenefitService::notifyNextRankEligibility uses a deterministic notification id, so a retry
// or a duplicate dispatch can never send a second notice. It sends nothing unless management has enabled the benefit.

namespace App\Jobs;

use App\Models\RankReview;
use App\Services\Rank\RankBenefitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNextRankNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $reviewId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(RankBenefitService $benefits): void
    {
        $review = RankReview::find($this->reviewId);
        if (!$review || $review->status !== RankReview::PENDING) return;   // decided or withdrawn in the meantime: nothing to announce

        $benefits->notifyNextRankEligibility($review);
    }
}
