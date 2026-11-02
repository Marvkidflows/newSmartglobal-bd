<?php
// LOCATION: app/Notifications/MarvflowMessageNotification.php
// Bell notification for a MarvFlow message or reply. Carries the SUBJECT only — never the message body.
// `thread_id` (the first message of the conversation) lets the app collapse repeated alerts for one conversation.
namespace App\Notifications;

use Illuminate\Notifications\Notification;

class MarvflowMessageNotification extends Notification
{
    public function __construct(protected string $title, protected string $message, protected int $messageId, protected string $link, protected ?int $threadId = null) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'type' => 'marvflow_message', 'message_id' => $this->messageId, 'thread_id' => $this->threadId ?? $this->messageId, 'link' => $this->link];
    }
}
