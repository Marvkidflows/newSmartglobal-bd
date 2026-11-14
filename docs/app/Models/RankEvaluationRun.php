<?php
// LOCATION: app/Models/RankEvaluationRun.php
// Progress record for a (possibly large) queued re-evaluation.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankEvaluationRun extends Model
{
    protected $fillable = [
        'reference', 'trigger', 'investor_rank_id', 'target_level', 'rank_criterion_id', 'triggered_by',
        'status', 'total', 'processed', 'upgraded', 'failed', 'cursor_user_id', 'failures', 'error',
        'started_at', 'finished_at', 'heartbeat_at',
    ];

    protected $casts = [
        'failures'     => 'array',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'heartbeat_at' => 'datetime',
    ];

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'completed_with_errors', 'failed'], true);
    }
}
