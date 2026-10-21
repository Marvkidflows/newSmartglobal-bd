<?php
// LOCATION: app/Http/Middleware/VerifyTelegramWebhook.php
//
// Accepts a request on the public Telegram webhook ONLY if it carries Telegram's secret token
// (the `secret_token` given to setWebhook is echoed back in the X-Telegram-Bot-Api-Secret-Token header).
// FAILS CLOSED: if no secret is configured the webhook is disabled (503), so it can never be reached unauthenticated.
// Even an accepted request can only forward text to staff — nothing on this route approves or changes a deposit.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.telegram.webhook_secret');

        if (strlen($secret) < 16) {
            return response()->json(['message' => 'Telegram webhook is not configured.'], 503);
        }

        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if (!hash_equals($secret, $given)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
