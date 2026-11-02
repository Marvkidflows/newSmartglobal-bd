<?php
// LOCATION: app/Models/InvestmentAllocation.php — Append-only record of a wallet → investment allocation with the plan terms that applied.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvestmentAllocation extends Model
{
    protected $table = 'investment_allocations';
    public $timestamps = false;
    protected $fillable = ['user_id', 'investment_plan_id', 'investment_id', 'amount', 'plan_terms', 'entry_group', 'status', 'created_at'];
    protected $casts = ['amount' => 'decimal:2', 'plan_terms' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('InvestmentAllocation records are immutable; record a new row (an auditable adjustment) instead.'));
        static::deleting(fn () => throw new LogicException('InvestmentAllocation records cannot be deleted.'));
    }
}
