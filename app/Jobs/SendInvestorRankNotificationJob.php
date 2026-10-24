<?php
// LOCATION: app/Jobs/SendInvestorRankNotificationJob.php
// Dispatched only AFTER the originating transaction has committed. Idempotent (deterministic notification ids).

namespace App\Jobs;

use App\Services\Rank\RankNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendInvestorRankNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $id) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(RankNotifier $notifier): void
    {
        $notifier->deliverInvestor($this->id);
    }
}
