<?php
// Task 5: versioned, append-only referral policy (reward amount + eligibility criteria). History is never edited or deleted.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReferralPolicy extends Model
{
    public $timestamps = false;
    protected $fillable = ['reward_amount', 'previous_reward_amount', 'currency', 'criteria', 'policy_approved', 'approval_reference', 'approved_by', 'approved_at',
        'effective_from', 'reason', 'changed_by', 'changed_by_name', 'created_at'];
    protected $casts = ['reward_amount' => 'decimal:2', 'previous_reward_amount' => 'decimal:2', 'criteria' => 'array', 'policy_approved' => 'boolean',
        'approved_at' => 'datetime', 'effective_from' => 'datetime', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Referral policy versions are immutable; record a new version.'));
        static::deleting(fn () => throw new LogicException('Referral policy versions cannot be deleted.'));
    }

    /** The version in force now: latest row whose effective_from has passed. */
    public static function current(): ?self
    {
        return static::where('effective_from', '<=', now())->orderByDesc('effective_from')->orderByDesc('id')->first();
    }
}
