<?php
// LOCATION: app/Jobs/SendRankChangeNotificationJob.php
// Dispatched only AFTER the rank change has committed.

namespace App\Jobs;

use App\Services\Rank\RankNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendRankChangeNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $changeId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(RankNotifier $notifier): void
    {
        $notifier->deliverChange($this->changeId);
    }

    public function failed(Throwable $e): void
    {
        app(RankNotifier::class)->markChangeFailed($this->changeId, $e);
    }
}
