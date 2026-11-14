<?php
// Task 5: versioned, append-only withdrawal-fee policy.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class WithdrawalFeePolicy extends Model
{
    public $timestamps = false;
    public const TYPES = ['none', 'fixed', 'percentage', 'combined'];
    protected $fillable = ['fee_type', 'fixed_amount', 'percentage', 'min_fee', 'max_fee', 'currency', 'is_active', 'policy_approved', 'approval_reference',
        'approved_by', 'effective_from', 'reason', 'configured_by', 'configured_by_name', 'created_at'];
    protected $casts = ['fixed_amount' => 'decimal:2', 'percentage' => 'decimal:4', 'min_fee' => 'decimal:2', 'max_fee' => 'decimal:2',
        'is_active' => 'boolean', 'policy_approved' => 'boolean', 'effective_from' => 'datetime', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Fee policy versions are immutable; record a new version.'));
        static::deleting(fn () => throw new LogicException('Fee policy versions cannot be deleted.'));
    }

    /** Version in force now (may be inactive/unapproved - the calculator decides whether it charges anything). */
    public static function current(): ?self
    {
        return static::where('effective_from', '<=', now())->orderByDesc('effective_from')->orderByDesc('id')->first();
    }
}
