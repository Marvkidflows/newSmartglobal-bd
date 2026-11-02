<?php
// LOCATION: app/Models/WithdrawalComponent.php — Append-only classification of a withdrawal into principal (position-specific) and verified profit.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class WithdrawalComponent extends Model
{
    protected $table = 'withdrawal_components';
    public $timestamps = false;
    protected $fillable = ['withdrawal_id', 'investment_id', 'component', 'amount', 'component_key', 'verification_note', 'classified_by', 'classified_by_name', 'created_at'];
    protected $casts = ['amount' => 'decimal:2', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('WithdrawalComponent records are immutable; record a new row (an auditable adjustment) instead.'));
        static::deleting(fn () => throw new LogicException('WithdrawalComponent records cannot be deleted.'));
    }
}
