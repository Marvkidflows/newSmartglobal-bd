<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReferralRewardEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['reward_id', 'event', 'from_status', 'to_status', 'reason', 'detail', 'actor_id', 'actor_name', 'created_at'];
    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reward events are immutable.'));
        static::deleting(fn () => throw new LogicException('Reward events cannot be deleted.'));
    }
}
