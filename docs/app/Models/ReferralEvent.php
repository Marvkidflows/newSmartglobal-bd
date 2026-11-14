<?php
// Task 5: referral funnel event (link visited, registration started/completed, activated, eligibility confirmed). Append-only.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReferralEvent extends Model
{
    public $timestamps = false;
    public const EVENTS = ['link_visited', 'registration_started', 'registration_completed', 'activated', 'eligibility_confirmed'];
    protected $fillable = ['referrer_id', 'referred_id', 'referral_code', 'event', 'meta', 'created_at'];
    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Referral events are immutable.'));
        static::deleting(fn () => throw new LogicException('Referral events cannot be deleted.'));
    }
}
