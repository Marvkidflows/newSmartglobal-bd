<?php
// TASK 4 — streams stored media after an access check. Files live on a private disk and are never web-served directly.
namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\News\NewsArticle;
use App\Models\News\NewsMedia;
use Illuminate\Support\Facades\Storage;

class NewsMediaController extends Controller
{
    // GET /api/public/news/media/{media} — only media attached to a LIVE, PUBLIC, approved article
    public function publicMedia(int $media)
    {
        $m = NewsMedia::where('status', 'approved')->findOrFail($media);
        $ok = NewsArticle::live()->where('visibility', 'public')->where(fn ($q) => $q->where('cover_media_id', $m->id)->orWhere('video_media_id', $m->id))->exists();
        abort_unless($ok, 404);
        return $this->stream($m, 'public, max-age=3600');
    }

    // GET /api/news/media/{media}?signature=… — short-lived signed URL, issued only to viewers already authorised for the article
    public function signedMedia(int $media)
    {
        return $this->stream(NewsMedia::findOrFail($media), 'private, max-age=600');
    }

    protected function stream(NewsMedia $m, string $cache)
    {
        abort_if($m->kind === 'video_embed' || !$m->path || !$m->disk, 404);
        $disk = Storage::disk($m->disk);
        abort_unless($disk->exists($m->path), 404);

        $headers = [
            'Content-Type' => $m->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => $cache,
            'Content-Disposition' => 'inline; filename="media.' . pathinfo($m->path, PATHINFO_EXTENSION) . '"',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cross-Origin-Resource-Policy' => 'cross-origin',
        ];
        // Local disks: response()->file() supports HTTP Range requests (needed for video seeking).
        if (config('filesystems.disks.' . $m->disk . '.driver') === 'local') return response()->file($disk->path($m->path), $headers);
        return $disk->response($m->path, null, $headers);
    }
}
