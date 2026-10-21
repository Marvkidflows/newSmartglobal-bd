<?php
// LOCATION: app/Jobs/EvaluateInvestorRankJob.php
//
// Event-driven evaluation of ONE investor after data that can affect rank changed
// (investment, approved deposit, KYC). Unique per investor UNTIL PROCESSING STARTS: a burst of changes
// collapses into one queued evaluation, but a change arriving while an evaluation is already running
// queues a fresh one instead of being dropped (it may have missed that run's data).

namespace App\Jobs;

use App\Services\Rank\RankEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateInvestorRankJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $uniqueFor = 15;

    public function __construct(public int $userId, public string $trigger = 'event') {}

    public function uniqueId(): string
    {
        return 'rank-eval-' . $this->userId;
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(RankEvaluationService $service): void
    {
        $service->evaluateUser($this->userId, $this->trigger);
    }
}
