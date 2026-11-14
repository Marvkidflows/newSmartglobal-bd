<?php
// TASK 3 — departments, staff membership, individual mailboxes, staff permissions and the audit trail.
namespace App\Http\Controllers\Mailing;

use App\Http\Controllers\Controller;
use App\Models\Mailing\MailAuditLog;
use App\Models\Mailing\MailDepartment;
use App\Models\Mailing\MailMailbox;
use App\Models\Mailing\MailPermission;
use App\Models\User;
use App\Services\Mailing\MailboxService;
use App\Services\Mailing\MailHeaderGuard;
use App\Services\Mailing\MailingAudit;
use App\Services\Mailing\MailingPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    use Concerns;

    public function index()
    {
        // Non-admin staff only see departments in an environment they hold some permission for; Registration roles only ever see public ones.
        $u = request()->user();
        $q = MailDepartment::with(['mailboxes.user:id,name', 'members:id,name'])->orderBy('name');
        if ($u->role !== 'admin') {
            $hasInvestor = collect(MailingPermissions::forUser($u))->contains(fn ($p) => MailingPermissions::isInvestorPermission($p));
            $q->where(function ($w) use ($hasInvestor) {
                $w->where('environment', 'public');                                   // anyone allowed to list departments may see the public ones
                if ($hasInvestor) $w->orWhereIn('environment', ['investor', 'both']); // investor/both only with an investor.* grant (Registration roles never have one)
            });
        }
        $depts = $q->get()->map(fn ($d) => [
            'id' => $d->id, 'slug' => $d->slug, 'name' => $d->name, 'environment' => $d->environment, 'description' => $d->description, 'is_active' => $d->is_active,
            'members' => $d->members->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->pivot->role]),
            'mailboxes' => $d->mailboxes->map(fn ($m) => $this->boxRow($m)),
        ]);
        return response()->json(['departments' => $depts]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:120', $this->noInjection()], 'slug' => ['required', 'alpha_dash', 'max:60', 'unique:mail_departments,slug'],
            'environment' => ['required', 'in:public,investor,both'], 'description' => ['nullable', 'string', 'max:255'],
        ]);
        $dept = MailDepartment::create($d);
        MailingAudit::record($r->user(), 'department.created', null, $dept, ['slug' => $dept->slug], $r);
        return response()->json(['success' => true, 'id' => $dept->id], 201);
    }

    public function update(Request $r, int $department)
    {
        $department = MailDepartment::findOrFail($department);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        $d = $r->validate([
            'name' => ['sometimes', 'string', 'max:120', $this->noInjection()], 'environment' => ['sometimes', 'in:public,investor,both'],
            'description' => ['nullable', 'string', 'max:255'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $department->update($d);
        MailingAudit::record($r->user(), 'department.updated', null, $department, array_keys($d), $r);
        return response()->json(['success' => true]);
    }

    // PUT /api/mailing/departments/{department}/members  { members: [{user_id, role}] } — staff only
    public function syncMembers(Request $r, int $department)
    {
        $department = MailDepartment::findOrFail($department);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        $d = $r->validate(['members' => ['present', 'array', 'max:200'], 'members.*.user_id' => ['required', 'integer', 'exists:users,id'], 'members.*.role' => ['nullable', 'in:member,lead']]);
        $sync = [];
        foreach ($d['members'] as $m) {
            $u = User::find($m['user_id']);
            abort_unless(MailingPermissions::isStaff($u), 422, 'Only staff accounts can be department members.');
            abort_if(in_array($u->role, MailingPermissions::REGISTRATION_ROLES, true) && $department->environment !== 'public', 422, 'Registration staff can only belong to public-environment departments.');
            $sync[$u->id] = ['role' => $m['role'] ?? 'member'];
        }
        $department->members()->sync($sync);
        MailingAudit::record($r->user(), 'department.members_synced', null, $department, ['count' => count($sync)], $r);
        return response()->json(['success' => true]);
    }

    // POST /api/mailing/departments/{department}/mailboxes
    public function mailboxStore(Request $r, int $department)
    {
        $department = MailDepartment::findOrFail($department);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        $d = $this->mailboxData($r);
        if (!empty($d['user_id'])) {
            $u = User::find($d['user_id']);
            abort_unless(MailingPermissions::isStaff($u), 422, 'A personal mailbox can only be assigned to a staff account.');
        }
        $box = MailMailbox::create($d + ['department_id' => $department->id])->refresh();   // DB default connection_status = not_connected: never "connected" on creation
        MailingAudit::record($r->user(), 'mailbox.created', null, $box, ['address' => $box->address, 'provider' => $box->provider], $r);
        return response()->json(['success' => true, 'mailbox' => $this->boxRow($box)], 201);
    }

    public function mailboxUpdate(Request $r, int $mailbox)
    {
        $mailbox = MailMailbox::findOrFail($mailbox);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        $d = $this->mailboxData($r, $mailbox);
        $addressChanged = isset($d['address']) && $d['address'] !== $mailbox->address;
        $mailbox->update($d);
        if ($addressChanged || isset($d['provider'])) $mailbox->forceFill(['connection_status' => 'not_connected', 'sender_verified_at' => null])->save();   // must be re-verified
        MailingAudit::record($r->user(), 'mailbox.updated', null, $mailbox, array_keys($d), $r);
        return response()->json(['success' => true, 'mailbox' => $this->boxRow($mailbox->refresh())]);
    }

    public function mailboxDestroy(Request $r, int $mailbox)
    {
        $mailbox = MailMailbox::findOrFail($mailbox);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        $mailbox->forceFill(['is_active' => false, 'connection_status' => 'not_connected'])->save();     // deactivated, history preserved
        MailingAudit::record($r->user(), 'mailbox.deactivated', null, $mailbox, [], $r);
        return response()->json(['success' => true]);
    }

    public function mailboxCheck(Request $r, int $mailbox, MailboxService $svc)
    {
        $mailbox = MailMailbox::findOrFail($mailbox);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        $box = $svc->checkConnection($mailbox);
        MailingAudit::record($r->user(), 'mailbox.checked', null, $box, ['status' => $box->connection_status], $r);
        return response()->json(['success' => true, 'mailbox' => $this->boxRow($box)]);
    }

    // GET /api/mailing/permissions — admin only (route uses the existing 'admin' middleware)
    // GET /api/mailing/my/permissions — what THIS staff member may do (drives which tabs the screen shows; the API still enforces every route).
    // Registration roles can never hold investor.* (MailingPermissions::forUser strips them), so they are never shown investor tools.
    public function myPermissions(Request $r)
    {
        $u = $r->user();
        abort_unless(MailingPermissions::isStaff($u), 403, 'Staff only.');
        $perms = $u->role === 'admin' ? MailingPermissions::ALL : array_values(array_filter(MailingPermissions::ALL, fn ($p) => MailingPermissions::can($u, $p)));
        return response()->json(['is_admin' => $u->role === 'admin', 'role' => $u->role, 'permissions' => $perms, 'department_ids' => MailingPermissions::departmentIds($u)]);
    }

    public function permissions()
    {
        $staff = User::whereIn('role', array_diff(MailingPermissions::STAFF_ROLES, ['admin']))->orderBy('name')->get(['id', 'name', 'email', 'role']);
        $grants = MailPermission::whereIn('user_id', $staff->pluck('id'))->get()->groupBy('user_id');
        return response()->json(['available' => MailingPermissions::ALL, 'staff' => $staff->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'permissions' => ($grants[$u->id] ?? collect())->pluck('permission')->values(),
        ])]);
    }

    // PUT /api/mailing/permissions/{user} { permissions: [...] }
    public function syncPermissions(Request $r, int $user)
    {
        $user = User::findOrFail($user);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless(MailingPermissions::isStaff($user) && $user->role !== 'admin', 422, 'Permissions can only be granted to non-admin staff accounts.');
        $d = $r->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', Rule::in(MailingPermissions::ALL)]]);
        $wanted = array_values(array_unique($d['permissions']));

        DB::transaction(function () use ($user, $wanted, $r) {
            MailPermission::where('user_id', $user->id)->whereNotIn('permission', $wanted)->delete();
            foreach ($wanted as $p) MailPermission::firstOrCreate(['user_id' => $user->id, 'permission' => $p], ['granted_by' => $r->user()->id]);
        });
        MailingAudit::record($r->user(), 'permissions.synced', null, $user, ['permissions' => $wanted], $r);
        return response()->json(['success' => true]);
    }

    // GET /api/mailing/audit?action=&actor_id=
    public function audit(Request $r)
    {
        $q = MailAuditLog::with('actor:id,name')->latest('id');
        // Audit rows are scoped to the environments the viewer may see: Registration roles only public/neutral rows; staff without any investor.* grant never see investor rows.
        $u = $r->user();
        if ($u->role !== 'admin') {
            $seesInvestor = !in_array($u->role, MailingPermissions::REGISTRATION_ROLES, true) && collect(MailingPermissions::forUser($u))->contains(fn ($p) => MailingPermissions::isInvestorPermission($p));
            if (!$seesInvestor) $q->where(fn ($w) => $w->whereNull('environment')->orWhere('environment', 'public'));
        }
        if ($r->filled('action')) $q->where('action', 'like', mb_substr((string) $r->input('action'), 0, 40) . '%');
        if ($r->filled('actor_id')) $q->where('actor_id', (int) $r->input('actor_id'));
        if ($r->filled('environment') && in_array($r->input('environment'), ['public', 'investor'], true)) $q->where('environment', $r->input('environment'));
        return response()->json($q->paginate(min(100, (int) $r->integer('per_page', 50)))->through(fn ($a) => [
            'id' => $a->id, 'action' => $a->action, 'environment' => $a->environment, 'subject' => $a->subject_type . '#' . $a->subject_id,
            'actor' => $a->actor?->name, 'metadata' => $a->metadata, 'created_at' => $a->created_at,
        ]));
    }

    protected function mailboxData(Request $r, ?MailMailbox $existing = null): array
    {
        $d = $r->validate([
            'address' => [$existing ? 'sometimes' : 'required', 'email', 'max:190', $this->noInjection(), Rule::unique('mail_mailboxes', 'address')->ignore($existing?->id)],
            'display_name' => [$existing ? 'sometimes' : 'required', 'string', 'max:120', $this->noInjection()],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('mail_mailboxes', 'user_id')->ignore($existing?->id)],
            'provider' => ['sometimes', 'in:none,brevo'],
            'credential_ref' => ['nullable', 'regex:/^[A-Z0-9_]{3,64}$/'],      // the NAME of a secret in the environment — a pasted secret is rejected
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($d['address'])) $d['address'] = MailHeaderGuard::address($d['address']);
        return $d;
    }

    protected function boxRow(MailMailbox $m): array
    {
        return [
            'id' => $m->id, 'department_id' => $m->department_id, 'address' => $m->address, 'display_name' => $m->display_name, 'user_id' => $m->user_id,
            'owner' => $m->user?->name, 'provider' => $m->provider, 'connection_status' => $m->connection_status, 'sender_verified_at' => $m->sender_verified_at,
            'last_checked_at' => $m->last_checked_at, 'last_check_error' => $m->last_check_error, 'has_credential_ref' => $m->credential_ref !== null, 'is_active' => $m->is_active,
        ];
    }
}
