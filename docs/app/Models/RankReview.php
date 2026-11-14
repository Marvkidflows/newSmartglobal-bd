<?php
// LOCATION: app/Models/RankReview.php
//
// A CALCULATED rank eligibility waiting for (or already given) a human decision.
// The engine only ever creates 'pending' rows (or withdraws them); approval/rejection is done by an
// authorised Admin / Financial Team member. A decided review is immutable.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RankReview extends Model
{
    public const PENDING = 'pending', APPROVED = 'approved', REJECTED = 'rejected', WITHDRAWN = 'withdrawn';

    protected $guarded = ['*'];

    protected $casts = ['evaluation' => 'array', 'decided_at' => 'datetime', 'notified_at' => 'datetime'];

    protected static function booted(): void
    {
        // Once decided (or withdrawn) the record is evidence and cannot be edited.
        static::updating(function (self $m) {
            if ($m->getOriginal('status') !== self::PENDING) {
                throw new LogicException('A decided rank review is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Rank reviews cannot be deleted.'));
    }

    public function scopePending($q)
    {
        return $q->where('status', self::PENDING);
    }

    public function investor()
    {
        return $this->belongsTo(User::class, 'investor_id');
    }

    public function currentRank()
    {
        return $this->belongsTo(InvestorRank::class, 'current_rank_id');
    }

    public function proposedRank()
    {
        return $this->belongsTo(InvestorRank::class, 'proposed_rank_id');
    }
}
