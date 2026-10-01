<?php
// LOCATION: app/Http/Middleware/MarvflowMiddleware.php
//
// MarvFlow Team Dashboard — gates the /api/marvflow/* routes.
//
// IMPORTANT — this deliberately does NOT follow the same pattern as
// FinancialMiddleware (which also lets role === 'admin' through).
// MarvFlow is Smart System Investment's external development team, not
// part of SSI's own staff hierarchy — per the spec this was built to:
// "Do not give [SSI Admin/Management] access to MarvFlow's private
// internal dashboard." So only marvflow_member / marvflow_lead pass
// here, full stop. An SSI admin reaches MarvFlow only indirectly, as
// the sender of a team_request, through DevRequestController — never
// through this middleware or anything gated by it.
//
// This is server-side enforcement, not a UI convenience — hiding the
// MarvFlow sidebar link from an admin's UI is not what keeps them out;
// this middleware running on every /marvflow/* route is.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MarvflowMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && ($user->role === 'marvflow_member' || $user->role === 'marvflow_lead')) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. MarvFlow team access required.',
            ], 403);
        }

        return redirect('/login')->with('error', 'Unauthorized access');
    }
}
