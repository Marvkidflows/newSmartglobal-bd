<?php
// LOCATION: app/Notifications/TaskWindowUpdatedNotification.php
// Covers: window extended/reduced/set-date, and "approaching expiration"
// alerts — all share the same simple database-channel shape.

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class TaskWindowUpdatedNotification extends Notification
{
    public function __construct(protected string $message, protected string $taskCode, protected int $taskId) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'title'     => 'Task Update',
            'message'   => $this->message,
            'type'      => 'task_window_update',
            'task_id'   => $this->taskId,
            'task_code' => $this->taskCode,
        ];
    }
}
