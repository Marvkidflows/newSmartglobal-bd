<?php
// TASK 3 — permission model for the mailing module.
//
// Admins hold every permission implicitly. Other STAFF roles (financial, marvflow_*, registration_* — the latter never investor.*) hold only what an admin has explicitly granted in
// mail_permissions. Investors can never hold a permission, whatever rows exist. Public-mailing and investor-mailing permissions are
// distinct, so access to one environment never implies access to the other.
namespace App\Services\Mailing;

use App\Models\Mailing\MailDepartment;
use App\Models\Mailing\MailPermission;
use App\Models\User;

class MailingPermissions
{
    public const ALL = [
        'public.view', 'public.manage', 'public.send',
        'investor.view', 'investor.manage', 'investor.send', 'investor.send_essential',
        'departments.view', 'departments.manage',
        'audit.view',
    ];

    public const STAFF_ROLES = ['admin', 'financial', 'marvflow_member', 'marvflow_lead', 'registration_member', 'registration_lead'];   // integration: Task 2 roles added so the Registration department can have mailboxes

    /** Integration (Task 2 x Task 3): the Registration Team handles applicants, i.e. PUBLIC mailing only. It can never hold an investor-mailing permission,
     *  whatever rows exist in mail_permissions (defence in depth: an admin mis-click must not expose investor communications to onboarding staff). */
    public const REGISTRATION_ROLES = ['registration_member', 'registration_lead'];

    public static function isInvestorPermission(string $permission): bool
    {
        return str_starts_with($permission, 'investor.');
    }

    public static function isStaff(?User $u): bool
    {
        return $u && in_array($u->role, self::STAFF_ROLES, true);
    }

    public static function can(?User $u, string $permission): bool
    {
        if (!self::isStaff($u) || !in_array($permission, self::ALL, true)) return false;
        if ($u->role === 'admin') return true;
        if (in_array($u->role, self::REGISTRATION_ROLES, true) && self::isInvestorPermission($permission)) return false;
        return MailPermission::where('user_id', $u->id)->where('permission', $permission)->exists();
    }

    /** @return string[] */
    public static function forUser(User $u): array
    {
        if (!self::isStaff($u)) return [];
        if ($u->role === 'admin') return self::ALL;
        $perms = MailPermission::where('user_id', $u->id)->pluck('permission')->all();
        if (in_array($u->role, self::REGISTRATION_ROLES, true)) $perms = array_values(array_filter($perms, fn ($p) => !self::isInvestorPermission($p)));
        return $perms;
    }

    /** Department membership is how a staff member reaches the correspondence of ONE department without environment-wide access. */
    public static function inDepartment(?User $u, ?int $departmentId): bool
    {
        if (!self::isStaff($u) || !$departmentId) return false;
        return $u->role === 'admin' || MailDepartment::whereKey($departmentId)
            ->whereHas('members', fn ($q) => $q->where('users.id', $u->id))->exists();
    }

    /** @return int[] ids of the departments this user belongs to (kept here so the shared User model is not modified) */
    public static function departmentIds(User $u): array
    {
        return \Illuminate\Support\Facades\DB::table('mail_department_members')->where('user_id', $u->id)->pluck('department_id')->map(fn ($i) => (int) $i)->all();
    }
}
