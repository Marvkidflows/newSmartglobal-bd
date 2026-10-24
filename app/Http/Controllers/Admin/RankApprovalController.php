<?php
// LOCATION: app/Http/Controllers/Admin/RankApprovalController.php
//
// Pending rank reviews: calculated eligibility waiting for an authorised decision.
// Mounted under BOTH /admin/ranks (Admin) and /financial/rank-approvals (Financial Team); authorisation is the
// backend Gate `ranks.review` (Admin + Financial), checked here — not only by the route middleware.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RankReview;
use App\Services\Rank\RankReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RankApprovalController extends Controller
{
    public function __construct(protected RankReviewService $reviews) {}

    // GET …/approvals?status=pending|approved|rejected|withdrawn|all
    public function index(Request $request)
    {
        Gate::authorize('ranks.review');
        $v = $request->validate(['status' => 'nullable|in:pending,approved,rejected,withdrawn,all', 'per_page' => 'nullable|integer|min:5|max:100']);
        $status = $v['status'] ?? 'pending';

        $q = RankReview::query()->with(['investor', 'currentRank', 'proposedRank'])->orderByDesc('id');
        if ($status !== 'all') $q->where('status', $status);

        $page = $q->paginate($v['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => $this->reviews->present($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'pending_count' => RankReview::pending()->count(),
            'approval_required' => (bool) config('ranks.require_approval'),
        ]);
    }

    // POST …/approvals/{review}/approve   { note? }
    public function approve(Request $request, RankReview $review)
    {
        Gate::authorize('ranks.review');
        $v = $request->validate(['note' => 'nullable|string|max:1000']);

        return response()->json(['review' => $this->reviews->present($this->reviews->approve($review, $request->user(), $v['note'] ?? null))]);
    }

    // POST …/approvals/{review}/reject    { reason }
    public function reject(Request $request, RankReview $review)
    {
        Gate::authorize('ranks.review');
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return response()->json(['review' => $this->reviews->present($this->reviews->reject($review, $request->user(), $v['reason']))]);
    }
}
