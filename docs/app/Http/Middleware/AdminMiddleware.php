<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user(); // resolves via the guard that actually authenticated this request (sanctum)

        if ($user && $user->role === 'admin') {
            // F-15: a suspended / deactivated staff account is refused and its token revoked
            if ($blocked = \App\Support\StaffAccountGuard::refuse($request, $user)) return $blocked;
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        return redirect('/investor/dashboard')->with('error', 'Unauthorized access');
    }
}