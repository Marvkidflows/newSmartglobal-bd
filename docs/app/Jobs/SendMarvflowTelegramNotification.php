<?php
// LOCATION: app/Jobs/SendMarvflowTelegramNotification.php
//
// MarvFlow Team Dashboard — dispatched AFTER a team_request is already
// saved to the database (see DevRequestController::store()), never
// before. This is what guarantees the required order:
//
//   validate -> save request -> dispatch this job -> Telegram sent
//
// Runs on the existing database queue (QUEUE_CONNECTION=database,
// processed by the queue:work process already running) rather than
// sending synchronously inline in the controller — per the spec's
// explicit instruction to use the existing queue system for this. If
// this job fails (Telegram API down, chat ID misconfigured, etc.), the
// team_request row is completely unaffected — it was already committed
// before this was ever dispatched.

namespace App\Jobs;

use App\Models\TeamRequest;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMarvflowTelegramNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        protected int $teamRequestId
    ) {}

    public function handle(TelegramService $telegram): void
    {
        $request = TeamRequest::with('sender')->find($this->teamRequestId);

        // The request may have been deleted between dispatch and this
        // job running (shouldn't happen in normal use, but a queued job
        // running later than the request it refers to is always a
        // possibility) — nothing to notify about if so, and nothing to
        // retry either.
        if (!$request) {
            Log::warning('MarvFlow Telegram job skipped: team_request no longer exists.', [
                'team_request_id' => $this->teamRequestId,
            ]);
            return;
        }

        $telegram->newMarvflowRequest(
            $request->id,
            $request->sender->name ?? 'Unknown Sender',
            $request->priority,
            $request->subject,
            $request->message,
            $request->status
        );
    }
}
