<?php
// LOCATION: app/Jobs/SendMarvflowStaffContactTelegram.php
//
// Telegram alert to the MarvFlow team's own chat (TELEGRAM_MARVFLOW_CHAT_ID) whenever SSI staff (Financial Team / Admin) CONTACT MarvFlow
// by replying: a reply on a team request, or a reply to a MarvFlow-started message. Dispatched only AFTER the reply is saved, on the
// existing queue. It carries plain strings (no model), and never the message body: only who, where and the subject.
// A Telegram failure can never affect the reply (the service never throws; the dispatch sites also catch).

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMarvflowStaffContactTelegram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(
        protected string $source,        // e.g. "Request #12" or "Admin inbox: email"
        protected string $senderName,
        protected string $senderRole,    // financial | admin
        protected ?string $subject = null
    ) {}

    public function handle(TelegramService $telegram): void
    {
        $telegram->marvflowStaffContact($this->source, $this->senderName, $this->senderRole, $this->subject);
    }
}
