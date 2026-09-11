<?php
// LOCATION: app/Models/InvestmentAccount.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class InvestmentAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'investment_plan_id',
        'amount',
        'profit_percentage',
        'expected_profit',
        'total_return',
        'start_date',
        'end_date',
        'remaining_days',
        'status',
        'last_countdown_update',
        'countdown_modified_by',
        'countdown_modified_reason',
        'is_paid',
    ];

    protected $casts = [
        'amount'                => 'decimal:2',
        'profit_percentage'     => 'decimal:2',
        'expected_profit'       => 'decimal:2',
        'total_return'          => 'decimal:2',
        'start_date'            => 'date',
        'end_date'              => 'date',
        'last_countdown_update' => 'datetime',
        'is_paid'               => 'boolean',
    ];

    // ── RELATIONSHIPS ──────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function investmentPlan()
    {
        return $this->belongsTo(InvestmentPlan::class);
    }

    // ADDED — was missing, required by AdminInvestmentController::countdownLogs()
    public function countdownLogs()
    {
        return $this->hasMany(InvestmentCountdownLog::class);
    }

    // ── HELPERS ────────────────────────────────────────────────────────────

    // Calculate progress percentage
    public function getProgressAttribute(): int
    {
        $plan      = $this->investmentPlan;
        $totalDays = $plan
            ? ($plan->duration_days ?? (($plan->duration_months ?? 1) * 30))
            : 30;
        $remaining = $this->remaining_days ?? $totalDays;
        $elapsed   = $totalDays - $remaining;

        return (int) min(100, max(0, round(($elapsed / $totalDays) * 100)));
    }

    // ADDED — was missing, required by getCountdownStatusAttribute() and syncRemainingDays()
    public function getLiveRemainingDaysAttribute(): int
    {
        if (!$this->end_date) {
            return $this->remaining_days ?? 0;
        }
        $diff = Carbon::today()->diffInDays(Carbon::parse($this->end_date), false);
        return max(0, (int) ceil($diff));
    }

    public function getCountdownStatusAttribute(): string
    {
        if ($this->is_paid) return 'paid';
        $remaining = $this->live_remaining_days;
        if ($remaining <= 0) return 'matured';
        if ($remaining <= 7) return 'maturing_soon';
        return 'active';
    }

    /**
     * Recalculate and persist remaining_days from end_date.
     * Call this any time end_date changes (admin adjustments, daily cron, etc).
     */
    public function syncRemainingDays(): void
    {
        $this->remaining_days = $this->live_remaining_days;
        $this->save();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}