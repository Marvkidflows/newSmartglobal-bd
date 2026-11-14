<?php
// TASK 4 — categories, staff permissions (admin only) and audit trail.
namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\News\NewsAuditLog;
use App\Models\News\NewsCategory;
use App\Models\News\NewsPermission;
use App\Models\User;
use App\Services\News\NewsAudit;
use App\Services\News\NewsPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NewsAccessController extends Controller
{
    // GET /api/news-admin/my/permissions — what THIS staff member may do (drives which tabs the screen shows; the API still enforces every route).
    public function myPermissions(\Illuminate\Http\Request $r)
    {
        $u = $r->user();
        abort_unless(NewsPermissions::isStaff($u), 403, 'Staff only.');
        $perms = $u->role === 'admin' ? NewsPermissions::ALL : array_values(array_filter(NewsPermissions::ALL, fn ($p) => NewsPermissions::can($u, $p)));
        return response()->json(['is_admin' => $u->role === 'admin', 'role' => $u->role, 'permissions' => $perms, 'department' => NewsPermissions::departmentOf($u)]);
    }

    public function categories()
    {
        return response()->json(['categories' => NewsCategory::orderBy('sort_order')->get()]);
    }

    public function categoryStore(Request $r)
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:100'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000']]);
        $slug = Str::slug($d['name']) ?: 'category';
        $base = $slug; $i = 1;
        while (NewsCategory::where('slug', $slug)->exists()) $slug = $base . '-' . (++$i);
        $c = NewsCategory::create(['slug' => $slug, 'name' => strip_tags($d['name']), 'sort_order' => $d['sort_order'] ?? 0]);
        NewsAudit::record($r->user(), 'category.created', $c, ['name' => $c->name], $r);
        return response()->json(['success' => true, 'category' => $c], 201);
    }

    public function categoryUpdate(Request $r, int $id)
    {
        $c = NewsCategory::findOrFail($id);
        $d = $r->validate(['name' => ['sometimes', 'string', 'max:100'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'], 'is_active' => ['sometimes', 'boolean']]);
        if (isset($d['name'])) $d['name'] = strip_tags($d['name']);
        $c->update($d);
        NewsAudit::record($r->user(), 'category.updated', $c, array_keys($d), $r);
        return response()->json(['success' => true, 'category' => $c->refresh()]);
    }

    // GET /api/news-admin/permissions — admin only (route uses the existing 'admin' middleware)
    public function permissions()
    {
        $staff = User::whereIn('role', array_diff(NewsPermissions::STAFF_ROLES, ['admin']))->orderBy('name')->get(['id', 'name', 'email', 'role']);
        $grants = NewsPermission::whereIn('user_id', $staff->pluck('id'))->get()->groupBy('user_id');
        return response()->json(['available' => NewsPermissions::ALL, 'staff' => $staff->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'permissions' => ($grants[$u->id] ?? collect())->pluck('permission')->values(),
        ])]);
    }

    public function syncPermissions(Request $r, int $user)
    {
        $user = User::findOrFail($user);
        abort_unless(NewsPermissions::isStaff($user) && $user->role !== 'admin', 422, 'Permissions can only be granted to non-admin staff accounts.');
        $d = $r->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', Rule::in(NewsPermissions::ALL)]]);
        $wanted = array_values(array_unique($d['permissions']));
        DB::transaction(function () use ($user, $wanted, $r) {
            NewsPermission::where('user_id', $user->id)->whereNotIn('permission', $wanted)->delete();
            foreach ($wanted as $p) NewsPermission::firstOrCreate(['user_id' => $user->id, 'permission' => $p], ['granted_by' => $r->user()->id]);
        });
        NewsAudit::record($r->user(), 'permissions.synced', $user, ['permissions' => $wanted], $r);
        return response()->json(['success' => true]);
    }

    public function audit(Request $r)
    {
        return response()->json(NewsAuditLog::with('actor:id,name')->latest('id')->paginate(50)->through(fn ($a) => [
            'id' => $a->id, 'action' => $a->action, 'subject' => $a->subject_type . '#' . $a->subject_id, 'actor' => $a->actor?->name, 'metadata' => $a->metadata, 'created_at' => $a->created_at,
        ]));
    }
}
