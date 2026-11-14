<?php
// LOCATION: app/Models/InvestmentPlan.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestmentPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'sector_category_id',
        'min_amount',
        'max_amount',
        'profit_percentage',
        'profit_percent',
        'duration_days',
        'duration_months',
        'is_featured',
        'status',
    ];

    protected $casts = [
        'min_amount'        => 'decimal:2',
        'max_amount'        => 'decimal:2',
        'profit_percentage' => 'decimal:2',
        'profit_percent'    => 'decimal:2',
        'is_featured'       => 'boolean',
    ];

    // ── RELATIONSHIPS ──────────────────────────────────────────────────────

    public function investmentAccounts()
    {
        return $this->hasMany(InvestmentAccount::class);
    }

    // Active investments only
    public function activeInvestments()
    {
        return $this->hasMany(InvestmentAccount::class)->where('status', 'active');
    }

    public function sectorCategory()
    {
        return $this->belongsTo(SectorCategory::class, 'sector_category_id');
    }

    // Convenience accessor straight to the parent sector through the category
    public function sector()
    {
        return $this->hasOneThrough(
            Sector::class,
            SectorCategory::class,
            'id',                  // sector_categories.id
            'id',                  // sectors.id
            'sector_category_id',  // investment_plans.sector_category_id
            'sector_id'            // sector_categories.sector_id
        );
    }

    // ── HELPERS ────────────────────────────────────────────────────────────

    // Get the ROI % (handles both column names)
    public function getRoiAttribute(): float
    {
        return (float) ($this->profit_percentage ?? $this->profit_percent ?? 0);
    }

    // Get duration in days (handles both column names)
    public function getDaysAttribute(): int
    {
        return $this->duration_days ?? (($this->duration_months ?? 1) * 30);
    }

    // Scope: only active plans
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Scope: featured plans first
    public function scopeFeaturedFirst($query)
    {
        return $query->orderByDesc('is_featured')->orderBy('min_amount');
    }
}