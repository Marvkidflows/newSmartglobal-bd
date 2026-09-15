<?php
// LOCATION: app/Services/TelegramService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected ?string $token;
    protected ?string $chatId;
    protected ?string $financialChatId;

    public function __construct()
    {
        $this->token           = config('services.telegram.bot_token');
        $this->chatId          = config('services.telegram.chat_id');
        $this->financialChatId = config('services.telegram.financial_chat_id');
    }

    /**
     * Send a plain text message to the configured admin chat, and —
     * additively, Financial Team dashboard — to the financial chat too
     * if one is configured. Fails silently (logs the error) so a
     * Telegram outage never breaks the actual business action (deposit
     * approval, registration, etc).
     */
    public function notify(string $message): bool
    {
        if (!$this->token || !$this->chatId) {
            Log::warning('Telegram notification skipped: bot token or chat ID not configured.');
            return false;
        }

        // Financial team chat is genuinely optional and separate from
        // the primary send below — its own failure/absence never
        // affects whether the primary notification succeeds.
        if ($this->financialChatId) {
            $this->sendMessage($this->financialChatId, $message);
        }

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                'chat_id'    => $this->chatId,
                'text'       => $message,
                'parse_mode' => 'HTML',
            ]);

            if (!$response->successful()) {
                Log::warning('Telegram notification failed.', ['response' => $response->body()]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Telegram notification exception: ' . $e->getMessage());
            return false;
        }
    }

    public function newRegistration(string $name, string $email): void
    {
        $this->notify(
            "🆕 <b>New Investor Registered</b>\n" .
            "Name: {$name}\n" .
            "Email: {$email}\n" .
            "Time: " . now()->format('Y-m-d H:i')
        );
    }
public function newDeposit(string $investorName, float $amount, string $reference): void
{
    $this->notify(
        "💰 <b>New Deposit Request</b>\n\n" .
        "👤 <b>Investor:</b> {$investorName}\n" .
        "💵 <b>Amount:</b> $" . number_format($amount, 2) . "\n" .
        "🔖 <b>Reference:</b> <code>{$reference}</code>\n" .
        "🕐 <b>Time:</b> " . now()->format('Y-m-d H:i') . "\n\n" .
        "⚡ Investor will contact you on Telegram to make payment.\n" .
        "Confirm with them using the reference above."
    );
}

    public function depositApproved(string $investorName, float $amount): void
    {
        $this->notify(
            "✅ <b>Deposit Approved</b>\n" .
            "Investor: {$investorName}\n" .
            "Amount: $" . number_format($amount, 2)
        );
    }

    public function newWithdrawal(string $investorName, float $amount, string $method): void
    {
        $this->notify(
            "🏦 <b>New Withdrawal Request</b>\n" .
            "Investor: {$investorName}\n" .
            "Amount: $" . number_format($amount, 2) . "\n" .
            "Method: {$method}\n" .
            "Time: " . now()->format('Y-m-d H:i')
        );
    }

    public function withdrawalApproved(string $investorName, float $amount): void
    {
        $this->notify(
            "✅ <b>Withdrawal Approved</b>\n" .
            "Investor: {$investorName}\n" .
            "Amount: $" . number_format($amount, 2)
        );
    }

    public function largeBalanceAdjustment(string $investorName, string $type, float $amount, string $reason): void
    {
        $this->notify(
            "⚠️ <b>Balance Adjustment</b>\n" .
            "Investor: {$investorName}\n" .
            "Type: " . ucfirst($type) . "\n" .
            "Amount: $" . number_format($amount, 2) . "\n" .
            "Reason: {$reason}"
        );
    }
    
    public function sendMessage(int|string $chatId, string $message): bool
{
    if (!$this->token) {
        Log::warning('Telegram bot token is not configured.');
        return false;
    }

    try {
        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$this->token}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]
        );

        if (!$response->successful()) {
            Log::warning('Telegram sendMessage failed.', [
                'response' => $response->body(),
            ]);

            return false;
        }

        return true;
    } catch (\Throwable $e) {
        Log::error('Telegram sendMessage exception.', [
            'error' => $e->getMessage(),
        ]);

        return false;
    }
}
public function forwardInvestorMessage(
    ?int $telegramUserId,
    int|string $chatId,
    string $firstName,
    string $lastName,
    ?string $username,
    string $message
): bool {
    $name = trim($firstName . ' ' . $lastName) ?: 'Unknown Investor';

    $adminMessage =
        "💬 <b>Investor Telegram Message</b>\n\n" .
        "👤 <b>Investor:</b> " . e($name) . "\n" .
        "🔗 <b>Username:</b> " . e($username ? '@' . $username : 'Not provided') . "\n" .
        "🆔 <b>Telegram ID:</b> <code>{$telegramUserId}</code>\n" .
        "💬 <b>Message:</b>\n" . e($message) . "\n\n" .
        "📌 <b>Reply Chat ID:</b> <code>{$chatId}</code>";

    return $this->notify($adminMessage);
}
}