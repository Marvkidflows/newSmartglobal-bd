<?php
// LOCATION: app/Mail/AdminCustomEmail.php
//
// BUGFIX: this class previously `implements ShouldQueue`. That made
// Mail::send($this) silently just enqueue the job instead of actually
// sending — so AdminEmailController::dispatchEmail() (the single/Compose
// send path) marked the email "sent" and fired the investor's in-app
// notification immediately, before Brevo had ever been contacted. With
// QUEUE_CONNECTION=database and no running `php artisan queue:work`
// worker, that queued job never ran — investor saw the notification,
// never got the real email.
//
// Bulk sending is unaffected by this fix and stays properly async: it
// already queues at the SendBulkEmailJob level (see that class), which
// itself calls Mail::send() on this mailable from inside an already-
// queued job's handle() — so removing ShouldQueue here just means "send
// for real once the job runs" instead of "queue a second time".

namespace App\Mail;

use Illuminate\Mail\Mailable;

class AdminCustomEmail extends Mailable
{
    public function __construct(
        public string $emailSubject,
        public string $bodyHtml,
        public ?string $attachmentPath = null,
        public ?string $attachmentName = null,
    ) {}

    public function build()
    {
        $mail = $this->subject($this->emailSubject)
            ->view('emails.admin-custom')
            ->with([
                'subject'  => $this->emailSubject,
                'bodyHtml' => $this->bodyHtml,
            ]);

        if ($this->attachmentPath && file_exists($this->attachmentPath)) {
            $mail->attach($this->attachmentPath, [
                'as' => $this->attachmentName ?? basename($this->attachmentPath),
            ]);
        }

        return $mail;
    }
}