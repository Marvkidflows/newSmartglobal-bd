<?php
// LOCATION: app/Models/Task.php
//
// NOTE: this replaces a legacy, unused Task model (previously referenced
// only in routes/web.php.backup — dead code from before this feature was
// built). The old `tasks` table it pointed to is untouched and unused;
// this model now maps to the NEW `tasks` table created in Phase 3.5.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Task extends Model
{
    use SoftDeletes;

    // Explicit table name — the class is `Task` (matching how the rest of
    // this codebase refers to it), but the actual table is `shared_tasks`
    // to avoid any collision with the legacy `tasks` table that already
    // exists on the real production database from an older, unrelated
    // feature (see migration comments for full history).
    protected $table = 'shared_tasks';

    public const STATUSES = ['draft', 'active', 'expired', 'cancelled', 'closed'];

    protected $fillable = [
        'task_code', 'task_type_id', 'title', 'description', 'requirements',
        'required_amount', 'status', 'activates_at', 'expires_at',
        'last_window_update', 'window_modified_by', 'window_modified_reason',
        'created_by', 'closed_at', 'deactivated_at', 'deactivated_by', 'deactivation_reason',
    ];

    protected $casts = [
        'required_amount'    => 'decimal:2',
        'activates_at'       => 'datetime',
        'expires_at'         => 'datetime',
        'last_window_update' => 'datetime',
        'closed_at'          => 'datetime',
        'deactivated_at'     => 'datetime',
    ];

    public function taskType() { return $this->belongsTo(TaskType::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function windowModifier() { return $this->belongsTo(User::class, 'window_modified_by'); }
    public function deactivator() { return $this->belongsTo(User::class, 'deactivated_by'); }
    public function assignments() { return $this->hasMany(TaskAssignment::class); }
    public function logs() { return $this->hasMany(TaskActivityLog::class)->orderByDesc('created_at'); }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    protected static function booted()
    {
        // Guarantee task_code is always stored uppercase, no matter which
        // code path creates the Task (controller, seeder, factory, direct
        // Eloquent call) — the investor-side lookup always uppercases the
        // incoming code before searching, so storage must match regardless
        // of the database's collation (some, like SQLite by default, are
        // case-sensitive; relying on MySQL's case-insensitive collation
        // alone would be fragile and engine-dependent).
        static::saving(function (Task $task) {
            if (!empty($task->task_code)) {
                $task->task_code = strtoupper($task->task_code);
            }
        });
    }

    public function getIsExpiredAttribute(): bool
    {
        if (in_array($this->status, ['expired', 'cancelled', 'closed'], true)) {
            return $this->status === 'expired';
        }
        return $this->expires_at !== null && Carbon::now()->gt($this->expires_at);
    }

    public function getLiveStatusAttribute(): string
    {
        if (!in_array($this->status, ['expired', 'cancelled', 'closed'], true) && $this->is_expired) {
            return 'expired';
        }
        // Deactivation is deliberately shown as its own status rather than
        // folded into the stored `status` column — the underlying status
        // (usually 'active') is what resume restores, so it must survive
        // a deactivate/resume cycle unchanged.
        if ($this->isDeactivated() && !in_array($this->status, ['expired', 'cancelled', 'closed'], true)) {
            return 'deactivated';
        }
        return $this->status;
    }

    public function getSecondsRemainingAttribute(): ?int
    {
        if (!$this->expires_at) return null;
        return max(0, (int) Carbon::now()->diffInSeconds($this->expires_at, false));
    }

    /**
     * Server-side expiration enforcement — mirrors the original InvestorTask
     * pattern. Idempotent; safe to call on every request that reads a task.
     */
    public function syncExpiry(): bool
    {
        if (in_array($this->status, ['expired', 'cancelled', 'closed'], true)) {
            return false;
        }
        if (!$this->expires_at || Carbon::now()->lte($this->expires_at)) {
            return false;
        }

        $from = $this->status;
        $this->status = 'expired';
        $this->save();

        $this->logs()->create([
            'actor_id' => null, 'actor_type' => 'system', 'action' => 'expired',
            'from_status' => $from, 'to_status' => 'expired', 'meta' => ['reason' => 'window closed'],
        ]);

        return true;
    }
}
