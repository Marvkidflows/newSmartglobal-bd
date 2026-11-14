<?php
// LOCATION: app/Services/Registration/RegistrationResumeService.php
//
// Task 2 (security correction) — proof of email ownership required to RESUME an unfinished registration.
//
// Threat closed: previously anyone who knew an unfinished registrant's email could POST /register/stage1 with it and (a) overwrite that
// account's password/name/phone and (b) receive a login token. The resume path now changes NOTHING and issues NOTHING until the caller proves
// control of the mailbox with a one-time code.
//
// Controls:
//   * ELIGIBILITY — only an ACTIVE account with role 'investor' and registration_completed = false may be resumed. Staff accounts (admin,
//     financial, marvflow, registration) can have registration_completed = false because they are created directly, and must never be
//     reachable through this path. Re-checked on the LOCKED row inside the verification transaction.
//   * CODE — 6 digits from random_int, stored only as an HMAC, single use, expires (default 10 min), destroyed after N wrong guesses
//     (default 5), and the previous code is invalidated whenever a new one is issued, so only one code is ever live.
//   * DELIVERY STATE (round 2) — a code is usable only once its row says delivery_status = 'sent'. If the mail send throws, or its outcome
//     cannot be known (e.g. the provider timed out after accepting the message), the row is KEPT, marked 'failed' and consumed: the code can
//     never be used, yet the row still counts toward the cooldown and hourly cap, so a broken or flaky provider cannot be used to request
//     codes without limit. Rows are never deleted to "undo" a failed send.
//   * ABUSE LIMITS — per ACCOUNT (database-enforced, so rotating IP addresses does not help): a minimum gap between codes (default 60 s) and a
//     maximum per hour (default 5) counted over ALL rows — sent, pending and failed alike. That bounds both inbox flooding and online guessing
//     to at most 5 x 5 = 25 guesses per hour in total.
//   * ATOMIC COMPLETION (round 2) — verifyAndApply() checks the code, applies the credential update AND consumes the code inside ONE database
//     transaction. If the update fails for any reason the transaction rolls back and the code is still valid, so the owner is not locked out
//     by a code that was "used" without the resume completing. A WRONG guess is the only thing that is committed on a failure path (the
//     attempt counter), which is exactly what the attempt cap needs.
//   * NO ORACLE — every verification failure (no such account, ineligible account, wrong code, expired, locked, undelivered) returns the same result.
//
// Residual, accepted trade-offs: (1) because limits are per account, someone who knows an email can use up its hourly allowance and delay the
// real owner (a nuisance, not a takeover); (2) during a genuine mail-provider outage, failed sends also use up the owner's hourly allowance.

namespace App\Services\Registration;

use App\Mail\RegistrationResumeCodeMail;
use App\Models\RegistrationResumeChallenge as Challenge;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RegistrationResumeService
{
    public function eligible(?User $user): bool
    {
        return $user !== null
            && $user->role === 'investor'
            && !$user->registration_completed
            && strtolower((string) ($user->status ?? 'active')) === 'active';
    }

    /**
     * Issue and email a code unless a limit applies.
     * @return array{sent: bool, reason: ?string, retry_after: int}  reason: null | 'cooldown' | 'hourly_cap' | 'mail_failed'
     *         retry_after = seconds until another code may be requested (0 when not applicable)
     */
    public function requestCode(User $user): array
    {
        $ttl      = max(1, (int) config('registration.resume_code_ttl_minutes'));
        $cooldown = max(0, (int) config('registration.resume_request_cooldown_sec'));

        $issued = DB::transaction(function () use ($user, $ttl, $cooldown) {
            User::whereKey($user->id)->lockForUpdate()->first();   // serialise concurrent requests for one account

            $now = now();
            $last = Challenge::where('user_id', $user->id)->latest('id')->first();   // ANY row, whatever its delivery state
            if ($last && $last->created_at->gt($now->copy()->subSeconds($cooldown))) {
                return ['reason' => 'cooldown', 'retry_after' => max(1, $cooldown - (int) $last->created_at->diffInSeconds($now, true))];
            }
            $lastHour = Challenge::where('user_id', $user->id)->where('created_at', '>=', $now->copy()->subHour())->count();   // ANY row
            if ($lastHour >= (int) config('registration.resume_requests_per_hour')) {
                return ['reason' => 'hourly_cap', 'retry_after' => $cooldown];
            }

            Challenge::where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => $now]);   // only one live code

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $challenge = Challenge::create([
                'user_id'         => $user->id,
                'code_hash'       => Challenge::hashCode($code),
                'attempts'        => 0,
                'delivery_status' => Challenge::DELIVERY_PENDING,   // NOT usable until the send is confirmed
                'expires_at'      => $now->copy()->addMinutes($ttl),
                'created_at'      => $now,
            ]);
            return ['challenge' => $challenge, 'code' => $code];
        });

        if (isset($issued['reason'])) {
            return ['sent' => false, 'reason' => $issued['reason'], 'retry_after' => $issued['retry_after']];
        }

        /** @var Challenge $challenge */
        $challenge = $issued['challenge'];

        try {
            Mail::mailer(config('registration.resume_mailer'))->to($user->email)
                ->send(new RegistrationResumeCodeMail($issued['code'], (string) ($user->full_name ?? $user->name ?? ''), $ttl));
            $challenge->forceFill(['delivery_status' => Challenge::DELIVERY_SENT])->save();
        } catch (\Throwable $e) {
            // The send failed — or we cannot tell whether it did (a timeout can happen after the provider accepted the message). Either way
            // this code must NEVER become usable, and the row must STAY so the cooldown and hourly cap still apply to the next request.
            Log::error('Registration resume code email failed or unconfirmed: ' . RegistrationCommunicationService::safeError($e->getMessage()));
            $this->retire($challenge);
            return ['sent' => false, 'reason' => 'mail_failed', 'retry_after' => $cooldown];
        }

        return ['sent' => true, 'reason' => null, 'retry_after' => 0];
    }

    /**
     * Check the code and, ONLY IF it is correct, run $apply — all inside one transaction.
     *
     *  - wrong / expired / locked / undelivered / ineligible → returns ['ok' => false, ...]; only the attempt counter is committed.
     *  - correct → $apply($lockedUser) runs; if it throws (database error, validation error, anything) the whole transaction rolls back and the
     *    code remains valid and unconsumed; the exception propagates so the caller can report it. If it returns, the code is consumed in the
     *    same transaction and its return value is handed back as 'result'.
     *
     * @param  Closure(User): mixed  $apply  performs the credential update; runs against the row locked for update
     * @return array{ok: bool, locked: bool, result: mixed}  locked = this wrong guess destroyed the code (attempt cap reached)
     */
    public function verifyAndApply(User $user, string $code, Closure $apply): array
    {
        $max = max(1, (int) config('registration.resume_code_max_attempts'));
        $fail = ['ok' => false, 'locked' => false, 'result' => null];

        return DB::transaction(function () use ($user, $code, $apply, $max, $fail) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();   // same lock order as requestCode(): user row first
            if (!$this->eligible($locked)) {
                return $fail;
            }

            $challenge = Challenge::where('user_id', $locked->id)->whereNull('consumed_at')->latest('id')->lockForUpdate()->first();

            if (
                !$challenge
                || $challenge->delivery_status !== Challenge::DELIVERY_SENT   // fail closed: pending / failed / unknown are never usable
                || $challenge->expires_at->isPast()
                || $challenge->attempts >= $max
            ) {
                return $fail;
            }

            if (!hash_equals($challenge->code_hash, Challenge::hashCode($code))) {
                $attempts = $challenge->attempts + 1;
                $destroyed = $attempts >= $max;
                $challenge->forceFill(['attempts' => $attempts, 'consumed_at' => $destroyed ? now() : null])->save();
                return ['ok' => false, 'locked' => $destroyed, 'result' => null];   // committed: the attempt cap depends on it
            }

            $result = $apply($locked);   // may throw → everything above and below rolls back, the code survives

            $challenge->forceFill(['consumed_at' => now()])->save();   // single use — consumed only because the update succeeded
            return ['ok' => true, 'locked' => false, 'result' => $result];
        });
    }

    /** Make a challenge permanently unusable without deleting it (it must keep counting toward the rate limits). */
    private function retire(Challenge $challenge): void
    {
        try {
            $challenge->forceFill(['delivery_status' => Challenge::DELIVERY_FAILED, 'consumed_at' => now()])->save();
        } catch (\Throwable $e) {
            // Even if this write fails, the row is still 'pending' (never 'sent'), and verifyAndApply() refuses anything that is not 'sent'.
            Log::error('Could not mark resume challenge as failed (it remains unusable): ' . RegistrationCommunicationService::safeError($e->getMessage()));
        }
    }
}
