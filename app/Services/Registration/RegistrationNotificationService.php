<?php
// LOCATION: app/Services/Registration/RegistrationNotificationService.php
//
// Task 2 — internal alerts to the Registration Team (in-app). Every alert carries a dedupe key recorded in registration_alerts under a UNIQUE
// constraint, so the same event can never notify the team twice even if the triggering code runs twice or concurrently.
// Fans out to ACTIVE registration_member / registration_lead accounts only.

namespace App\Services\Registration;

use App\Models\RegistrationAlert;
use App\Models\User;
use App\Notifications\RegistrationAlertNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

class RegistrationNotificationService
{
    public const TEAM_ROLES = ['registration_member', 'registration_lead'];

    /** Returns true when the alert was sent, false when it was a duplicate (or had no recipients). */
    public function notifyTeam(string $dedupeKey, string $title, string $message, string $type, array $data = []): bool
    {
        try {
            RegistrationAlert::create(['dedupe_key' => $dedupeKey, 'alert_type' => $type, 'created_at' => now()]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) return false;   // already alerted for this exact event
            throw $e;
        }

        $notification = new RegistrationAlertNotification($title, $message, $type, $data);
        User::whereIn('role', self::TEAM_ROLES)->where('status', 'active')->get()
            ->each(fn (User $u) => $u->notify($notification));

        return true;
    }

    /** A consenting visitor opened the registration page. Hourly-capped so bots cannot flood the team. */
    public function visitorOpened(int $visitId): void
    {
        if (!config('registration.notify_on_visit')) return;

        $bucket = 'registration:visit-alerts:' . now()->format('YmdH');
        Cache::add($bucket, 0, 3700);
        if ((int) Cache::increment($bucket) > (int) config('registration.visit_alert_max_per_hour')) return;

        $this->notifyTeam("visit:{$visitId}", 'Registration page opened', 'A visitor who accepted the tracking notice opened the registration page.', 'registration_page_opened');
    }

    public function applicationStarted(int $applicationId): void
    {
        $this->notifyTeam("started:{$applicationId}", 'Registration started', 'A prospective investor began an application.', 'registration_started', ['application_id' => $applicationId]);
    }

    public function applicationSubmitted(int $applicationId): void
    {
        $this->notifyTeam("submitted:{$applicationId}", 'Application submitted', 'An applicant completed registration and is awaiting review.', 'registration_submitted', ['application_id' => $applicationId]);
    }

    /** $sequence distinguishes a first submission from a later resubmission so each gets exactly one alert. */
    public function kycSubmitted(int $applicationId, string $sequence): void
    {
        $this->notifyTeam("kyc:{$applicationId}:{$sequence}", 'KYC documents submitted', 'An applicant submitted identity documents for review.', 'registration_kyc_submitted', ['application_id' => $applicationId]);
    }

    public function incompleteNeedsFollowUp(int $applicationId): bool
    {
        return $this->notifyTeam("incomplete:{$applicationId}", 'Incomplete application needs follow-up', 'An application has been inactive and is still incomplete.', 'registration_incomplete', ['application_id' => $applicationId]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[0] ?? $e->getCode());
        return $code === '23000' || $code === '23505' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
