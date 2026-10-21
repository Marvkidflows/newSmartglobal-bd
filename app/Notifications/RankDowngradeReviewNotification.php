<?php
// LOCATION: app/Notifications/RankDowngradeReviewNotification.php
// Admin alert: an investor no longer meets their current rank's criteria.
// Informational only — nothing has been changed; Admin decides.

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class RankDowngradeReviewNotification extends Notification
{
    public function __construct(string $id, protected array $payload)
    {
        $this->id = $id;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return $this->payload;
    }
}
