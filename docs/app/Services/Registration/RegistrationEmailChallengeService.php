<?php
// LOCATION: app/Services/Registration/RegistrationEmailChallengeService.php
//
// Registration security (enumeration closure). Proof of control of a NEW email address before any account is created.
// Mirrors RegistrationResumeService (same limits, same fail-closed delivery handling) but is keyed by the email address because no user row exists.
//
//   * CODE      6 digits (random_int), stored only as an HMAC, single use, expires (default 10 min), destroyed after N wrong guesses (default 5);
//               issuing a new code invalidates the previous one.
//   * DELIVERY  usable only when delivery_status = 'sent'; a failed or unconfirmed send keeps its row (marked failed + consumed) so the cooldown and
//               hourly cap still count it — a flaky mail provider cannot be used to request codes without limit.
//   * LIMITS    per EMAIL ADDRESS (database-enforced, so rotating IP addresses does not help): minimum gap between codes and a maximum per hour,
//               counted over ALL rows. This also caps how many messages a victim's inbox can receive.
//   * ATOMIC    verifyAndApply() checks the code, runs the account creation AND consumes the code in ONE transaction; if creation fails everything
//               rolls back and the code stays valid. A wrong guess is the only thing committed on a failure path (the attempt counter).
//   * NO ORACLE every verification failure returns the same result, and a missing challenge costs the same hash work as a wrong one.

namespace App\Services\Registration;

use App\Mail\RegistrationEmailCodeMail;
use App\Models\RegistrationEmailChallenge as Challenge;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RegistrationEmailChallengeService
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Issue and email a code unless a limit applies. Never reveals anything to the caller of the HTTP endpoint — the controller runs this after the
     * response has been sent and ignores the outcome.
     * @return array{sent: bool, reason: ?string, retry_after: int}
     */
    public function requestCode(string $email): array
    {
        $email    = self::normalize($email);
        $ttl      = max(1, (int) config('registration.resume_code_ttl_minutes'));
        $cooldown = max(0, (int) config('registration.resume_request_cooldown_sec'));

        try {
            $issued = Cache::lock('reg-email-challenge:' . sha1($email), 10)->block(5, function () use ($email, $ttl, $cooldown) {
                return DB::transaction(function () use ($email, $ttl, $cooldown) {
                    $now  = now();
                    $last = Challenge::where('email', $email)->latest('id')->first();   // ANY row, whatever its delivery state
                    if ($last && $last->created_at->gt($now->copy()->subSeconds($cooldown))) {
                        return ['reason' => 'cooldown', 'retry_after' => max(1, $cooldown - (int) $last->created_at->diffInSeconds($now, true))];
                    }
                    $lastHour = Challenge::where('email', $email)->where('created_at', '>=', $now->copy()->subHour())->count();
                    if ($lastHour >= (int) config('registration.resume_requests_per_hour')) {
                        return ['reason' => 'hourly_cap', 'retry_after' => $cooldown];
                    }

                    Challenge::where('email', $email)->whereNull('consumed_at')->update(['consumed_at' => $now]);   // only one live code

                    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $challenge = Challenge::create([
                        'email'           => $email,
                        'code_hash'       => Challenge::hashCode($code),
                        'attempts'        => 0,
                        'delivery_status' => Challenge::DELIVERY_PENDING,   // NOT usable until the send is confirmed
                        'expires_at'      => $now->copy()->addMinutes($ttl),
                        'created_at'      => $now,
                    ]);
                    return ['challenge' => $challenge, 'code' => $code];
                });
            });
        } catch (LockTimeoutException $e) {
            return ['sent' => false, 'reason' => 'cooldown', 'retry_after' => $cooldown];   // another request for this address is in flight
        }

        if (isset($issued['reason'])) {
            return ['sent' => false, 'reason' => $issued['reason'], 'retry_after' => $issued['retry_after']];
        }

        /** @var Challenge $challenge */
        $challenge = $issued['challenge'];
        try {
            Mail::mailer(config('registration.resume_mailer'))->to($email)->send(new RegistrationEmailCodeMail($issued['code'], $ttl));
            $challenge->forceFill(['delivery_status' => Challenge::DELIVERY_SENT])->save();
        } catch (\Throwable $e) {
            Log::error('Registration email code failed or unconfirmed: ' . RegistrationCommunicationService::safeError($e->getMessage()));
            $this->retire($challenge);
            return ['sent' => false, 'reason' => 'mail_failed', 'retry_after' => $cooldown];
        }

        return ['sent' => true, 'reason' => null, 'retry_after' => 0];
    }

    /**
     * Check the code and, ONLY IF it is correct, run $apply — all in one transaction.
     * @param  Closure(): mixed  $apply  creates the account; if it throws, everything rolls back and the code survives
     * @return array{ok: bool, locked: bool, result: mixed}
     */
    public function verifyAndApply(string $email, string $code, Closure $apply): array
    {
        $email = self::normalize($email);
        $max   = max(1, (int) config('registration.resume_code_max_attempts'));
        $fail  = ['ok' => false, 'locked' => false, 'result' => null];

        return DB::transaction(function () use ($email, $code, $apply, $max, $fail) {
            $challenge = Challenge::where('email', $email)->whereNull('consumed_at')->latest('id')->lockForUpdate()->first();

            if (
                !$challenge
                || $challenge->delivery_status !== Challenge::DELIVERY_SENT   // fail closed: pending / failed / unknown are never usable
                || $challenge->expires_at->isPast()
                || $challenge->attempts >= $max
            ) {
                hash_equals(Challenge::hashCode('000000'), Challenge::hashCode($code));   // same hashing work whether or not a challenge exists
                return $fail;
            }

            if (!hash_equals($challenge->code_hash, Challenge::hashCode($code))) {
                $attempts  = $challenge->attempts + 1;
                $destroyed = $attempts >= $max;
                $challenge->forceFill(['attempts' => $attempts, 'consumed_at' => $destroyed ? now() : null])->save();
                return ['ok' => false, 'locked' => $destroyed, 'result' => null];   // committed: the attempt cap depends on it
            }

            $result = $apply();   // may throw -> rollback, code survives

            $challenge->forceFill(['consumed_at' => now()])->save();   // single use — consumed only because the account was created
            return ['ok' => true, 'locked' => false, 'result' => $result];
        });
    }

    private function retire(Challenge $challenge): void
    {
        try {
            $challenge->forceFill(['delivery_status' => Challenge::DELIVERY_FAILED, 'consumed_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::error('Could not mark registration email challenge as failed (it remains unusable): ' . RegistrationCommunicationService::safeError($e->getMessage()));
        }
    }
}
