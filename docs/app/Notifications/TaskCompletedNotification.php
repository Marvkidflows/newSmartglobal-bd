<?php
// LOCATION: app/Notifications/TaskCompletedNotification.php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use App\Models\TaskAssignment;

class TaskCompletedNotification extends Notification
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
            'title'              => 'Task Completed',
            'message'            => "Your task \"{$task->title}\" ({$task->task_code}) has been completed. Result: " . ($this->assignment->final_result ?? 'see task details') . '.',
            'type'               => 'task_completed',
            'task_id'            => $task->id,
            'task_assignment_id' => $this->assignment->id,
            'task_code'          => $task->task_code,
        ];
    }
}
