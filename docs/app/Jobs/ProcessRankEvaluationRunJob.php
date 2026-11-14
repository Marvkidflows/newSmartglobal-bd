<?php
// LOCATION: app/Jobs/ProcessRankEvaluationRunJob.php
//
// Processes ONE chunk of a rank re-evaluation run, then re-queues itself until the
// run is exhausted. Progress lives in rank_evaluation_runs.cursor_user_id (advanced
// with compare-and-set), so retries are safe and a long run never blocks a request.

namespace App\Jobs;

use App\Models\RankEvaluationRun;
use App\Services\Rank\RankEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class ProcessRankEvaluationRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $runId) {}

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(RankEvaluationService $service): void
    {
        $run = RankEvaluationRun::find($this->runId);
        if (!$run || $run->isFinished()) return;

        if ($service->processNextChunk($run)) {
            self::dispatch($this->runId);
        }
    }

    public function failed(Throwable $e): void
    {
        RankEvaluationRun::where('id', $this->runId)
            ->whereNotIn('status', ['completed', 'completed_with_errors', 'failed'])
            ->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500), 'finished_at' => now()]);
    }
}
