<?php
// LOCATION: app/Jobs/SendBulkEmailJob.php

namespace App\Jobs;

use App\Mail\AdminCustomEmail;
use App\Models\SentEmail;
use App\Models\User;
use App\Notifications\EmailReceivedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendBulkEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        protected int $sentEmailId
    ) {}

    public function handle(): void
    {
        $sentEmail = SentEmail::find($this->sentEmailId);
        if (!$sentEmail) {
            return;
        }

        $investor = User::find($sentEmail->investor_id);
        if (!$investor) {
            $sentEmail->update(['status' => 'failed', 'error_message' => 'Investor no longer exists.']);
            return;
        }

        try {
            Mail::mailer('brevo')
                ->to($sentEmail->recipient_email)
                ->send(new AdminCustomEmail(
                    $sentEmail->subject,
                    $sentEmail->body_html,
                    $sentEmail->attachment_path,
                    $sentEmail->attachment_name
                ));

            $sentEmail->update(['status' => 'sent', 'sent_at' => now()]);

            $investor->notify(new EmailReceivedNotification($sentEmail->id, $sentEmail->subject));
        } catch (\Throwable $e) {
            $sentEmail->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }
}