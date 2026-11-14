<?php
// LOCATION: app/Notifications/InvestorRankNotification.php
//
// Sent to the INVESTOR when their rank is officially awarded, upgraded, changed, suspended, revoked or reinstated.
// Stored in the existing `notifications` table (database channel). The id is supplied by the caller and is
// deterministic per rank-change record, so a retry can never produce a second notification.
// Deliberately carries no balances, thresholds, internal reasons or administrator identity.

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class InvestorRankNotification extends Notification
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
