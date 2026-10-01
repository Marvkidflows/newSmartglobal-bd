<?php
// LOCATION: app/Services/MarvflowNotificationService.php
//
// MarvFlow Team Dashboard — mirrors FinancialNotificationService's
// pattern exactly, but fans out to marvflow_member/marvflow_lead users
// only (never admin/financial — see MarvflowMiddleware for why MarvFlow
// is kept separate from SSI's own staff hierarchy throughout this
// feature).

namespace App\Services;

use App\Models\User;
use App\Notifications\MarvflowAlertNotification;

class MarvflowNotificationService
{
    /**
     * @param array $data Extra fields merged into the notification's
     *                     stored data (e.g. ['team_request_id' => $id])
     *                     so the frontend can deep-link to the request.
     */
    public function notifyTeam(string $title, string $message, string $type = 'marvflow', array $data = []): void
    {
        $notification = new MarvflowAlertNotification($title, $message, $type, $data);

        User::whereIn('role', ['marvflow_member', 'marvflow_lead'])
            ->get()
            ->each(fn (User $user) => $user->notify($notification));
    }

    public function newRequest(int $requestId, string $senderName, string $priority, string $subject): void
    {
        $this->notifyTeam(
            'New Development Request',
            "{$senderName} submitted a " . strtoupper($priority) . " priority request: \"{$subject}\"",
            'new_request',
            ['team_request_id' => $requestId]
        );
    }
}
