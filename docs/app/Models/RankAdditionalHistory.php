<?php
// LOCATION: app/Models/RankAdditionalHistory.php
// Append-only history of changes to the optional criteria (who / when / before → after / why).

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RankAdditionalHistory extends Model
{
    protected $table = 'rank_additional_history';

    public $timestamps = false;

    protected $fillable = ['criterion_key', 'action', 'actor_id', 'actor_name', 'actor_role', 'previous', 'new', 'reason', 'created_at'];

    protected $casts = ['previous' => 'array', 'new' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rank configuration history is immutable.'));
        static::deleting(fn () => throw new LogicException('Rank configuration history cannot be deleted.'));
    }
}
