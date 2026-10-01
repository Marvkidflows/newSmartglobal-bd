<?php
// LOCATION: app/Http/Controllers/Financial/FinancialNoticeController.php
//
// "Financial Team Notice" publishing. Uses the EXISTING News & Information
// Centre (announcements table / Announcement model / investor announcements
// feed) — no second announcement system. This controller is deliberately
// narrower than AdminAnnouncementController:
//
//   * every notice is forced to category = financial_notice,
//     department = financial (client cannot choose otherwise)
//   * it can only list / edit / unpublish notices of its OWN department —
//     it can never touch an admin's or another team's announcement
//   * no image upload, popup or featured flags (those stay admin-only)
//   * a permanent audit record is written for issue/unpublish
//
// Multi-recipient PRIVATE notices (with per-investor read/unread state)
// are sent through FinancialMessageController::broadcast instead; this
// controller is for notices meant for the public News Centre feed.

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\FinancialAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FinancialNoticeController extends Controller
{
    public function __construct(protected FinancialAuditService $audit) {}

    // GET /financial/notices
    public function index(Request $request)
    {
        Gate::authorize('financial.issue-notices');
        Announcement::syncScheduled();

        $notices = Announcement::where('department', 'financial')
            ->where('category', 'financial_notice')
            ->with('creator:id,name')->latest()->get()
            ->map(fn ($a) => $this->format($a));

        return response()->json(['notices' => $notices]);
    }

    // POST /financial/notices
    public function store(Request $request)
    {
        Gate::authorize('financial.issue-notices');

        $v = $request->validate([
            'title'   => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:20000'],
            'status'  => ['nullable', 'in:draft,published'],
        ]);

        $status = $v['status'] ?? 'published';

        $notice = DB::transaction(function () use ($request, $v, $status) {
            $a = Announcement::create([
                'title'        => $v['title'],
                'summary'      => $v['summary'] ?? null,
                'content'      => $v['content'],
                'type'         => 'info',
                'category'     => 'financial_notice',
                'department'   => 'financial',
                'is_popup'     => false,
                'is_featured'  => false,
                'status'       => $status,
                'published_at' => $status === 'published' ? now() : null,
                'created_by'   => $request->user()->id,
                'updated_by'   => $request->user()->id,
            ]);

            $this->audit->record($request->user(), 'communication.news_notice_' . $status, 'announcement', $a->id, null,
                null, ['title' => $a->title, 'status' => $status, 'category' => 'financial_notice'], null);

            return $a;
        });

        return response()->json(['message' => $status === 'published' ? 'Notice published.' : 'Draft saved.', 'notice' => $this->format($notice)], 201);
    }

    // POST /financial/notices/{announcement}/unpublish
    public function unpublish(Request $request, Announcement $announcement)
    {
        Gate::authorize('financial.issue-notices');
        $this->ensureOwn($announcement);

        DB::transaction(function () use ($request, $announcement) {
            $prev = $announcement->status;
            $announcement->update(['status' => 'unpublished', 'updated_by' => $request->user()->id]);
            $this->audit->record($request->user(), 'communication.news_notice_unpublished', 'announcement', $announcement->id, null,
                ['status' => $prev], ['status' => 'unpublished'], $request->input('reason'));
        });

        return response()->json(['message' => 'Notice unpublished.', 'notice' => $this->format($announcement->fresh('creator'))]);
    }

    // POST /financial/notices/{announcement}/publish
    public function publish(Request $request, Announcement $announcement)
    {
        Gate::authorize('financial.issue-notices');
        $this->ensureOwn($announcement);

        DB::transaction(function () use ($request, $announcement) {
            $prev = $announcement->status;
            $announcement->update(['status' => 'published', 'published_at' => now(), 'updated_by' => $request->user()->id]);
            $this->audit->record($request->user(), 'communication.news_notice_published', 'announcement', $announcement->id, null,
                ['status' => $prev], ['status' => 'published'], null);
        });

        return response()->json(['message' => 'Notice published.', 'notice' => $this->format($announcement->fresh('creator'))]);
    }

    protected function ensureOwn(Announcement $a): void
    {
        // 404, not 403 — do not confirm that other departments' items exist.
        abort_unless($a->department === 'financial' && $a->category === 'financial_notice', 404);
    }

    protected function format(Announcement $a): array
    {
        return [
            'id'           => $a->id,
            'title'        => $a->title,
            'summary'      => $a->summary,
            'content'      => $a->content,
            'status'       => $a->status,
            'category'     => $a->category,
            'department'   => $a->department,
            'source'       => 'Smart System Investment — Financial Team',
            'issued_by'    => $a->creator->name ?? null,
            'published_at' => optional($a->published_at)->toIso8601String(),
            'created_at'   => $a->created_at->toIso8601String(),
        ];
    }
}
