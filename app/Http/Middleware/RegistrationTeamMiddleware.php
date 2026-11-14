<?php
// LOCATION: app/Http/Middleware/RegistrationTeamMiddleware.php
//
// Task 2 — gates the /api/registration/* routes. Admits registration_member, registration_lead and admin (Admin retains platform-wide
// oversight, exactly as FinancialMiddleware admits admin). It does NOT admit financial or marvflow roles, and — the other half of the
// departmental separation — registration roles are refused by the admin, financial, marvflow and investor middleware, and are absent from
// every financial Gate. All of this is server-side; hiding a link in the UI is never what keeps the team out of financial data.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RegistrationTeamMiddleware
{
    public const ROLES = ['registration_member', 'registration_lead', 'admin'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && in_array($user->role, self::ROLES, true)) {
            // F-15: a suspended / deactivated staff account is refused and its token revoked
            if ($blocked = \App\Support\StaffAccountGuard::refuse($request, $user)) return $blocked;
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. Registration team access required.',
        ], 403);
    }
}
