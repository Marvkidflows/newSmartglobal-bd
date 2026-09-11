<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CountdownUpdatedNotification extends Notification
{
    public function __construct(protected string $message) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return ['message' => $this->message, 'type' => 'countdown_update'];
    }
}