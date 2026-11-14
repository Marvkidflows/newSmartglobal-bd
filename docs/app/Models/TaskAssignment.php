<?php
// LOCATION: app/Models/TaskAssignment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class TaskAssignment extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'pending', 'awaiting_activation', 'active', 'in_progress',
        'submitted', 'under_review', 'completed', 'expired', 'cancelled', 'failed',
    ];

    public const TERMINAL_STATUSES = ['completed', 'expired', 'cancelled', 'failed'];

    protected $fillable = [
        'task_id', 'user_id', 'status', 'activated_at', 'code_confirmed_at', 'required_amount',
        'submitted_amount', 'submitted_at', 'submitted_notes',
        'amount_used', 'amount_received', 'profit_loss', 'result_notes', 'final_result',
        'verified_by', 'verified_at', 'completed_at', 'closed_at', 'balance_applied_at',
    ];

    protected $casts = [
        'required_amount'   => 'decimal:2',
        'submitted_amount'  => 'decimal:2',
        'amount_used'       => 'decimal:2',
        'amount_received'   => 'decimal:2',
        'profit_loss'       => 'decimal:2',
        'activated_at'      => 'datetime',
        'code_confirmed_at' => 'datetime',
        'submitted_at'      => 'datetime',
        'verified_at'       => 'datetime',
        'completed_at'      => 'datetime',
        'closed_at'         => 'datetime',
        'balance_applied_at' => 'datetime',
    ];

    public function task() { return $this->belongsTo(Task::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function verifier() { return $this->belongsTo(User::class, 'verified_by'); }
    public function logs() { return $this->hasMany(TaskActivityLog::class)->orderByDesc('created_at'); }

    public function scopeForUser($query, $userId) { return $query->where('user_id', $userId); }
    public function scopeOpen($query) { return $query->whereNotIn('status', self::TERMINAL_STATUSES); }

    /**
     * The amount THIS investor must confirm — their own override if the
     * admin set one, otherwise the shared task's default. This is the
     * value everything (submitCode's match/balance checks, the dashboard
     * display) should actually use, never $task->required_amount directly.
     */
    public function getEffectiveRequiredAmountAttribute(): ?float
    {
        if ($this->required_amount !== null) {
            return (float) $this->required_amount;
        }
        return $this->task?->required_amount !== null ? (float) $this->task->required_amount : null;
    }

    /**
     * The status this assignment should be treated as RIGHT NOW, accounting
     * for the parent task's shared expiry (which the caller must have
     * already synced via $task->syncExpiry() before calling this).
     */
    public function getLiveStatusAttribute(): string
    {
        if (!in_array($this->status, self::TERMINAL_STATUSES, true)
            && $this->relationLoaded('task')
            && $this->task
            && $this->task->live_status === 'expired') {
            return 'expired';
        }
        // Mirror the parent task's deactivated state for display — the
        // stored status underneath stays whatever it was (usually
        // 'awaiting_activation'), same reasoning as Task::live_status.
        if (!in_array($this->status, self::TERMINAL_STATUSES, true)
            && $this->relationLoaded('task')
            && $this->task
            && $this->task->isDeactivated()) {
            return 'deactivated';
        }
        return $this->status;
    }

    /**
     * Persists the expired transition for THIS assignment when the shared
     * task has expired and this assignment hasn't reached a terminal state
     * yet. Call after $task->syncExpiry().
     */
    public function syncExpiryFromTask(): bool
    {
        if (in_array($this->status, self::TERMINAL_STATUSES, true)) {
            return false;
        }
        if (!$this->task || $this->task->status !== 'expired') {
            return false;
        }

        $from = $this->status;
        $this->status = 'expired';
        $this->save();

        $this->logs()->create([
            'task_id' => $this->task_id, 'actor_id' => null, 'actor_type' => 'system',
            'action' => 'expired', 'from_status' => $from, 'to_status' => 'expired',
            'meta' => ['reason' => 'shared task window closed'],
        ]);

        return true;
    }

    /**
     * An investor can confirm their code before the admin's window opens
     * (code_confirmed_at gets set, status stays awaiting_activation). This
     * lazily promotes them to 'active' the moment the window opens — call
     * on every read, same pattern as syncExpiry(). Idempotent.
     */
    public function syncStart(): bool
    {
        if ($this->status !== 'awaiting_activation' || !$this->code_confirmed_at) {
            return false;
        }
        if (!$this->task || $this->task->isDeactivated()) {
            return false;
        }
        if (!$this->task->activates_at || Carbon::now()->lt($this->task->activates_at)) {
            return false;
        }

        $this->status = 'active';
        $this->activated_at = Carbon::now();
        $this->save();

        $this->logs()->create([
            'task_id' => $this->task_id, 'actor_id' => null, 'actor_type' => 'system',
            'action' => 'auto_activated_on_window_open', 'from_status' => 'awaiting_activation', 'to_status' => 'active',
        ]);

        return true;
    }

    /**
     * Seconds until the shared task's window opens — null once it already
     * has, or if no activation time is set. Purely for the investor's
     * "starts in" countdown display; the server enforces the real window
     * via syncStart()/submitCode(), not this value.
     */
    public function getSecondsUntilStartAttribute(): ?int
    {
        if (!$this->task || !$this->task->activates_at) return null;
        // While deactivated, there's nothing counting down — the window
        // is paused, not just delayed, so showing a live number here
        // would contradict the "Deactivated" status right next to it.
        if ($this->task->isDeactivated()) return null;
        if (Carbon::now()->gte($this->task->activates_at)) return null;
        return max(0, (int) Carbon::now()->diffInSeconds($this->task->activates_at, false));
    }
}
