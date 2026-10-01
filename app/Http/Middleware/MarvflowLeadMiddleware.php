<?php
// LOCATION: app/Http/Middleware/MarvflowLeadMiddleware.php
//
// MarvFlow Team Dashboard — gates the handful of lead-only actions
// (reassigning a request to someone else, changing priority). Stacks
// on top of MarvflowMiddleware on those specific routes rather than
// replacing it, so a non-lead marvflow_member gets the same clear
// "you don't have permission" response as anyone else who isn't
// MarvFlow at all — just with a different message.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MarvflowLeadMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->role === 'marvflow_lead') {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'This action requires MarvFlow Team Lead access.',
        ], 403);
    }
}
