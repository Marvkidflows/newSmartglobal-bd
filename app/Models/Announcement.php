<?php
// LOCATION: app/Models/Announcement.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Announcement extends Model
{
    // Valid News & Information Centre lifecycle states.
    public const STATUSES = ['draft', 'scheduled', 'published', 'unpublished'];

    // Fixed category list (Guide/brief §7). Kept as a plain string column —
    // extendable later by adding to this array, no migration required.
    public const CATEGORIES = [
        'company_announcement',
        'platform_update',
        'important_notice',
        'investor_information',
        'project_update',
        'general_communication',
        'other',
    ];

    protected $fillable = [
        'title',
        'slug',
        'summary',
        'content',
        'image_url',
        'type',
        'category',
        'is_popup',
        'is_featured',
        'is_active',
        'status',
        'published_at',
        'scheduled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'is_popup'     => 'boolean',
        'is_featured'  => 'boolean',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    protected static function booted()
    {
        // Keep slug + legacy is_active in sync automatically, whatever
        // combination of fields a controller sets — so any existing code
        // still reading `is_active` (bell dropdown, dashboard, popup)
        // keeps working unchanged.
        static::saving(function (Announcement $announcement) {
            if (empty($announcement->slug) && !empty($announcement->title)) {
                $announcement->slug = static::uniqueSlugFor($announcement->title, $announcement->id);
            }

            if ($announcement->isDirty('status') || empty($announcement->status)) {
                $announcement->status = $announcement->status ?: 'published';
                $announcement->is_active = $announcement->status === 'published';
            }
        });
    }

    public static function uniqueSlugFor(string $title, $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'news';
        $slug = $base;
        $i = 1;
        while (
            static::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-" . (++$i);
        }
        return $slug;
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ── VISIBILITY (server-enforced — drafts/scheduled-not-yet-due/
    // unpublished never reach investor-facing endpoints) ──
    public function scopePublished($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'published')
              ->where(function ($qq) {
                  $qq->whereNull('published_at')->orWhere('published_at', '<=', now());
              });
        })->orWhere(function ($q) {
            // A scheduled item whose time has arrived is visible even if the
            // lazy sync (below) hasn't flipped its status yet.
            $q->where('status', 'scheduled')->where('scheduled_at', '<=', now());
        });
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Lazily promotes any due `scheduled` announcements to `published`.
     * Call before serving investor-facing or admin listing endpoints.
     * Mirrors InvestorTask::syncExpiry()'s "enforce on read" approach —
     * no scheduler/cron is assumed to exist.
     */
    public static function syncScheduled(): int
    {
        return static::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get()
            ->each(function (Announcement $a) {
                $a->status = 'published';
                $a->published_at = $a->published_at ?: $a->scheduled_at;
                $a->save();
            })
            ->count();
    }
}