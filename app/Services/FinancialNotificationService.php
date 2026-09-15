<?php
// LOCATION: app/Services/FinancialNotificationService.php
//
// Financial Team dashboard — pushes an in-app notification (via
// FinancialAlertNotification) to every admin and financial-role user
// when a new deposit or withdrawal request comes in, or gets resolved.
// This is separate from TelegramService's notify() — Telegram reaches
// whoever's in the configured chat(s); this reaches whoever's logged
// into the platform, via the same notification bell/list investors use.
//
// Deliberately a thin, single-purpose service rather than something
// bolted onto TelegramService or the deposit/withdrawal controllers
// directly, so the "who gets notified" logic lives in exactly one
// place.

namespace App\Services;

use App\Models\User;
use App\Notifications\FinancialAlertNotification;

class FinancialNotificationService
{
    /**
     * @param array $data Extra fields merged into the notification's
     *                     stored data (e.g. ['deposit_id' => $id]) —
     *                     lets the frontend deep-link to the record.
     */
    public function notifyFinancialTeam(string $title, string $message, string $type = 'financial', array $data = []): void
    {
        $notification = new FinancialAlertNotification($title, $message, $type, $data);

        User::whereIn('role', ['admin', 'financial'])
            ->get()
            ->each(fn (User $user) => $user->notify($notification));
    }

    public function newDeposit(int $depositId, string $investorName, float $amount): void
    {
        $this->notifyFinancialTeam(
            'New Deposit Request',
            "{$investorName} submitted a deposit of $" . number_format($amount, 2) . '.',
            'deposit',
            ['deposit_id' => $depositId]
        );
    }

    public function newWithdrawal(int $withdrawalId, string $investorName, float $amount): void
    {
        $this->notifyFinancialTeam(
            'New Withdrawal Request',
            "{$investorName} requested a withdrawal of $" . number_format($amount, 2) . '.',
            'withdrawal',
            ['withdrawal_id' => $withdrawalId]
        );
    }
}
