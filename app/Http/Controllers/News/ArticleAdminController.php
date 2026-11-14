<?php
// TASK 4 — staff article management and the moderation workflow. All lifecycle changes go through NewsWorkflow.
namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\News\NewsArticle;
use App\Services\News\NewsPermissions;
use App\Services\News\NewsPresenter;
use App\Services\News\NewsWorkflow;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ArticleAdminController extends Controller
{
    public function __construct(protected NewsWorkflow $flow, protected NewsPresenter $presenter) {}

    protected function seesAll($u): bool
    {
        return NewsPermissions::can($u, 'news.edit') || NewsPermissions::can($u, 'news.review') || NewsPermissions::can($u, 'news.publish') || NewsPermissions::can($u, 'news.manage');
    }

    protected function find(Request $r, int $id): NewsArticle
    {
        $a = NewsArticle::findOrFail($id);
        abort_unless($this->seesAll($r->user()) || (int) $a->author_id === (int) $r->user()->id, 404);   // contributors cannot even see others' items
        return $a;
    }

    // GET /api/news-admin/articles?status=&type=&category_id=&visibility=&q=&mine=1
    public function index(Request $r)
    {
        NewsWorkflow::syncScheduled();
        $q = NewsArticle::with(['category', 'cover'])->latest('id');
        if (!$this->seesAll($r->user()) || $r->boolean('mine')) $q->where('author_id', $r->user()->id);
        foreach (['status' => 'status', 'type' => 'content_type', 'visibility' => 'visibility'] as $param => $col) {
            if ($r->filled($param)) $q->where($col, (string) $r->input($param));
        }
        if ($r->filled('category_id')) $q->where('category_id', (int) $r->input('category_id'));
        if ($r->filled('q')) {
            $t = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr(trim((string) $r->input('q')), 0, 100)) . '%';
            $q->where(fn ($w) => $w->where('title', 'like', $t)->orWhere('author_name', 'like', $t));
        }
        return response()->json($q->paginate(min(100, (int) $r->integer('per_page', 25)))->through(fn ($a) => $this->presenter->card($a, true) + [
            'status' => $a->status, 'department' => $a->department, 'review_note' => $a->review_note, 'scheduled_at' => $a->scheduled_at, 'updated_at' => $a->updated_at,
        ]));
    }

    public function show(Request $r, int $id)
    {
        return response()->json(['article' => $this->presenter->admin($this->find($r, $id))]);
    }

    public function store(Request $r)
    {
        abort_unless(NewsPermissions::can($r->user(), 'news.contribute') || NewsPermissions::can($r->user(), 'news.edit') || NewsPermissions::can($r->user(), 'news.publish'), 403);
        $a = $this->flow->create($r->user(), $this->rules($r, true), $r);
        return response()->json(['success' => true, 'article' => $this->presenter->admin($a)], 201);
    }

    public function update(Request $r, int $id)
    {
        $a = $this->find($r, $id);
        return response()->json(['success' => true, 'article' => $this->presenter->admin($this->flow->update($r->user(), $a, $this->rules($r, false), $r))]);
    }

    public function destroy(Request $r, int $id)
    {
        $this->flow->remove($r->user(), $this->find($r, $id), $r);
        return response()->json(['success' => true]);
    }

    public function submit(Request $r, int $id)
    {
        return $this->out($this->flow->submit($r->user(), $this->find($r, $id), $r));
    }

    public function approve(Request $r, int $id)
    {
        $d = $r->validate(['note' => ['nullable', 'string', 'max:1000']]);
        return $this->out($this->flow->approve($r->user(), NewsArticle::findOrFail($id), $d['note'] ?? null, $r));
    }

    public function reject(Request $r, int $id)
    {
        $d = $r->validate(['note' => ['required', 'string', 'max:1000']]);
        return $this->out($this->flow->reject($r->user(), NewsArticle::findOrFail($id), $d['note'], $r));
    }

    // POST /api/news-admin/articles/{id}/publish { scheduled_at? }  — a future time schedules instead of publishing
    public function publish(Request $r, int $id)
    {
        $d = $r->validate(['scheduled_at' => ['nullable', 'date']]);
        $at = !empty($d['scheduled_at']) ? Carbon::parse($d['scheduled_at']) : null;
        return $this->out($this->flow->publish($r->user(), NewsArticle::findOrFail($id), $at, $r));
    }

    public function unpublish(Request $r, int $id)
    {
        return $this->out($this->flow->unpublish($r->user(), NewsArticle::findOrFail($id), $r));
    }

    protected function out(NewsArticle $a)
    {
        return response()->json(['success' => true, 'article' => $this->presenter->admin($a)]);
    }

    protected function rules(Request $r, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return $r->validate([
            'title' => [$req, 'string', 'max:190'], 'summary' => ['nullable', 'string', 'max:500'], 'body' => [$req, 'string', 'max:200000'],
            'content_type' => [$req, 'in:' . implode(',', NewsArticle::TYPES)], 'visibility' => [$req, 'in:' . implode(',', NewsArticle::VISIBILITIES)],
            'category_id' => ['nullable', 'integer', 'exists:news_categories,id'],
            'cover_media_id' => ['nullable', 'integer', 'exists:news_media,id'], 'video_media_id' => ['nullable', 'integer', 'exists:news_media,id'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);
    }
}
