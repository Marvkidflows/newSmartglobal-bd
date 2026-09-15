<?php
// LOCATION: app/Http/Middleware/FinancialMiddleware.php
//
// Financial Team dashboard — gates the /api/financial/* routes.
// Admins keep full access everywhere (including here) since existing
// Admin-level access is meant to retain overall platform control; a
// financial-role user is let through only for these specific financial
// routes, never for /api/admin/* (that's still AdminMiddleware, which
// checks role === 'admin' exactly and doesn't know 'financial' exists).
//
// This is server-side enforcement, not a UI convenience — every route
// in the financial group depends on this running first, so hiding a
// frontend button is never the only thing standing between a financial
// user and an action they shouldn't reach.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class FinancialMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && ($user->role === 'financial' || $user->role === 'admin')) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Financial team access required.',
            ], 403);
        }

        return redirect('/investor/dashboard')->with('error', 'Unauthorized access');
    }
}
