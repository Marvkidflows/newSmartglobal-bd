<?php
// LOCATION: app/Models/PrincipalMovement.php — Append-only position-level principal ledger (returns to wallet, external payouts, withdrawal principal, approved corrections).

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PrincipalMovement extends Model
{
    protected $table = 'principal_movements';
    public $timestamps = false;
    protected $fillable = ['investment_id', 'user_id', 'kind', 'status', 'amount', 'source_type', 'source_id', 'source_key', 'withdrawal_id', 'entry_group', 'reference', 'evidence', 'recorded_by', 'recorded_by_name', 'verified_by', 'verified_at', 'created_at'];
    protected $casts = ['amount' => 'decimal:2', 'verified_at' => 'datetime', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('PrincipalMovement records are immutable; record a new row (an auditable adjustment) instead.'));
        static::deleting(fn () => throw new LogicException('PrincipalMovement records cannot be deleted.'));
    }
}
