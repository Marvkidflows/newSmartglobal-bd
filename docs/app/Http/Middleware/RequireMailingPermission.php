<?php
// TASK 3 — route gate for the mailing module.
//   ->middleware(RequireMailingPermission::class . ':{env}.manage')       one permission, {env} replaced by the route's {environment}
//   ->middleware(RequireMailingPermission::class . ':a.view,b.view')      ANY of the listed permissions
//   ->middleware(RequireMailingPermission::class . ':staff')              any active staff member (controller narrows further)
// Used by class name (no alias), so bootstrap/app.php is NOT modified. Runs after auth:sanctum.
namespace App\Http\Middleware;

use App\Services\Mailing\MailingPermissions;
use App\Support\StaffAccountGuard;
use Closure;
use Illuminate\Http\Request;

class RequireMailingPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)   // Laravel splits ':a,b' into separate arguments
    {
        $user = $request->user();
        if (!MailingPermissions::isStaff($user)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Staff access required.'], 403);
        }
        if ($blocked = StaffAccountGuard::refuse($request, $user)) return $blocked;

        $env = $request->route('environment');
        if ($env !== null && !in_array($env, ['public', 'investor'], true)) {
            return response()->json(['success' => false, 'message' => 'Unknown mailing environment.'], 404);
        }

        if ($permissions === ['staff']) return $next($request);          // any active staff member; the controller applies finer rules (e.g. department membership)

        foreach ($permissions as $perm) {
            if (MailingPermissions::can($user, str_replace('{env}', (string) $env, trim($perm)))) return $next($request);
        }
        return response()->json(['success' => false, 'message' => 'You do not have permission for this mailing action.'], 403);
    }
}
