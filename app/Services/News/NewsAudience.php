<?php
// TASK 4 — who may read which visibility level. Server-enforced on every read endpoint (the UI is never the gate).
//   public   → everyone, including anonymous visitors
//   members  → any signed-in investor-role account (including applicants not yet verified)
//   investor → verified, eligible investors only (active + KYC verified + confirmed email)
namespace App\Services\News;

use App\Models\User;

class NewsAudience
{
    public static function isVerifiedInvestor(?User $u): bool
    {
        return $u && $u->role === 'investor' && $u->status === 'active' && (bool) $u->kyc_verified && $u->email_verified_at !== null;
    }

    /** @return string[] */
    public static function visibilitiesFor(?User $u): array
    {
        if (!$u || $u->role !== 'investor') return ['public'];
        return self::isVerifiedInvestor($u) ? ['public', 'members', 'investor'] : ['public', 'members'];
    }
}
