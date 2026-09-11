<?php
// LOCATION: app/Notifications/AccountStatusNotification.php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;

class AccountStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $action,
        protected string $message
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'    => 'account_status',
            'action'  => $this->action,
            'message' => $this->message,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $subjects = [
            'suspended'  => 'Your Account Has Been Suspended',
            'frozen'     => 'Your Account Has Been Frozen',
            'activated'  => 'Your Account Has Been Reactivated',
            'unfrozen'   => 'Your Account Freeze Has Been Lifted',
        ];

        $subject = $subjects[$this->action] ?? 'Account Status Update';

        return (new MailMessage)
            ->mailer('brevo')
            ->subject($subject)
            ->greeting('Hello ' . ($notifiable->name ?? '') . ',')
            ->line($this->message)
            ->line('If you have questions, please contact our support team.')
            ->action('Contact Support', config('app.frontend_url') . '/investor/messages')
            ->line('Thank you for using Smart System Investment.');
    }
}