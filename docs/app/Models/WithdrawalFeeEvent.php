<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class WithdrawalFeeEvent extends Model
{
    public $timestamps = false;
    public const EVENTS = ['charged', 'collected', 'not_collected', 'reversed', 'waived', 'review'];
    protected $fillable = ['withdrawal_id', 'user_id', 'event', 'amount', 'currency', 'event_key', 'reference', 'related_event_id', 'reason', 'actor_id', 'actor_name', 'created_at'];
    protected $casts = ['amount' => 'decimal:2', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Withdrawal fee events are immutable.'));
        static::deleting(fn () => throw new LogicException('Withdrawal fee events cannot be deleted.'));
    }
}
