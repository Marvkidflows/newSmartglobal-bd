<?php
// TASK 3 — the ONE definition of "verified, eligible investor" used for every investor-only audience and send.
// Eligible = investor role + active account + KYC verified + confirmed e-mail address.
namespace App\Services\Mailing;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class InvestorEligibility
{
    public static function scope(Builder $q): Builder
    {
        return $q->where('role', 'investor')
            ->where('status', 'active')
            ->where('kyc_verified', true)
            ->whereNotNull('email_verified_at');
    }

    public static function isEligible(?User $u): bool
    {
        return $u && $u->role === 'investor' && $u->status === 'active' && (bool) $u->kyc_verified && $u->email_verified_at !== null;
    }

    public static function emailIsEligible(string $email): bool
    {
        return self::scope(User::query())->whereRaw('LOWER(email) = ?', [strtolower($email)])->exists();
    }
}
