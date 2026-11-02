<?php
// LOCATION: app/Models/InvestmentFlag.php — Append-only disputed / reversed / under-review marks on an investment position.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvestmentFlag extends Model
{
    protected $table = 'investment_flags';
    public $timestamps = false;
    protected $fillable = ['investment_id', 'flag_type', 'action', 'reason', 'actor_id', 'actor_name', 'actor_role', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('InvestmentFlag records are immutable; record a new row (an auditable adjustment) instead.'));
        static::deleting(fn () => throw new LogicException('InvestmentFlag records cannot be deleted.'));
    }
}
