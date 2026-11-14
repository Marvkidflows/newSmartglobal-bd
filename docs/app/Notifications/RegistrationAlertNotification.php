<?php
// LOCATION: app/Notifications/RegistrationAlertNotification.php
// Task 2 — in-app ('database' channel) alert for Registration Team members. Same pattern as FinancialAlertNotification /
// MarvflowAlertNotification: read through the shared NotificationController. Carries NO applicant contact details — only a title,
// a neutral message and (where relevant) the application id for deep-linking.

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RegistrationAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $title,
        protected string $message,
        protected string $type = 'registration',
        protected array $data = [],
    ) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return array_merge(['title' => $this->title, 'message' => $this->message, 'type' => $this->type], $this->data);
    }
}
