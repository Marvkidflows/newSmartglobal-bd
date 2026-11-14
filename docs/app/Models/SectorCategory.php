<?php
// LOCATION: app/Models/SectorCategory.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SectorCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'sector_id',
        'name',
        'slug',
        'description',
        'sort_order',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    // ── RELATIONSHIPS ──────────────────────────────────────────────────────

    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }

    public function investmentPlans()
    {
        return $this->hasMany(InvestmentPlan::class, 'sector_category_id');
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