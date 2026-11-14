<?php
// TASK 4 — staff media library: upload (image / video file), secure embeds, moderation.
namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\News\NewsArticle;
use App\Models\News\NewsMedia;
use App\Services\News\NewsAudit;
use App\Services\News\NewsMediaService;
use App\Services\News\NewsPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class MediaAdminController extends Controller
{
    public function __construct(protected NewsMediaService $svc) {}

    protected function row(NewsMedia $m): array
    {
        return [
            'id' => $m->id, 'kind' => $m->kind, 'status' => $m->status, 'mime' => $m->mime, 'size' => $m->size, 'width' => $m->width, 'height' => $m->height,
            'original_name' => $m->original_name, 'alt' => $m->alt_text, 'caption' => $m->caption, 'uploaded_by' => $m->uploader?->name, 'created_at' => $m->created_at,
            'url' => $m->kind === 'video_embed' ? null : URL::temporarySignedRoute('news.media.signed', now()->addMinutes((int) config('news.signed_url_minutes')), ['media' => $m->id]),
            'embed_url' => $this->svc->embedUrl($m),
        ];
    }

    protected function reviewer($u): bool
    {
        return NewsPermissions::can($u, 'news.review') || NewsPermissions::can($u, 'news.manage');
    }

    // GET /api/news-admin/media?status=pending
    public function index(Request $r)
    {
        $q = NewsMedia::with('uploader:id,name')->latest('id');
        if (!$this->reviewer($r->user()) && !NewsPermissions::can($r->user(), 'news.publish') && !NewsPermissions::can($r->user(), 'news.edit')) $q->where('uploaded_by', $r->user()->id);
        if ($r->filled('status')) $q->where('status', (string) $r->input('status'));
        if ($r->filled('kind')) $q->where('kind', (string) $r->input('kind'));
        return response()->json($q->paginate(min(100, (int) $r->integer('per_page', 30)))->through(fn ($m) => $this->row($m)));
    }

    // POST /api/news-admin/media/image  (multipart: file, alt?, caption?)
    public function image(Request $r)
    {
        $d = $r->validate(['file' => ['required', 'file'], 'alt' => ['nullable', 'string', 'max:190'], 'caption' => ['nullable', 'string', 'max:255']]);
        $m = $this->svc->storeImage($r->file('file'), $r->user(), $d['alt'] ?? null, $d['caption'] ?? null);
        NewsAudit::record($r->user(), 'media.uploaded', $m, ['kind' => 'image', 'status' => $m->status, 'size' => $m->size], $r);
        return response()->json(['success' => true, 'media' => $this->row($m->load('uploader:id,name'))], 201);
    }

    // POST /api/news-admin/media/video
    public function video(Request $r)
    {
        $d = $r->validate(['file' => ['required', 'file'], 'alt' => ['nullable', 'string', 'max:190'], 'caption' => ['nullable', 'string', 'max:255']]);
        $m = $this->svc->storeVideo($r->file('file'), $r->user(), $d['alt'] ?? null, $d['caption'] ?? null);
        NewsAudit::record($r->user(), 'media.uploaded', $m, ['kind' => 'video_file', 'status' => $m->status, 'size' => $m->size], $r);
        return response()->json(['success' => true, 'media' => $this->row($m->load('uploader:id,name'))], 201);
    }

    // POST /api/news-admin/media/embed { url, caption? }
    public function embed(Request $r)
    {
        $d = $r->validate(['url' => ['required', 'string', 'max:300'], 'caption' => ['nullable', 'string', 'max:255']]);
        $m = $this->svc->storeEmbed($d['url'], $r->user(), $d['caption'] ?? null);
        NewsAudit::record($r->user(), 'media.embedded', $m, ['provider' => $m->embed_provider, 'status' => $m->status], $r);
        return response()->json(['success' => true, 'media' => $this->row($m->load('uploader:id,name'))], 201);
    }

    public function approve(Request $r, int $id)
    {
        return $this->moderate($r, $id, 'approved');
    }

    public function reject(Request $r, int $id)
    {
        return $this->moderate($r, $id, 'rejected');
    }

    protected function moderate(Request $r, int $id, string $status)
    {
        $m = NewsMedia::findOrFail($id);
        $m->forceFill(['status' => $status, 'moderated_by' => $r->user()->id, 'moderated_at' => now()])->save();
        if ($status === 'rejected') {   // a rejected file can no longer be served from anywhere
            NewsArticle::where('status', 'published')->where(fn ($q) => $q->where('cover_media_id', $m->id)->orWhere('video_media_id', $m->id))->update(['status' => 'unpublished']);
        }
        NewsAudit::record($r->user(), 'media.' . $status, $m, [], $r);
        return response()->json(['success' => true, 'media' => $this->row($m->load('uploader:id,name'))]);
    }

    public function destroy(Request $r, int $id)
    {
        $m = NewsMedia::findOrFail($id);
        $own = (int) $m->uploaded_by === (int) $r->user()->id;
        abort_unless($own || NewsPermissions::can($r->user(), 'news.manage'), 403);
        $inUse = NewsArticle::live()->where(fn ($q) => $q->where('cover_media_id', $m->id)->orWhere('video_media_id', $m->id))->exists();
        if ($inUse) return response()->json(['success' => false, 'message' => 'This media is used by a live article. Unpublish the article first.'], 409);
        NewsAudit::record($r->user(), 'media.deleted', $m, [], $r);
        $this->svc->delete($m);
        return response()->json(['success' => true]);
    }
}
