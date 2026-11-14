<?php
// LOCATION: app/Jobs/SendRankProgressNoticesJob.php
// Processes one chunk of investors for "approaching the next rank" notices. Each investor is handled on its own: a failure for one is logged
// and the rest of the chunk still runs. The work is idempotent (see RankProgressNoticeService), so a retry or a duplicate dispatch can never
// send a second notice, and the next daily sweep picks up anything that failed.

namespace App\Jobs;

use App\Models\User;
use App\Services\Rank\RankProgressNoticeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendRankProgressNoticesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param int[] $userIds */
    public function __construct(public array $userIds) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(RankProgressNoticeService $notices): void
    {
        foreach ($this->userIds as $id) {
            try {
                $user = User::find($id);
                if ($user) $notices->process($user);
            } catch (Throwable $e) {
                Log::warning('Rank progress notice failed for one investor; continuing.', ['investor_id' => $id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Rank progress notice job failed.', ['investors' => count($this->userIds), 'error' => $e->getMessage()]);
    }
}
