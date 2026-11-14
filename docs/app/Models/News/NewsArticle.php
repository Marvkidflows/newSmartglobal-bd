<?php
// TASK 4 — news / media article.
namespace App\Models\News;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class NewsArticle extends Model
{
    use SoftDeletes;

    public const TYPES = ['article', 'announcement', 'market_news', 'educational', 'video', 'promotion', 'investor_notice'];
    public const VISIBILITIES = ['public', 'members', 'investor'];
    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'scheduled', 'published', 'unpublished'];

    protected $table = 'news_articles';
    // Status / reviewer / publisher fields are NOT mass-assignable: only NewsWorkflow moves an article through its lifecycle.
    protected $fillable = ['title', 'summary', 'body', 'content_type', 'category_id', 'visibility', 'cover_media_id', 'video_media_id', 'is_featured'];
    protected $casts = ['reviewed_at' => 'datetime', 'published_at' => 'datetime', 'scheduled_at' => 'datetime', 'is_featured' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            if (empty($a->slug)) $a->slug = self::uniqueSlug($a->title);
        });
    }

    public static function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'news', 150, '');
        $slug = $base; $i = 1;
        while (self::withTrashed()->where('slug', $slug)->exists()) $slug = $base . '-' . (++$i);
        return $slug;
    }

    public function category() { return $this->belongsTo(NewsCategory::class, 'category_id'); }
    public function cover()    { return $this->belongsTo(NewsMedia::class, 'cover_media_id'); }
    public function video()    { return $this->belongsTo(NewsMedia::class, 'video_media_id'); }
    public function author()   { return $this->belongsTo(User::class, 'author_id'); }

    /** Live right now: published (date reached) OR scheduled whose time has arrived. Drafts / submitted / rejected / unpublished never match. */
    public function scopeLive($q)
    {
        return $q->where(function ($w) {
            $w->where(fn ($x) => $x->where('status', 'published')->where(fn ($y) => $y->whereNull('published_at')->orWhere('published_at', '<=', now())))
              ->orWhere(fn ($x) => $x->where('status', 'scheduled')->where('scheduled_at', '<=', now()));
        });
    }
}
