<?php
// LOCATION: app/Models/RankBenefit.php
// One benefit/privilege of one rank (SSI Investor Rank, Benefits and Privileges Framework §3). Never deleted:
// a benefit is SUSPENDED, so its history stays available for audit.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RankBenefit extends Model
{
    public const STATUS_ACTIVE      = 'active';                  // available and active
    public const STATUS_ELIGIBILITY = 'subject_to_eligibility';  // available when its conditions are met
    public const STATUS_PENDING     = 'pending_approval';        // proposed, not yet approved by SSI management
    public const STATUS_SUSPENDED   = 'suspended';               // suspended or unavailable

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ELIGIBILITY, self::STATUS_PENDING, self::STATUS_SUSPENDED];

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE      => 'Available and active',
        self::STATUS_ELIGIBILITY => 'Subject to eligibility',
        self::STATUS_PENDING     => 'Pending approval',
        self::STATUS_SUSPENDED   => 'Suspended or unavailable',
    ];

    public const CATEGORIES = ['recognition', 'service', 'reinvestment', 'loyalty', 'loan', 'roi'];
    public const FULFILMENTS = ['platform', 'operational', 'future_feature'];

    protected $fillable = [
        'key', 'investor_rank_id', 'category', 'title', 'description', 'status', 'fulfilment', 'is_financial',
        'requires_approval', 'requires_consent', 'cumulative', 'is_published', 'display_order', 'effective_from',
        'effective_to', 'approved_by', 'approved_by_name', 'approved_at', 'approval_reference', 'version',
    ];

    protected $casts = [
        'is_financial' => 'boolean', 'requires_approval' => 'boolean', 'requires_consent' => 'boolean',
        'cumulative' => 'boolean', 'is_published' => 'boolean', 'display_order' => 'integer', 'version' => 'integer',
        'effective_from' => 'date', 'effective_to' => 'date', 'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Benefits are suspended, never deleted, so their history stays auditable.'));
    }

    public function rank()
    {
        return $this->belongsTo(InvestorRank::class, 'investor_rank_id');
    }

    /** Snapshot used in the audit trail. */
    public function snapshot(): array
    {
        return [
            'key' => $this->key, 'title' => $this->title, 'description' => $this->description, 'status' => $this->status,
            'is_published' => (bool) $this->is_published, 'display_order' => (int) $this->display_order,
            'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(),
            'approval_reference' => $this->approval_reference, 'version' => (int) $this->version,
        ];
    }
}
