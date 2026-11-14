<?php
// TASK 4 — news-module permissions. Admins hold all implicitly; other STAFF hold only what an admin granted; investors never hold any.
//   news.contribute  submit own articles / upload own media (never publishes)
//   news.edit        edit any article
//   news.review      moderate submissions and media (approve / reject)
//   news.publish     publish, schedule, unpublish, feature
//   news.manage      remove content, manage categories, read the audit trail
namespace App\Services\News;

use App\Models\News\NewsPermission;
use App\Models\User;

class NewsPermissions
{
    public const ALL = ['news.contribute', 'news.edit', 'news.review', 'news.publish', 'news.manage'];
    public const STAFF_ROLES = ['admin', 'financial', 'marvflow_member', 'marvflow_lead', 'registration_member', 'registration_lead'];   // integration: Task 2 roles can be granted contributor/reviewer rights by an admin

    public static function isStaff(?User $u): bool
    {
        return $u && in_array($u->role, self::STAFF_ROLES, true);
    }

    public static function can(?User $u, string $perm): bool
    {
        if (!self::isStaff($u) || !in_array($perm, self::ALL, true)) return false;
        return $u->role === 'admin' || NewsPermission::where('user_id', $u->id)->where('permission', $perm)->exists();
    }

    public static function any(?User $u): bool
    {
        if (!self::isStaff($u)) return false;
        return $u->role === 'admin' || NewsPermission::where('user_id', $u->id)->exists();
    }

    /** Short label of the department responsible for content authored by this user. */
    public static function departmentOf(User $u): string
    {
        return match ($u->role) { 'admin' => 'administration', 'financial' => 'financial', 'marvflow_member', 'marvflow_lead' => 'marvflow', 'registration_member', 'registration_lead' => 'registration', default => 'other' };
    }
}
