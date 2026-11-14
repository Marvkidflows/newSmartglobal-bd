<?php
// Task 5: referral codes, link-visit tracking and funnel events. Extends the EXISTING referral attribution (users.referral_code / users.referred_by);
// it does not create a second referral system. Attribution is written once at account creation by RegisterController and is not editable by an investor
// (no investor-facing endpoint accepts referred_by / referral_code for an existing account).
namespace App\Services\Task5;

use App\Models\ReferralEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReferralService
{
    /** A unique, unambiguous 8-character code (no 0/O/1/I). The users.referral_code column is also DB-unique. */
    public function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < 20; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            if (!User::where('referral_code', $code)->exists()) return $code;
        }
        return strtoupper(Str::random(12));                                         // astronomically unlikely fallback, still checked by the unique index
    }

    /** Existing investors created before codes were guaranteed may have none: give them one, once. Never overwrites an existing code. */
    public function ensureCode(User $user): ?string
    {
        if ($user->role !== 'investor') return null;
        if (!empty($user->referral_code)) return $user->referral_code;
        $code = $this->newCode();
        $updated = User::whereKey($user->id)->whereNull('referral_code')->update(['referral_code' => $code]);
        $user->refresh();
        return $user->referral_code;
    }

    public function referrerByCode(?string $code): ?User
    {
        $raw = trim((string) $code);
        if ($raw === '' || strlen($raw) > 20) return null;
        // Exact match first (codes issued before Task 5 are mixed-case), then the upper-cased form (codes issued by Task 5 are upper-case).
        return User::where('role', 'investor')->whereIn('referral_code', array_unique([$raw, strtoupper($raw)]))->first();
    }

    /** One-way hash so signals can be compared without storing raw IP addresses. Never used alone to declare fraud. */
    public function fingerprint(?Request $request): ?string
    {
        $ip = $request?->ip();
        return $ip ? substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 24) : null;
    }

    public function log(string $event, ?int $referrerId, ?int $referredId, ?string $code, array $meta = []): ReferralEvent
    {
        return ReferralEvent::create(['referrer_id' => $referrerId, 'referred_id' => $referredId, 'referral_code' => $code, 'event' => $event, 'meta' => $meta ?: null, 'created_at' => now()]);
    }

    public function recordVisit(?string $code, Request $request): bool
    {
        $referrer = $this->referrerByCode($code);
        if (!$referrer) return false;
        $this->log('link_visited', $referrer->id, null, $referrer->referral_code, ['fp' => $this->fingerprint($request)]);
        return true;
    }

    public function link(User $user): array
    {
        $code = $this->ensureCode($user);
        return ['code' => $code, 'link' => $code ? rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/register?ref=' . $code : null];
    }
}
