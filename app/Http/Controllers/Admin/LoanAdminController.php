<?php
// LOCATION: app/Http/Controllers/Admin/LoanAdminController.php
// Admin: lending-programme switchboard and loan-application review. Restricted to admins (loans.* gates);
// financial decisions are never available to the investor, financial-team or marvflow roles.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoanApplication;
use App\Services\Rank\LoanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LoanAdminController extends Controller
{
    public function __construct(protected LoanService $loans) {}

    // GET /admin/ranks/loans/settings
    public function settings()
    {
        Gate::authorize('loans.review');
        return response()->json(['settings' => $this->loans->settings(), 'can_configure' => Gate::allows('loans.configure'), 'can_decide' => Gate::allows('loans.decide')]);
    }

    // PUT /admin/ranks/loans/settings  { is_authorized?, is_active?, policy_reference?, eligible_rank_keys?, notes?, reason }
    public function configure(Request $request)
    {
        Gate::authorize('loans.configure');
        $v = $request->validate([
            'is_authorized' => 'sometimes|boolean', 'is_active' => 'sometimes|boolean', 'policy_reference' => 'sometimes|nullable|string|max:160',
            'eligible_rank_keys' => 'sometimes|array|min:1', 'eligible_rank_keys.*' => 'string|max:20', 'notes' => 'sometimes|nullable|string|max:2000',
            'reason' => 'required|string|min:5|max:1000',
        ]);

        return response()->json(['settings' => $this->loans->configure($v, $request->user(), $v['reason'])]);
    }

    // GET /admin/ranks/loans/applications?status=
    public function index(Request $request)
    {
        Gate::authorize('loans.review');
        $v = $request->validate(['status' => 'nullable|in:' . implode(',', LoanApplication::STATUSES)]);
        $page = $this->loans->list($v['status'] ?? null);

        return response()->json([
            'data' => collect($page->items())->map(fn ($a) => $this->loans->presentForStaff($a))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    // POST /admin/ranks/loans/applications/{application}/review  { note? }
    public function review(Request $request, LoanApplication $application)
    {
        Gate::authorize('loans.decide');
        abort_if(\App\Services\Rank\LoanService::locked(), 403, \App\Services\Rank\LoanService::LOCK_MESSAGE);   // locked first: no validation detail leaks while lending is off
        $v = $request->validate(['note' => 'nullable|string|max:1000']);

        return response()->json($this->loans->presentForStaff($this->loans->startReview($application, $request->user(), $v['note'] ?? null)));
    }

    // POST /admin/ranks/loans/applications/{application}/approve  { approved_terms, reason }
    public function approve(Request $request, LoanApplication $application)
    {
        Gate::authorize('loans.decide');
        abort_if(\App\Services\Rank\LoanService::locked(), 403, \App\Services\Rank\LoanService::LOCK_MESSAGE);   // locked first: no validation detail leaks while lending is off
        $v = $request->validate(['approved_terms' => 'required|string|min:10|max:4000', 'reason' => 'required|string|min:5|max:1000']);

        return response()->json($this->loans->presentForStaff($this->loans->approve($application, $request->user(), $v['approved_terms'], $v['reason'])));
    }

    // POST /admin/ranks/loans/applications/{application}/reject  { reason }
    public function reject(Request $request, LoanApplication $application)
    {
        Gate::authorize('loans.decide');
        abort_if(\App\Services\Rank\LoanService::locked(), 403, \App\Services\Rank\LoanService::LOCK_MESSAGE);   // locked first: no validation detail leaks while lending is off
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return response()->json($this->loans->presentForStaff($this->loans->reject($application, $request->user(), $v['reason'])));
    }
}
