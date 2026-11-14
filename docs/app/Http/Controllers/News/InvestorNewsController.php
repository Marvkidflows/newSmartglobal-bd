<?php
// TASK 4 — signed-in investor feed. Which visibility levels a viewer may read is decided HERE (server-side):
//   unverified applicants → public + members; verified, eligible investors → public + members + investor.
namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\News\NewsArticle;
use App\Services\News\NewsAudience;
use App\Services\News\NewsPresenter;
use App\Services\News\NewsWorkflow;
use Illuminate\Http\Request;

class InvestorNewsController extends Controller
{
    public function __construct(protected NewsPresenter $presenter) {}

    // GET /api/investor/news
    public function index(Request $r)
    {
        $page = PublicNewsController::feedQuery($r, NewsAudience::visibilitiesFor($r->user()))->paginate(min(50, (int) $r->integer('per_page', config('news.per_page'))));
        return response()->json($page->through(fn ($a) => $this->presenter->card($a)));
    }

    // GET /api/investor/news/{slug}
    public function show(Request $r, string $slug)
    {
        NewsWorkflow::syncScheduled();
        $a = NewsArticle::live()->whereIn('visibility', NewsAudience::visibilitiesFor($r->user()))->where('slug', $slug)->firstOrFail();
        return response()->json(['article' => $this->presenter->full($a)]);
    }
}
