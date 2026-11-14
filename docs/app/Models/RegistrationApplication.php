<?php
// LOCATION: app/Models/RegistrationApplication.php
// Review-workflow state for one applicant. users.kyc_* remains the authoritative KYC flag used by the rest of the platform; this row
// tracks the Registration Team's process around it.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationApplication extends Model
{
    public const PENDING                 = 'pending';
    public const SUBMITTED               = 'submitted';
    public const UNDER_REVIEW            = 'under_review';
    public const APPROVED                = 'approved';
    public const REJECTED                = 'rejected';
    public const ADDITIONAL_INFO_REQUIRED = 'additional_info_required';

    public const STATUSES = [
        self::PENDING, self::SUBMITTED, self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::ADDITIONAL_INFO_REQUIRED,
    ];

    public const LABELS = [
        self::PENDING                  => 'Pending',
        self::SUBMITTED                => 'Submitted',
        self::UNDER_REVIEW             => 'Under Review',
        self::APPROVED                 => 'Approved',
        self::REJECTED                 => 'Rejected',
        self::ADDITIONAL_INFO_REQUIRED => 'Additional Information Required',
    ];

    /** Manual (reviewer-driven) transitions. System transitions (pending→submitted, resubmission) are made by RegistrationTracker. */
    public const TRANSITIONS = [
        self::SUBMITTED                => [self::UNDER_REVIEW, self::ADDITIONAL_INFO_REQUIRED, self::APPROVED, self::REJECTED],
        self::UNDER_REVIEW             => [self::SUBMITTED, self::ADDITIONAL_INFO_REQUIRED, self::APPROVED, self::REJECTED],
        self::ADDITIONAL_INFO_REQUIRED => [self::UNDER_REVIEW, self::REJECTED],
        self::REJECTED                 => [self::UNDER_REVIEW],
        self::APPROVED                 => [],   // final here; reversing a verification is an Admin action (existing /api/admin/kyc routes)
        self::PENDING                  => [],   // the applicant has not submitted yet — only follow-up applies
    ];

    /** Statuses that only a lead (or admin) may set. */
    public const DECISION_STATUSES = [self::APPROVED, self::REJECTED];

    protected $fillable = [
        'user_id', 'visit_id', 'review_status', 'stage_reached', 'started_at', 'details_completed_at', 'submitted_at', 'kyc_submitted_at',
        'review_started_at', 'decided_at', 'last_activity_at', 'assigned_to', 'decided_by', 'decision_reason', 'info_request',
        'followup_count', 'last_followup_at', 'followup_opt_out_at', 'backfilled',
    ];

    protected $casts = [
        'started_at'           => 'datetime',
        'details_completed_at' => 'datetime',
        'submitted_at'         => 'datetime',
        'kyc_submitted_at'     => 'datetime',
        'review_started_at'    => 'datetime',
        'decided_at'           => 'datetime',
        'last_activity_at'     => 'datetime',
        'last_followup_at'     => 'datetime',
        'followup_opt_out_at'  => 'datetime',
        'backfilled'           => 'boolean',
    ];

    public function user()          { return $this->belongsTo(User::class); }
    public function assignee()      { return $this->belongsTo(User::class, 'assigned_to'); }
    public function decider()       { return $this->belongsTo(User::class, 'decided_by'); }
    public function events()        { return $this->hasMany(RegistrationEvent::class, 'application_id'); }
    public function communications(){ return $this->hasMany(RegistrationCommunication::class, 'application_id'); }

    public function label(): string
    {
        return self::LABELS[$this->review_status] ?? $this->review_status;
    }

    public function isIncomplete(): bool
    {
        return $this->review_status === self::PENDING;
    }

    public function isOverdueForFollowUp(): bool
    {
        if (!$this->isIncomplete() || !$this->last_activity_at) return false;
        return $this->last_activity_at->lte(now()->subHours((int) config('registration.incomplete_after_hours')));
    }
}
