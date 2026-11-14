<?php
// LOCATION: app/Support/StaffAccountGuard.php
//
// F-15 (hardened in the correction pass, R9): STAFF access is an ALLOWLIST, not a denylist. A staff account (admin / financial / MarvFlow) keeps
// access ONLY while its status is explicitly one of ALLOWED_STATUSES. suspended, deactivated, inactive, frozen — and any status value that
// does not exist today — are refused on every request and the current token is revoked, so existing sessions end immediately.
// (Investor 'frozen'/'suspended' are wallet/read-only restrictions handled by CheckAccountStatus and do NOT go through this guard.)
// An empty/NULL status is normalised to 'active', exactly as everywhere else in the codebase (`status ?? 'active'`).
namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

class StaffAccountGuard
{
    public const STAFF_ROLES = ['admin', 'financial', 'marvflow_member', 'marvflow_lead', 'registration_member', 'registration_lead'];
    public const ALLOWED_STATUSES = ['active'];

    public static function isStaffRole(?string $role): bool
    {
        return $role !== null && in_array($role, self::STAFF_ROLES, true);
    }

    public static function normalisedStatus(?User $user): string
    {
        $s = strtolower(trim((string) ($user?->status ?? '')));
        return $s === '' ? 'active' : $s;
    }

    /** True only for an account whose status is on the allowlist. */
    public static function isActive(?User $user): bool
    {
        return $user !== null && in_array(self::normalisedStatus($user), self::ALLOWED_STATUSES, true);
    }

    /** Allowlist semantics: anything that is not explicitly allowed is blocked (a missing user is not "blocked" — authentication handles that). */
    public static function isBlocked(?User $user): bool
    {
        return $user !== null && !self::isActive($user);
    }

    /** Role AND status check used when an action is executed later (e.g. approval execution), not just at request time. */
    public static function isActiveStaffWithRole(?User $user, array $roles): bool
    {
        return $user !== null && in_array($user->role, $roles, true) && self::isActive($user);
    }

    /** Returns a 403 response when the account must be refused (and revokes the token); null when the request may proceed. */
    public static function refuse(Request $request, ?User $user)
    {
        if (!self::isBlocked($user)) return null;
        try { $user->currentAccessToken()?->delete(); } catch (\Throwable $e) { /* session/TransientToken (tests): nothing to revoke */ }
        // Same stable code/status shape as EnsureMessagingAccountActive so existing clients and tests keep working; always 403.
        return response()->json([
            'success' => false,
            'code'    => 'account_restricted',
            'status'  => $user->status,
            'message' => 'Your staff account is ' . $user->status . '. Access is disabled — please contact your administrator.',
        ], 403);
    }
}
