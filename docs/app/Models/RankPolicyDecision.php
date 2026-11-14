<?php
// LOCATION: app/Models/RankPolicyDecision.php — Append-only formal sign-off that must exist before a money rank criterion can be activated.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RankPolicyDecision extends Model
{
    protected $table = 'rank_policy_decisions';
    public $timestamps = false;
    protected $fillable = ['topic', 'status', 'approval_reference', 'note', 'decided_by', 'decided_by_name', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('RankPolicyDecision records are immutable; record a new row (an auditable adjustment) instead.'));
        static::deleting(fn () => throw new LogicException('RankPolicyDecision records cannot be deleted.'));
    }
}
