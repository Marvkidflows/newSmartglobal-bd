<?php
// app/Models/Investment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Investment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_name',
        'tier',
        'amount',
        'roi_percentage',
        'duration_days',
        'expected_return',
        'earned_return',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'roi_percentage' => 'decimal:2',
        'expected_return' => 'decimal:2',
        'earned_return' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the user that owns the investment
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get days remaining for this investment
     */
    public function getDaysRemainingAttribute()
    {
        if ($this->status !== 'active') {
            return 0;
        }

        $endDate = Carbon::parse($this->created_at)->addDays($this->duration_days);
        return max(0, $endDate->diffInDays(now()));
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentageAttribute()
    {
        if ($this->status === 'completed') {
            return 100;
        }

        $totalDays = $this->duration_days;
        $daysElapsed = Carbon::parse($this->created_at)->diffInDays(now());
        
        return min(100, round(($daysElapsed / $totalDays) * 100, 2));
    }

    /**
     * Check if investment is matured
     */
    public function isMatured()
    {
        return $this->getDaysRemainingAttribute() === 0 && $this->status === 'active';
    }

    /**
     * Scope for active investments
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for completed investments
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}