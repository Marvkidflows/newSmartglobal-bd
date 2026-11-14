<?php
// Task 5: referral rewards ledger row. Status is changed ONLY through App\Services\Task5\ReferralRewardService (never mass-assigned from a request).
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReferralReward extends Model
{
    public const STATUSES = ['pending', 'approved', 'credited', 'reversed', 'rejected'];

    protected $guarded = ['*'];   // every write goes through the service with forceFill(); mass assignment is impossible by construction
    protected $casts = ['amount' => 'decimal:2', 'eligibility_detail' => 'array', 'flags' => 'array', 'suspicious' => 'boolean',
        'eligibility_checked_at' => 'datetime', 'registration_started_at' => 'datetime', 'registration_completed_at' => 'datetime', 'activated_at' => 'datetime',
        'decided_at' => 'datetime', 'credited_at' => 'datetime', 'reversed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Referral rewards are never deleted; reject or reverse them (history is kept).'));
    }

    public function referrer() { return $this->belongsTo(User::class, 'referrer_id'); }
    public function referred() { return $this->belongsTo(User::class, 'referred_id'); }
    public function events()   { return $this->hasMany(ReferralRewardEvent::class, 'reward_id')->orderBy('id'); }
}
