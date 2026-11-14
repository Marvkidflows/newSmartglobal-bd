<?php
// LOCATION: app/Http/Middleware/EnsureStaffAccountActive.php
//
// R9: applied to the WHOLE authenticated API group (not just the role-gated route groups), so a non-active staff account cannot use /api/user,
// profile, notifications, or any other authenticated endpoint with an already-issued token. Investors are untouched (they have their own
// CheckAccountStatus). Logout stays reachable so a client can always end its own session cleanly.
namespace App\Http\Middleware;

use App\Support\StaffAccountGuard;
use Closure;
use Illuminate\Http\Request;

class EnsureStaffAccountActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && StaffAccountGuard::isStaffRole($user->role) && !$request->is('api/logout')) {
            if ($blocked = StaffAccountGuard::refuse($request, $user)) return $blocked;
        }
        return $next($request);
    }
}
