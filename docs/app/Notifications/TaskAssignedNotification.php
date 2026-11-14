<?php
// LOCATION: app/Notifications/TaskAssignedNotification.php
// Follows the existing CountdownUpdatedNotification pattern exactly —
// database channel only, via Laravel's built-in notifications table.

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use App\Models\TaskAssignment;

class TaskAssignedNotification extends Notification
{
    public function __construct(protected TaskAssignment $assignment) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $task = $this->assignment->task;
        return [
            'title'                => 'New Task Assigned',
            'message'              => "A new task \"{$task->title}\" has been assigned to you. Task code: {$task->task_code}.",
            'type'                 => 'task_assigned',
            'task_id'              => $task->id,
            'task_assignment_id'   => $this->assignment->id,
            'task_code'            => $task->task_code,
        ];
    }
}
