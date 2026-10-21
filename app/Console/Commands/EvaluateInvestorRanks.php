<?php
// LOCATION: app/Console/Commands/EvaluateInvestorRanks.php
//
//   php artisan ranks:evaluate                 queue a full re-evaluation run (manual trigger)
//   php artisan ranks:evaluate --scheduled     the daily sweep (skips if a run is already active / no active criteria)
//   php artisan ranks:evaluate --user=123      evaluate one investor immediately
//   php artisan ranks:evaluate --retry-notifications   re-send failed/stuck admin alerts
//
// The sweep exists for time-based criteria (e.g. account age) that change without
// any event. It is chunked and queued — never an unbounded in-process loop.

namespace App\Console\Commands;

use App\Models\RankChangeNotification;
use App\Models\RankDowngradeReview;
use App\Models\RankEvaluationRun;
use App\Jobs\SendDowngradeReviewNotificationJob;
use App\Jobs\SendRankChangeNotificationJob;
use App\Services\Rank\RankEvaluationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EvaluateInvestorRanks extends Command
{
    protected $signature = 'ranks:evaluate {--scheduled} {--user=} {--retry-notifications}';
    protected $description = 'Evaluate investor ranks against the active Admin-configured criteria (upgrades only).';

    public function handle(RankEvaluationService $service): int
    {
        if ($this->option('retry-notifications')) {
            $stale = now()->subMinutes((int) config('ranks.notification_stale_minutes', 10));
            $changes = RankChangeNotification::where(fn ($q) => $q->where('status', 'failed')
                ->orWhere(fn ($q2) => $q2->where('status', 'pending')->where('created_at', '<', $stale)))->pluck('investor_rank_change_id');
            foreach ($changes as $id) SendRankChangeNotificationJob::dispatch($id);

            $reviews = RankDowngradeReview::open()->whereNull('notified_at')->where('created_at', '<', $stale)->pluck('id');
            foreach ($reviews as $id) SendDowngradeReviewNotificationJob::dispatch($id);

            $this->info("Re-queued {$changes->count()} rank alert(s) and {$reviews->count()} review alert(s).");
            Log::info('ranks:evaluate --retry-notifications', ['rank_alerts' => $changes->count(), 'review_alerts' => $reviews->count()]);
            return self::SUCCESS;
        }

        if ($uid = $this->option('user')) {
            $change = $service->evaluateUser((int) $uid, 'manual');
            $this->info($change ? "Upgraded: {$change->reference}" : 'No change.');
            return self::SUCCESS;
        }

        if ($service->activeCriteria()->isEmpty()) {
            $this->info('No active rank criteria — nothing to evaluate.');
            Log::info('ranks:evaluate sweep skipped', ['reason' => 'no active criteria', 'scheduled' => (bool) $this->option('scheduled')]);
            return self::SUCCESS;
        }

        if ($this->option('scheduled')
            && RankEvaluationRun::whereIn('status', ['pending', 'running'])->where('heartbeat_at', '>', now()->subMinutes(30))->exists()) {
            $this->info('A run is already in progress — skipping.');
            Log::info('ranks:evaluate sweep skipped', ['reason' => 'run already in progress']);
            return self::SUCCESS;
        }

        $run = $service->startRun($this->option('scheduled') ? 'scheduled' : 'manual');
        $this->info("Queued run {$run->reference} for {$run->total} investor(s). Make sure a queue worker is running.");
        // Operators: grep storage/logs for this line to confirm the daily sweep fired.
        Log::info('ranks:evaluate sweep queued', ['run' => $run->reference, 'investors' => $run->total, 'scheduled' => (bool) $this->option('scheduled')]);

        return self::SUCCESS;
    }
}
