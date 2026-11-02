<?php
// LOCATION: app/Models/WalletLedgerEntry.php — Append-only wallet ledger with separate classifications and linked legs.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class WalletLedgerEntry extends Model
{
    protected $table = 'wallet_ledger_entries';
    public $timestamps = false;
    protected $fillable = ['entry_group', 'entry_key', 'user_id', 'classification', 'direction', 'amount', 'currency', 'deposit_id', 'withdrawal_id', 'investment_id', 'allocation_id', 'principal_movement_id', 'reference', 'balance_after', 'note', 'actor_id', 'actor_name', 'created_at'];
    protected $casts = ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('WalletLedgerEntry records are immutable; record a new row (an auditable adjustment) instead.'));
        static::deleting(fn () => throw new LogicException('WalletLedgerEntry records cannot be deleted.'));
    }
}
