<?php
// TASK 4 — turns models into API payloads. Storage paths / disks never leave the server; media get short-lived signed URLs unless the
// owning article is public (then a stable public route that re-checks visibility on every request).
namespace App\Services\News;

use App\Models\News\NewsArticle;
use App\Models\News\NewsMedia;
use Illuminate\Support\Facades\URL;

class NewsPresenter
{
    public function __construct(protected NewsMediaService $media) {}

    public function media(?NewsMedia $m, NewsArticle $a, bool $staffPreview = false): ?array
    {
        if (!$m) return null;
        $row = ['id' => $m->id, 'kind' => $m->kind, 'alt' => $m->alt_text, 'caption' => $m->caption, 'status' => $m->status];
        if ($m->kind === 'video_embed') {
            $row['embed_url'] = $this->media->embedUrl($m);
            $row['provider'] = $m->embed_provider;
        } else {
            $row += ['mime' => $m->mime, 'width' => $m->width, 'height' => $m->height];
            $row['url'] = ($a->visibility === 'public' && !$staffPreview)
                ? route('news.public.media', ['media' => $m->id])
                : URL::temporarySignedRoute('news.media.signed', now()->addMinutes((int) config('news.signed_url_minutes')), ['media' => $m->id]);
        }
        return $row;
    }

    public function card(NewsArticle $a, bool $staff = false): array
    {
        $a->loadMissing(['category', 'cover']);
        return [
            'id' => $a->id, 'slug' => $a->slug, 'title' => $a->title, 'summary' => $a->summary, 'content_type' => $a->content_type,
            'category' => $a->category ? ['slug' => $a->category->slug, 'name' => $a->category->name] : null,
            'visibility' => $a->visibility, 'is_featured' => $a->is_featured, 'author_name' => $a->author_name,
            'published_at' => $a->published_at ?? $a->scheduled_at, 'cover' => $this->media($a->cover, $a, $staff), 'has_video' => (bool) $a->video_media_id,
        ];
    }

    public function full(NewsArticle $a, bool $staff = false): array
    {
        $a->loadMissing(['video']);
        return $this->card($a, $staff) + ['body' => $a->body, 'video' => $this->media($a->video, $a, $staff)];
    }

    /** Admin payload: lifecycle + moderation fields. */
    public function admin(NewsArticle $a): array
    {
        $a->loadMissing(['category', 'cover', 'video']);
        return $this->full($a, true) + [
            'status' => $a->status, 'category_id' => $a->category_id, 'cover_media_id' => $a->cover_media_id, 'video_media_id' => $a->video_media_id,
            'author_id' => $a->author_id, 'department' => $a->department, 'review_note' => $a->review_note, 'reviewed_by' => $a->reviewed_by,
            'reviewed_at' => $a->reviewed_at, 'scheduled_at' => $a->scheduled_at, 'created_at' => $a->created_at, 'updated_at' => $a->updated_at,
        ];
    }
}
