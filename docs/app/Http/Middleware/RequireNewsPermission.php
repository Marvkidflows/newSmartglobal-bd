<?php
// TASK 4 — route gate for the news-module staff API.
//   ->middleware(RequireNewsPermission::class . ':news.review,news.publish')   ANY of the listed permissions
//   ->middleware(RequireNewsPermission::class . ':any')                         any staff member holding at least one news permission
// Used by class name (no alias), so bootstrap/app.php is NOT modified. Runs after auth:sanctum.
namespace App\Http\Middleware;

use App\Services\News\NewsPermissions;
use App\Support\StaffAccountGuard;
use Closure;
use Illuminate\Http\Request;

class RequireNewsPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)   // Laravel splits ':a,b' into separate arguments
    {
        $user = $request->user();
        if (!NewsPermissions::isStaff($user)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Staff access required.'], 403);
        }
        if ($blocked = StaffAccountGuard::refuse($request, $user)) return $blocked;

        if ($permissions === ['any'] ? NewsPermissions::any($user) : collect($permissions)->contains(fn ($p) => NewsPermissions::can($user, trim($p)))) {
            return $next($request);
        }
        return response()->json(['success' => false, 'message' => 'You do not have permission for this news action.'], 403);
    }
}
