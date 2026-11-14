<?php
// LOCATION: app/Models/RankCriteriaHistory.php
// Append-only record of every criteria configuration change.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RankCriteriaHistory extends Model
{
    protected $table = 'rank_criteria_history';

    public $timestamps = false;

    protected $fillable = [
        'rank_criterion_id', 'investor_rank_id', 'version', 'action',
        'actor_id', 'actor_name', 'previous', 'new', 'reason', 'created_at',
    ];

    protected $casts = [
        'previous'   => 'array',
        'new'        => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Criteria history is immutable.'));
        static::deleting(fn () => throw new LogicException('Criteria history cannot be deleted.'));
    }

    public function rank()
    {
        return $this->belongsTo(InvestorRank::class, 'investor_rank_id');
    }
}
