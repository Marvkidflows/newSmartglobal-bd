<?php
// LOCATION: app/Notifications/FinancialAlertNotification.php
//
// Financial Team dashboard — in-app notification for new deposit/
// withdrawal requests. Uses the exact same pattern as the app's other
// Notification classes (TaskAssignedNotification, etc.): 'database'
// channel only, written to Laravel's built-in notifications table via
// the Notifiable trait already on User. This means financial team
// members see these through the SAME NotificationController/endpoint
// investors already use — no separate notification system needed.

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FinancialAlertNotification extends Notification
{
    use Queueable;

    protected string $title;
    protected string $message;
    protected string $type;
    protected array $data;

    public function __construct(string $title, string $message, string $type = 'financial', array $data = [])
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
