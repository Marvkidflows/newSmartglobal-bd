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
    protected ?string $marvflowChatId;

    public function __construct()
    {
        $this->token           = config('services.telegram.bot_token');
        $this->chatId          = config('services.telegram.chat_id');
        $this->financialChatId = config('services.telegram.financial_chat_id');
        $this->marvflowChatId  = config('services.telegram.marvflow_chat_id');
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
                Log::warning('Telegram notification failed.', ['status' => $response->status()]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Telegram notification exception.', ['error' => $this->safeError($e)]);
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
        "👤 <b>Investor:</b> " . e($investorName) . "\n" .
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

    // MarvFlow Team Dashboard — one new team_request notification.
    // Deliberately its own method rather than routed through notify():
    // notify() always fans out to chat_id (+financial_chat_id when
    // set), and a MarvFlow request must ONLY ever reach the MarvFlow
    // team's own chat, never SSI's own admin/financial Telegram chats.
    //
    // Never throws: if TELEGRAM_MARVFLOW_CHAT_ID isn't configured yet,
    // this logs a warning and returns false — the request itself has
    // already been saved to the database by the time this runs (see
    // SendMarvflowTelegramNotification job), so a missing/misconfigured
    // chat ID here never loses or blocks the request.
    public function newMarvflowRequest(int $requestId, string $senderName, string $priority, string $subject, string $message, string $status = 'NEW'): bool
    {
        if (!$this->marvflowChatId) {
            Log::warning('MarvFlow Telegram notification skipped: TELEGRAM_MARVFLOW_CHAT_ID is not configured.', [
                'team_request_id' => $requestId,
            ]);
            return false;
        }

        $text =
            "🆕 <b>NEW MARVFLOW TEAM REQUEST</b>\n\n" .
            "🆔 <b>Request ID:</b> #{$requestId}\n" .
            "🏢 <b>From:</b> Smart System Investment\n" .
            "👤 <b>Sender:</b> " . e($senderName) . "\n" .
            "⚡ <b>Priority:</b> " . strtoupper($priority) . "\n\n" .
            "📌 <b>Subject:</b>\n" . e($subject) . "\n\n" .
            "💬 <b>Message:</b>\n" . e($message) . "\n\n" .
            "📊 <b>Status:</b> " . strtoupper($status) . "\n\n" .
            "🕐 <b>Date:</b> " . now()->format('Y-m-d H:i');

        return $this->sendMessage($this->marvflowChatId, $text);
    }
    
    // MarvFlow Team Dashboard: SSI staff (Financial Team / Admin) replied to MarvFlow. Same destination and rules as
    // newMarvflowRequest(): only the MarvFlow chat, never SSI's own chats; logs and returns false (never throws) when not configured.
    // Deliberately carries NO message body: just who, where and the subject (the content is read in the dashboard).
    public function marvflowStaffContact(string $source, string $senderName, string $senderRole, ?string $subject = null): bool
    {
        if (!$this->marvflowChatId) {
            Log::warning('MarvFlow Telegram staff-contact alert skipped: TELEGRAM_MARVFLOW_CHAT_ID is not configured.', ['source' => $source]);
            return false;
        }

        $role = $senderRole === 'admin' ? 'Admin' : 'Financial Team';
        $text =
            "💬 <b>SSI STAFF CONTACTED MARVFLOW</b>\n\n" .
            "👤 <b>From:</b> " . e($senderName) . " ({$role})\n" .
            "📍 <b>Where:</b> " . e($source) . "\n" .
            ($subject !== null && $subject !== '' ? "📌 <b>Subject:</b> " . e(mb_substr($subject, 0, 200)) . "\n" : '') .
            "\n👉 Open the MarvFlow dashboard to read and reply.\n" .
            "🕐 " . now()->format('Y-m-d H:i');

        return $this->sendMessage($this->marvflowChatId, $text);
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
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    } catch (\Throwable $e) {
        Log::error('Telegram sendMessage exception.', [
            'error' => $this->safeError($e),
        ]);

        return false;
    }
}
    /**
     * Like sendMessage(), but returns true ONLY when Telegram itself answered ok:true. Used where the caller must not
     * claim delivery that did not happen (agent notification). Never throws; never logs the token or the message.
     */
    public function sendMessageConfirmed(int|string $chatId, string $message): bool
    {
        if (!$this->token) {
            Log::warning('Telegram bot token is not configured.');
            return false;
        }
        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                'chat_id' => $chatId, 'text' => $message, 'parse_mode' => 'HTML',
            ]);
            if (!$response->successful() || $response->json('ok') !== true) {
                Log::warning('Telegram agent message not confirmed.', ['status' => $response->status()]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('Telegram agent message exception.', ['error' => $this->safeError($e)]);
            return false;
        }
    }

    /**
     * Heads-up that an investor wrote to the Financial Team inside the SSI dashboard (chat message or deposit enquiry).
     * Goes to ONE agent's chat when $agentChatId is given, otherwise to the configured staff chat(s). Only what staff need to
     * recognise the request: investor name + account id, topic, deposit reference and a short preview - no email, phone,
     * balances or payment details. Replying is done in the SSI dashboard. Returns true only if Telegram confirmed delivery.
     */
    public function newInvestorMessage(string $investorName, ?string $accountId, bool $isDeposit, ?string $reference, string $preview, int|string|null $agentChatId = null): bool
    {
        $text =
            ($isDeposit ? "💰 <b>New deposit enquiry</b>\n\n" : "💬 <b>New investor message</b>\n\n") .
            "👤 <b>Investor:</b> " . e($investorName) . ($accountId ? " (" . e($accountId) . ")" : '') . "\n" .
            ($reference ? "🔖 <b>Reference:</b> <code>" . e($reference) . "</code>\n" : '') .
            "📝 <b>Message:</b> " . e(mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $preview)), 0, 200, '…')) . "\n\n" .
            "Reply in the SSI Financial dashboard → Messages. Nothing has been paid or approved.";

        return $agentChatId ? $this->sendMessageConfirmed($agentChatId, $text) : $this->notify($text);
    }

    /**
     * Tell ONE specific Financial Team agent about a new deposit request. Contains only what the agent needs to match
     * the payment: reference, investor name + account id, plan, the intended USD amount. No balances, emails,
     * phone numbers, payment details or proof. Returns true only if Telegram confirmed delivery.
     */
    public function notifyAssignedAgentOfDeposit(string $chatId, string $investorName, string $accountId, string $planName, float $amount, string $reference): bool
    {
        return $this->sendMessageConfirmed($chatId,
            "💰 <b>New deposit request (PENDING)</b>\n\n" .
            "👤 <b>Investor:</b> " . e($investorName) . " (" . e($accountId) . ")\n" .
            "📦 <b>Plan:</b> " . e($planName) . "\n" .
            "💵 <b>Intended amount:</b> $" . number_format($amount, 2) . " (US-dollar value, unverified)\n" .
            "🔖 <b>Reference:</b> <code>" . e($reference) . "</code>\n\n" .
            "The investor is being connected to you on Telegram. Nothing has been paid or approved; " .
            "approval is done only in the SSI Financial dashboard."
        );
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

    /**
     * Exception text that is safe to write to a log. The bot token is part of every Bot API URL, and HTTP client
     * exceptions quote the URL, so the raw message must never be logged. Response bodies are not logged either.
     */
    private function safeError(\Throwable $e): string
    {
        $message = $e->getMessage();
        if ($this->token) {
            $message = str_replace($this->token, '[REDACTED]', $message);
        }
        $message = preg_replace('#/bot[^/\s]+#', '/bot[REDACTED]', $message) ?? '';

        return get_class($e) . ': ' . mb_substr($message, 0, 300);
    }
}
