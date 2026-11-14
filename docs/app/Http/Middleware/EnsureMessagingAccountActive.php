<?php
// LOCATION: app/Http/Middleware/EnsureMessagingAccountActive.php
//
// Messaging-level restriction for the MarvFlow <-> SSI message endpoints ONLY (applied route by route in routes/api.php).
// Only an account whose status is `active` may list, read, send or reply. suspended / inactive / frozen / deactivated are refused
// with 403 and a stable `code`, before any controller code runs, so nothing is read or written.
//
// This is deliberately NOT a login rule: login, tokens and every other endpoint behave exactly as before (the app-wide suspension
// policy is a separate decision). An empty status is treated as active, as everywhere else in the codebase (`status ?? 'active'`).

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureMessagingAccountActive
{
    public static function isActive($user): bool
    {
        return $user && (($user->status ?? 'active') === 'active');
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && !self::isActive($user)) {
            return response()->json([
                'success' => false,
                'code'    => 'account_restricted',
                'status'  => $user->status,
                'message' => 'Messaging is unavailable while your account is ' . $user->status . '. Please contact SSI support.',
            ], 403);
        }

        return $next($request);
    }
}
