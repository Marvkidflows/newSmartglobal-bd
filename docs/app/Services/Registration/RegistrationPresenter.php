<?php
// LOCATION: app/Services/Registration/RegistrationPresenter.php
//
// Task 2 — the ONLY place that shapes registration data for API responses. Every field is listed explicitly (no ->toArray() on User), so a
// column added to users later — a balance, a PIN hash, anything financial — can never leak to the Registration Team by accident.

namespace App\Services\Registration;

use App\Models\RegistrationApplication;
use App\Models\RegistrationEvent;
use App\Models\User;

class RegistrationPresenter
{
    public const APPLICANT_COLUMNS = [
        'id', 'name', 'full_name', 'email', 'phone', 'country', 'email_verified_at', 'registration_completed', 'registration_stage',
        'id_document_path', 'created_at',
    ];

    /**
     * Document links are rendered by the reviewer UI as <a href> / <img src>. The columns are written only from the upload endpoint today, but a
     * reviewer's browser must never be handed a `javascript:` / `data:` / `http:` value if that ever changes — so only plain https URLs pass.
     */
    public static function safeUrl(?string $url): ?string
    {
        if (!$url || !preg_match('#^https://[^\s<>"\'\\\\]+$#i', $url)) return null;
        return $url;
    }

    private static function iso($d): ?string
    {
        return $d ? $d->toIso8601String() : null;
    }

    public static function summary(RegistrationApplication $a): array
    {
        $u = $a->user;
        return [
            'id'                 => $a->id,
            'applicant'          => [
                'id'      => $u?->id,
                'name'    => $u?->name ?? $u?->full_name,
                'email'   => $u?->email,
                'phone'   => $u?->phone,
                'country' => $u?->country,
            ],
            'status'             => $a->review_status,
            'status_label'       => $a->label(),
            'stage_reached'      => (int) $a->stage_reached,
            'email_verified'     => (bool) $u?->email_verified_at,
            'kyc_documents'      => (bool) ($u?->id_document_path),
            'started_at'         => self::iso($a->started_at),
            'submitted_at'       => self::iso($a->submitted_at),
            'kyc_submitted_at'   => self::iso($a->kyc_submitted_at),
            'decided_at'         => self::iso($a->decided_at),
            'last_activity_at'   => self::iso($a->last_activity_at),
            'assigned_to'        => $a->assignee ? ['id' => $a->assignee->id, 'name' => $a->assignee->name] : null,
            'incomplete'         => $a->isIncomplete(),
            'overdue_follow_up'  => $a->isOverdueForFollowUp(),
            'followup_count'     => (int) $a->followup_count,
            'followup_opted_out' => (bool) $a->followup_opt_out_at,
        ];
    }

    public static function event(RegistrationEvent $e): array
    {
        return [
            'id'          => $e->id,
            'application_id' => $e->application_id,
            'type'        => $e->event_type,
            'from_status' => $e->from_status,
            'to_status'   => $e->to_status,
            'note'        => $e->note,
            'meta'        => $e->meta,
            'actor'       => $e->actor_id ? ['id' => $e->actor_id, 'name' => $e->actor?->name, 'role' => $e->actor_role] : null,
            'created_at'  => self::iso($e->created_at),
        ];
    }

    /** Full KYC/review view for a single application. Contains no balance, wallet, deposit, withdrawal or investment data. */
    public static function detail(RegistrationApplication $a, User $viewer, array $allowedTransitions, ?string $followUpBlocker): array
    {
        $u = $a->user;
        $a->loadMissing(['assignee:id,name', 'decider:id,name']);

        return self::summary($a) + [
            'applicant_details' => [
                'name'                  => $u->name ?? $u->full_name,
                'email'                 => $u->email,
                'email_verified_at'     => self::iso($u->email_verified_at),
                'phone'                 => $u->phone,
                'country'               => $u->country,
                'date_of_birth'         => optional($u->date_of_birth)->toDateString(),
                'residential_address'   => $u->residential_address,
                'address_line_2'        => $u->address_line_2,
                'city'                  => $u->city,
                'state'                 => $u->state,
                'postal_code'           => $u->postal_code,
                'employment_status'     => $u->employment_status,
                'annual_income_range'   => $u->annual_income_range,
                'source_of_funds'       => $u->source_of_funds,
                'investment_experience' => $u->investment_experience,
                'risk_tolerance'        => $u->risk_tolerance,
                'investment_objectives' => $u->investment_objectives,
                'privacy_policy_acknowledged_at' => self::iso($u->privacy_policy_acknowledged_at ?? null),
                'registered_at'         => self::iso($u->created_at),
            ],
            'kyc' => [
                'id_type'          => $u->id_type,
                'id_number'        => $u->id_number,
                'id_document_url'  => self::safeUrl($u->id_document_path),
                'selfie_url'       => self::safeUrl($u->selfie_path),
                'user_kyc_status'  => $u->kyc_status_safe,
                'verified_at'      => self::iso($u->kyc_verified_at),
                'rejection_reason' => $u->kyc_rejection_reason,
            ],
            'review' => [
                'review_started_at' => self::iso($a->review_started_at),
                'decided_by'        => $a->decider ? ['id' => $a->decider->id, 'name' => $a->decider->name] : null,
                'decision_reason'   => $a->decision_reason,
                'info_request'      => $a->info_request,
            ],
            'permissions' => [
                'allowed_transitions' => $allowedTransitions,
                'can_decide'          => RegistrationReviewService::roleMayDecide($viewer),
                'can_assign_others'   => RegistrationReviewService::roleMayDecide($viewer),
                'follow_up_blocked_reason' => $followUpBlocker,
            ],
        ];
    }
}
