<?php
// LOCATION: app/Services/Registration/RegistrationTracker.php
//
// Task 2 — records the registration journey: page open (consented) → attempt → started → stages → submitted → KYC submitted.
// Called from the existing registration / KYC controllers. EVERY public method is wrapped by its caller in try/catch: tracking must never
// be able to break registration or KYC submission for an applicant.

namespace App\Services\Registration;

use App\Models\RegistrationApplication;
use App\Models\RegistrationEvent;
use App\Models\RegistrationVisit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

class RegistrationTracker
{
    public function __construct(private RegistrationNotificationService $alerts) {}

    public const VISITOR_TOKEN_RULE = '/^[A-Za-z0-9\-]{16,64}$/';

    // ── Visitor (pre-account) ────────────────────────────────────────────────

    /** Returns the visit, or null when tracking is disabled or consent was not given (in which case NOTHING is stored). */
    public function recordVisit(string $rawToken, bool $consent): ?RegistrationVisit
    {
        if (!config('registration.visit_tracking_enabled') || !$consent) return null;

        $hash = RegistrationVisit::hashToken($rawToken);
        $visit = RegistrationVisit::where('token_hash', $hash)->first();

        if ($visit) {
            $visit->forceFill(['last_seen_at' => now(), 'page_views' => $visit->page_views + 1])->save();
            return $visit;
        }

        // Global ceiling on NEW anonymous rows per hour. The endpoint is public and only throttled per IP, so a distributed bot could otherwise
        // grow this table without limit. Over the ceiling a visit is simply not recorded (the visitor is unaffected).
        $bucket = 'registration:visit-rows:' . now()->format('YmdH');
        Cache::add($bucket, 0, 3700);
        if ((int) Cache::increment($bucket) > (int) config('registration.visit_max_new_per_hour')) return null;

        try {
            $visit = RegistrationVisit::create([
                'token_hash'             => $hash,
                'consent_notice_version' => config('registration.tracking_notice_version'),
                'opened_at'              => now(),
                'last_seen_at'           => now(),
            ]);
        } catch (QueryException $e) {
            // Two first-visits with the same token raced: the unique index decided; use the winner.
            return RegistrationVisit::where('token_hash', $hash)->first();
        }

        RegistrationEvent::record('page_opened', null, null, null, null, null, [], $visit->id);
        $this->alerts->visitorOpened($visit->id);

        return $visit;
    }

    // ── Applicant ────────────────────────────────────────────────────────────

    /** Creates the application row on first sight (idempotent). Does not send alerts. */
    public function ensureApplication(User $user): RegistrationApplication
    {
        return RegistrationApplication::firstOrCreate(
            ['user_id' => $user->id],
            [
                'review_status'    => RegistrationApplication::PENDING,
                'stage_reached'    => max(1, (int) $user->registration_stage),
                'started_at'       => $user->created_at ?? now(),
                'last_activity_at' => now(),
            ]
        );
    }

    /** Stage-1 submission: a registration ATTEMPT that created ($resumed=false) or resumed an application. */
    public function attempt(User $user, ?string $rawVisitorToken, bool $resumed): RegistrationApplication
    {
        $app = $this->ensureApplication($user);
        $created = $app->wasRecentlyCreated;

        $visit = null;
        if ($rawVisitorToken && preg_match(self::VISITOR_TOKEN_RULE, $rawVisitorToken)) {
            // Only a visit that was recorded WITH consent can exist, so linking never introduces data the visitor did not agree to.
            $visit = RegistrationVisit::where('token_hash', RegistrationVisit::hashToken($rawVisitorToken))->first();
        }
        if ($visit && !$visit->user_id) {
            $visit->forceFill(['user_id' => $user->id, 'attempted_at' => now()])->save();
            $app->forceFill(['visit_id' => $visit->id])->save();
        }

        $app->forceFill(['last_activity_at' => now()])->save();

        if ($created || !$resumed) {
            RegistrationEvent::record('application_started', $app, null, null, $app->review_status, null, [], $visit?->id);
            $this->alerts->applicationStarted($app->id);
        } else {
            RegistrationEvent::record('application_resumed', $app, null, null, null, null, [], $visit?->id);
        }

        return $app;
    }

    // ── Resume (ownership-verified continuation of an unfinished registration) ──

    /** A one-time code was emailed to the owner of an unfinished registration. Nothing about the account changed. */
    public function resumeCodeRequested(User $user): void
    {
        RegistrationEvent::record('resume_code_requested', $this->ensureApplication($user));
    }

    /** Too many wrong codes: the code was destroyed. Visible to the team as a possible takeover attempt. */
    public function resumeLockedOut(User $user): void
    {
        RegistrationEvent::record('resume_code_locked', $this->ensureApplication($user));
    }

    /** The owner proved control of the email and continued; credentials were replaced and old sessions revoked. */
    public function resumeVerified(User $user, ?string $rawVisitorToken): void
    {
        $app = $this->attempt($user, $rawVisitorToken, true);
        RegistrationEvent::record('resume_verified', $app, null, null, null, null, ['sessions_revoked' => true]);
    }

    /** Email already belongs to a COMPLETED account: record the attempt against that application without exposing anything to the caller. */
    public function blockedDuplicate(User $existing): void
    {
        $app = $this->ensureApplication($existing);
        // Unauthenticated endpoint: collapse repeats so a script cannot flood an applicant's history (one entry per hour).
        $recent = RegistrationEvent::where('application_id', $app->id)->where('event_type', 'attempt_blocked_duplicate')
            ->where('created_at', '>=', now()->subHour())->exists();
        if (!$recent) RegistrationEvent::record('attempt_blocked_duplicate', $app);
    }

    /** A registration stage (2, 3 or 4) was completed. Stage 4 hands the application to the team. */
    public function stageCompleted(User $user, int $stage): RegistrationApplication
    {
        $app = $this->ensureApplication($user);

        $updates = ['last_activity_at' => now()];
        if ($stage > (int) $app->stage_reached) $updates['stage_reached'] = $stage;
        $app->forceFill($updates)->save();

        RegistrationEvent::record('stage_completed', $app, null, null, null, null, ['stage' => $stage]);

        if ($stage >= 4) $this->submit($app);

        return $app;
    }

    private function submit(RegistrationApplication $app): void
    {
        $app->forceFill(['details_completed_at' => $app->details_completed_at ?? now()])->save();

        if ($app->review_status !== RegistrationApplication::PENDING) return;   // idempotent: re-posting stage 4 never re-submits or re-alerts

        $app->forceFill(['review_status' => RegistrationApplication::SUBMITTED, 'submitted_at' => now()])->save();
        RegistrationEvent::record('application_submitted', $app, null, RegistrationApplication::PENDING, RegistrationApplication::SUBMITTED);
        $this->alerts->applicationSubmitted($app->id);
    }

    /** The investor uploaded (or re-uploaded) KYC documents. Anything but an in-progress review returns to "Submitted". */
    public function kycSubmitted(User $user): RegistrationApplication
    {
        $app = $this->ensureApplication($user);
        $from = $app->review_status;
        $sequence = (string) (RegistrationEvent::where('application_id', $app->id)->where('event_type', 'kyc_submitted')->count() + 1);

        $updates = ['kyc_submitted_at' => now(), 'last_activity_at' => now()];
        $to = $from;
        if ($from !== RegistrationApplication::UNDER_REVIEW) {
            $to = RegistrationApplication::SUBMITTED;
            $updates += ['review_status' => $to, 'decided_at' => null, 'decided_by' => null, 'decision_reason' => null];
            if (!$app->submitted_at) $updates['submitted_at'] = now();
        }
        $app->forceFill($updates)->save();

        RegistrationEvent::record('kyc_submitted', $app, null, $from, $to, null, ['sequence' => (int) $sequence]);
        $this->alerts->kycSubmitted($app->id, $sequence);

        return $app;
    }

    /** An Admin decided through the existing /api/admin/kyc routes: keep the application consistent with the user's KYC flag. */
    public function adminKycDecision(User $user, string $decision, ?string $reason, ?User $admin): void
    {
        if (!in_array($decision, [RegistrationApplication::APPROVED, RegistrationApplication::REJECTED], true)) return;

        $app = $this->ensureApplication($user);
        $from = $app->review_status;
        $app->forceFill([
            'review_status'    => $decision,
            'decided_at'       => now(),
            'decided_by'       => $admin?->id,
            'decision_reason'  => $decision === RegistrationApplication::REJECTED ? $reason : null,
            'last_activity_at' => now(),
        ])->save();

        RegistrationEvent::record('admin_kyc_decision', $app, $admin, $from, $decision, $reason, ['via' => 'admin_kyc']);
    }
}
