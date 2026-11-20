<?php
// LOCATION: app/Support/AuthTokens.php   (NEW FILE — D-02)
//
// One place that issues and revokes API tokens, so no controller can mint a token without an expiry or forget to revoke on a security-sensitive change.

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use Laravel\Sanctum\PersonalAccessToken;

class AuthTokens
{
    private const DEFAULT_STAFF_MINUTES    = 480;
    private const DEFAULT_INVESTOR_MINUTES = 10080;
    private const DEFAULT_CEILING_MINUTES  = 10080;

    /** Minutes this user's NEW token should live. Never 0/negative (those fall back to the defaults) and never above the ceiling. */
    public static function lifetimeMinutes(?User $user): int
    {
        $staff = StaffAccountGuard::isStaffRole($user?->role);
        $minutes = (int) config($staff ? 'ssi_auth.staff_token_lifetime_minutes' : 'ssi_auth.investor_token_lifetime_minutes');
        if ($minutes <= 0) {
            $minutes = $staff ? self::DEFAULT_STAFF_MINUTES : self::DEFAULT_INVESTOR_MINUTES;
        }
        $ceiling = (int) config('ssi_auth.token_ceiling_minutes');
        if ($ceiling <= 0) {
            $ceiling = self::DEFAULT_CEILING_MINUTES;
        }
        return min($minutes, $ceiling);
    }

    public static function expiresAt(?User $user): CarbonInterface
    {
        return now()->addMinutes(self::lifetimeMinutes($user));
    }

    /** Issues a token that expires, and returns the plain-text value (shown to the client once). */
    public static function issue(User $user, string $name): string
    {
        return $user->createToken($name, ['*'], self::expiresAt($user))->plainTextToken;
    }

    /** Revokes every token of the user (password reset, deactivation, e-mail change by someone else). Returns the number revoked. */
    public static function revokeAll(User $user): int
    {
        return (int) $user->tokens()->delete();
    }

    /**
     * Revokes every token EXCEPT the one making the current request (password change by the signed-in person: they stay signed in, every other
     * device is signed out). Transient / session authentication has no token row, so nothing is kept in that case.
     */
    public static function revokeOthers(User $user): int
    {
        $current = $user->currentAccessToken();
        $keep    = $current instanceof PersonalAccessToken ? $current->getKey() : null;
        return (int) $user->tokens()->when($keep, fn ($q) => $q->where('id', '!=', $keep))->delete();
    }
}
