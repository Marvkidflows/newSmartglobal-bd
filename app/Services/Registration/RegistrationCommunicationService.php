<?php
// LOCATION: app/Services/Registration/RegistrationCommunicationService.php
//
// Task 2 — the ONLY path by which the Registration Team emails an applicant. Guarantees:
//   * every message is stored (who, to whom, what, when) and its delivery outcome recorded (sent / failed + error);
//   * a unique dedupe_key makes a repeated or concurrent identical send a no-op that returns the original row;
//   * follow-up emails are cooldown-limited, count-capped and refused for applicants who opted out;
//   * transactional notices (information requests, decisions) are not blocked by a follow-up opt-out — they concern the applicant's own
//     application — but are still logged and deduplicated.
// Nothing here touches Task 3's public/investor mailing system; Task 3 will unify consent/unsubscribe across both.

namespace App\Services\Registration;

use App\Mail\RegistrationApplicantMail;
use App\Models\RegistrationApplication;
use App\Models\RegistrationCommunication;
use App\Models\RegistrationEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class RegistrationCommunicationService
{
    /**
     * Transactional message (information request / decision notice). $dedupeKey must identify the triggering event.
     */
    public function sendTransactional(RegistrationApplication $app, ?User $actor, string $kind, string $subject, string $body, string $dedupeKey): RegistrationCommunication
    {
        return $this->deliver($app, $actor, $kind, $subject, $body, $dedupeKey);
    }

    /**
     * Follow-up to an incomplete applicant (or one who owes additional information). Throws RegistrationRuleException when a rule blocks it.
     */
    public function sendFollowUp(RegistrationApplication $app, User $actor, string $subject, string $body): RegistrationCommunication
    {
        return DB::transaction(function () use ($app, $actor, $subject, $body) {
            $locked = RegistrationApplication::whereKey($app->id)->lockForUpdate()->firstOrFail();

            if ($reason = $this->followUpBlocker($locked)) {
                throw new RegistrationRuleException($reason);
            }

            $attempt = RegistrationCommunication::where('application_id', $locked->id)->where('kind', RegistrationCommunication::KIND_FOLLOW_UP)->count() + 1;
            $comm = $this->deliver($locked, $actor, RegistrationCommunication::KIND_FOLLOW_UP, $subject, $body, "followup:{$locked->id}:{$attempt}");

            if ($comm->status === 'sent') {
                $locked->forceFill(['followup_count' => $locked->followup_count + 1, 'last_followup_at' => now()])->save();
            }
            return $comm;
        });
    }


    /**
     * Delivery errors are shown to staff, so strip anything that looks like a credential (API keys, tokens, long opaque strings) and any
     * address-like text before storing. Keeps the diagnostic ("connection refused", "401 Unauthorized") without leaking a secret.
     */
    public static function safeError(string $message): string
    {
        $message = preg_replace('/[A-Za-z0-9_\-\.]{24,}/', '[redacted]', $message);
        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $message);
        return mb_substr($message, 0, 300);
    }

    /** Why a follow-up cannot be sent right now (null = it can). Used by the send path (on the locked row) and by the UI. */
    public function followUpBlocker(RegistrationApplication $app): ?string
    {
        if (!in_array($app->review_status, [RegistrationApplication::PENDING, RegistrationApplication::ADDITIONAL_INFO_REQUIRED], true)) {
            return 'Follow-up is only available for incomplete applications or those awaiting additional information.';
        }
        if ($app->followup_opt_out_at) {
            return 'This applicant has opted out of follow-up messages.';
        }
        $sent = RegistrationCommunication::where('application_id', $app->id)
            ->where('kind', RegistrationCommunication::KIND_FOLLOW_UP)->where('status', 'sent')->count();
        if ($sent >= (int) config('registration.followup_max')) {
            return 'The maximum number of follow-up messages for this application has been reached.';
        }
        $cooldown = (int) config('registration.followup_cooldown_hours');
        if ($app->last_followup_at && $app->last_followup_at->gt(now()->subHours($cooldown))) {
            return 'A follow-up was already sent recently. Next one allowed after ' . $app->last_followup_at->copy()->addHours($cooldown)->toDateTimeString() . ' UTC.';
        }
        return null;
    }

    private function deliver(RegistrationApplication $app, ?User $actor, string $kind, string $subject, string $body, string $dedupeKey): RegistrationCommunication
    {
        $user = $app->user ?? User::find($app->user_id);

        try {
            $comm = RegistrationCommunication::create([
                'application_id' => $app->id,
                'sent_by'        => $actor?->id,
                'kind'           => $kind,
                'to_email'       => $user->email,
                'subject'        => $subject,
                'body'           => $body,
                'status'         => 'queued',
                'dedupe_key'     => $dedupeKey,
            ]);
        } catch (QueryException $e) {
            $existing = RegistrationCommunication::where('dedupe_key', $dedupeKey)->first();
            if ($existing) return $existing;   // duplicate-send prevention: identical event already handled
            throw $e;
        }

        try {
            Mail::to($user->email)->send(new RegistrationApplicantMail($subject, $body, $kind, $user->name ?? $user->full_name));
            $comm->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
            RegistrationEvent::record('communication_sent', $app, $actor, null, null, null, ['communication_id' => $comm->id, 'kind' => $kind]);
        } catch (\Throwable $e) {
            $comm->forceFill(['status' => 'failed', 'error' => self::safeError($e->getMessage())])->save();
            RegistrationEvent::record('communication_failed', $app, $actor, null, null, null, ['communication_id' => $comm->id, 'kind' => $kind]);
            \Log::warning('Registration email failed: ' . $e->getMessage());
        }

        return $comm->fresh();
    }
}
