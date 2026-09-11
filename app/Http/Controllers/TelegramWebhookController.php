<?php

namespace App\Http\Controllers;

use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramService $telegram
    ) {}

    public function handle(Request $request)
    {
        $update = $request->all();

        Log::info('Telegram webhook received', $update);

        $message = $update['message'] ?? null;

        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];

        $chatId = $chat['id'] ?? null;
        $text = trim($message['text'] ?? '');

        if (!$chatId || $text === '') {
            return response()->json(['ok' => true]);
        }

        $telegramUserId = $from['id'] ?? null;
        $firstName = $from['first_name'] ?? '';
        $lastName = $from['last_name'] ?? '';
        $username = $from['username'] ?? null;

        // Investor's message
        if ($text !== '/start') {
            $this->telegram->forwardInvestorMessage(
                telegramUserId: $telegramUserId,
                chatId: $chatId,
                firstName: $firstName,
                lastName: $lastName,
                username: $username,
                message: $text
            );

            // Optional acknowledgement to investor
            $this->telegram->sendMessage(
                $chatId,
                "Thank you for your message. An agent will respond to you shortly."
            );

            return response()->json(['ok' => true]);
        }

        // /start
        $this->telegram->sendMessage(
            $chatId,
            "Welcome to Smart System Investment.\n\n"
            . "You can send your message here and our team will respond to you."
        );

        $this->telegram->notify(
            "🤖 <b>New Investor Started Telegram Bot</b>\n\n"
            . "👤 <b>Name:</b> " . e(trim($firstName . ' ' . $lastName)) . "\n"
            . "🔗 <b>Username:</b> " . e($username ? '@' . $username : 'Not provided') . "\n"
            . "🆔 <b>Telegram ID:</b> <code>{$telegramUserId}</code>"
        );

        return response()->json(['ok' => true]);
    }
}