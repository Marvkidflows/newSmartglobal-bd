<?php
// TASK 4 — anonymous reads. Only LIVE articles with visibility=public are ever returned.
namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\News\NewsArticle;
use App\Models\News\NewsCategory;
use App\Services\News\NewsPresenter;
use App\Services\News\NewsWorkflow;
use Illuminate\Http\Request;

class PublicNewsController extends Controller
{
    public function __construct(protected NewsPresenter $presenter) {}

    public static function feedQuery(Request $r, array $visibilities)
    {
        NewsWorkflow::syncScheduled();
        $q = NewsArticle::live()->whereIn('visibility', $visibilities)->with(['category', 'cover']);
        if ($r->filled('category')) $q->whereHas('category', fn ($c) => $c->where('slug', (string) $r->input('category')));
        if ($r->filled('type')) $q->where('content_type', (string) $r->input('type'));
        if ($r->boolean('featured')) $q->where('is_featured', true);
        if ($r->filled('q')) {
            $t = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr(trim((string) $r->input('q')), 0, 100)) . '%';
            $q->where(fn ($w) => $w->where('title', 'like', $t)->orWhere('summary', 'like', $t));
        }
        return $q->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('id');
    }

    // GET /api/public/news
    public function index(Request $r)
    {
        $page = self::feedQuery($r, ['public'])->paginate(min(50, (int) $r->integer('per_page', config('news.per_page'))));
        return response()->json($page->through(fn ($a) => $this->presenter->card($a)));
    }

    // GET /api/public/news/{slug}
    public function show(string $slug)
    {
        NewsWorkflow::syncScheduled();
        $a = NewsArticle::live()->where('visibility', 'public')->where('slug', $slug)->firstOrFail();   // anything else is a plain 404
        return response()->json(['article' => $this->presenter->full($a)]);
    }

    // GET /api/public/news/categories
    public function categories()
    {
        return response()->json(['categories' => NewsCategory::where('is_active', true)->orderBy('sort_order')->get(['slug', 'name'])]);
    }
}
