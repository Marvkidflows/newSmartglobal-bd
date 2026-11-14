<?php
// LOCATION: app/Notifications/RegistrationApplicantNotification.php
// Task 2 — in-app notice to the APPLICANT about the outcome of their review (additional information needed, approved, rejected).
// Service correspondence about the applicant's own application; not promotional.

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RegistrationApplicantNotification extends Notification
{
    use Queueable;

    public function __construct(protected string $title, protected string $message, protected string $type = 'kyc_status') {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return ['title' => $this->title, 'message' => $this->message, 'type' => $this->type];
    }
}
