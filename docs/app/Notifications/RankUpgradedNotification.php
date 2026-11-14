<?php
// LOCATION: app/Notifications/RankUpgradedNotification.php
//
// Admin alert for an AUTOMATIC rank upgrade. Stored in Laravel's existing
// `notifications` table (database channel) so it shows up in the same notification
// centre every other role already uses. The id is supplied by the caller and is
// deterministic per (rank change, admin): the primary key itself makes a duplicate
// delivery impossible.
//
// Deliberately carries NO balances or amounts — only the rule that was met.

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class RankUpgradedNotification extends Notification
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
