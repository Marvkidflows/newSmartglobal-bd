<?php
// LOCATION: app/Models/Sector.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Sector extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sector) {
            if (empty($sector->slug)) {
                $sector->slug = Str::slug($sector->name);
            }
        });
    }

    // ── RELATIONSHIPS ──────────────────────────────────────────────────────

    public function categories()
    {
        return $this->hasMany(SectorCategory::class);
    }

    public function activeCategories()
    {
        return $this->hasMany(SectorCategory::class)->where('status', 'active');
    }

    // All plans across all of this sector's categories
    public function investmentPlans()
    {
        return $this->hasManyThrough(InvestmentPlan::class, SectorCategory::class, 'sector_id', 'sector_category_id');
    }

    // ── SCOPES ─────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}