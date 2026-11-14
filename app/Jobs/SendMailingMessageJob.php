<?php
// TASK 3 — sends one mail_messages row. All duplicate/consent/eligibility protection lives in MessageSender; this job only handles retry timing.
namespace App\Jobs;

use App\Services\Mailing\MessageSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMailingMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;          // upper bound for worker-level retries; the per-message cap is config('mailing.max_attempts')
    public int $timeout = 60;

    public function __construct(public int $messageId) {}

    public function handle(MessageSender $sender): void
    {
        if ($sender->send($this->messageId) === 'retry' && $this->job) {
            $this->release(60);
        }
    }
}
