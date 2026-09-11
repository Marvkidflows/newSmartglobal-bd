<?php
// LOCATION: app/Notifications/EmailReceivedNotification.php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Bus\Queueable;

class EmailReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected int $sentEmailId,
        protected string $subject
    ) {}

    // Database only — the actual email itself was already sent separately via Brevo.
    // This just powers the in-app bell/notification dropdown.
    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'         => 'email_received',
            'sent_email_id'=> $this->sentEmailId,
            'subject'      => $this->subject,
            'message'      => 'You have received a new email: "' . $this->subject . '"',
        ];
    }
}