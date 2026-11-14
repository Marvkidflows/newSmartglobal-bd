<?php
// LOCATION: app/Notifications/MarvflowAlertNotification.php
//
// MarvFlow Team Dashboard — in-app notification for MarvFlow team
// members (new request, reply from SSI, reassignment). Same pattern as
// FinancialAlertNotification: 'database' channel only, written to
// Laravel's built-in notifications table via the Notifiable trait
// already on User — so MarvFlow team members see these through the
// exact same NotificationController/endpoint every other role uses.
// This is separate from and in addition to the Telegram alert; Telegram
// is a passive "something arrived" ping, this is what actually shows up
// in-app under /marvflow/notifications.

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MarvflowAlertNotification extends Notification
{
    use Queueable;

    protected string $title;
    protected string $message;
    protected string $type;
    protected array $data;

    public function __construct(string $title, string $message, string $type = 'marvflow', array $data = [])
    {
        $this->title   = $title;
        $this->message = $message;
        $this->type    = $type;
        $this->data    = $data;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return array_merge([
            'title'   => $this->title,
            'message' => $this->message,
            'type'    => $this->type,
        ], $this->data);
    }
}
