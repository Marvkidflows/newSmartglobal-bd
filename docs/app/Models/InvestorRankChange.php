<?php
// LOCATION: app/Models/InvestorRankChange.php
// Append-only audit record of every rank decision (automatic or manual).
// Corrections are made by writing a NEW row, never by editing an old one.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvestorRankChange extends Model
{
    public const AUTOMATIC_UPGRADE  = 'automatic_upgrade';
    public const MANUAL_ASSIGNMENT  = 'manual_assignment';
    public const MANUAL_UPGRADE     = 'manual_upgrade';
    public const DOWNGRADE          = 'downgrade';
    public const AUTHORIZED_OVERRIDE = 'authorized_override';
    public const REVIEW_RETAINED    = 'review_retained';   // decision record: rank unchanged
    public const APPROVED_UPGRADE   = 'approved_upgrade';  // calculated eligibility approved by an authorised reviewer
    public const SUSPENDED          = 'suspended';         // decision record: rank kept, badge hidden
    public const REVOKED            = 'revoked';           // decision record: rank withdrawn, badge hidden
    public const REINSTATED         = 'reinstated';        // decision record: rank active again

    public const TYPES = [
        self::AUTOMATIC_UPGRADE, self::MANUAL_ASSIGNMENT, self::MANUAL_UPGRADE,
        self::DOWNGRADE, self::AUTHORIZED_OVERRIDE, self::REVIEW_RETAINED,
        self::APPROVED_UPGRADE, self::SUSPENDED, self::REVOKED, self::REINSTATED,
    ];

    public $timestamps = false;

    protected $fillable = [
        'reference', 'investor_id', 'account_id', 'sequence', 'previous_rank_id', 'new_rank_id',
        'change_type', 'is_automatic', 'actor_id', 'actor_name', 'actor_role', 'reason',
        'override_justification', 'criteria_snapshot', 'evaluation_run_id', 'created_at',
    ];

    protected $casts = [
        'is_automatic'      => 'boolean',
        'criteria_snapshot' => 'array',
        'created_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rank audit records are immutable.'));
        static::deleting(fn () => throw new LogicException('Rank audit records cannot be deleted.'));
    }

    public function investor()
    {
        return $this->belongsTo(User::class, 'investor_id');
    }

    public function previousRank()
    {
        return $this->belongsTo(InvestorRank::class, 'previous_rank_id');
    }

    public function newRank()
    {
        return $this->belongsTo(InvestorRank::class, 'new_rank_id');
    }

    public function notification()
    {
        return $this->hasOne(RankChangeNotification::class, 'investor_rank_change_id');
    }
}
