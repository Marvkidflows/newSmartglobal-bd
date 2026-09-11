<?php
// LOCATION: app/Notifications/TaskActivatedNotification.php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use App\Models\TaskAssignment;

class TaskActivatedNotification extends Notification
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
            'title'              => 'Task Activated',
            'message'            => "Your task \"{$task->title}\" ({$task->task_code}) is now active. Please monitor your dashboard for the outcome.",
            'type'               => 'task_activated',
            'task_id'            => $task->id,
            'task_assignment_id' => $this->assignment->id,
            'task_code'          => $task->task_code,
        ];
    }
}
