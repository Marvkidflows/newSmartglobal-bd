<?php
// LOCATION: app/Http/Controllers/Investor/InvestorAnnouncementController.php
//
// Investor-facing News & Information Centre feed. SECURITY: only published
// (or due-scheduled) content is ever returned — draft/scheduled-not-yet-due/
// unpublished items never reach this endpoint (Guide/brief §17, §22).

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;

class InvestorAnnouncementController extends Controller
{
    // GET /investor-investment/announcements
    public function investorIndex(Request $request)
    {
        Announcement::syncScheduled();

        $query = Announcement::published()->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->boolean('featured_only')) {
            $query->where('is_featured', true);
        }

        $announcements = $query->get()->map(fn($a) => $this->formatSummary($a));

        return response()->json([
            'announcements' => $announcements,
            'count'         => $announcements->count(),
        ]);
    }

    // GET /investor-investment/announcements/{announcement}
    // Route-model-bound by slug (see routes/api.php). A draft/unpublished
    // item resolves to 404 for investors, never leaking its content.
    public function show(Request $request, string $slug)
    {
        Announcement::syncScheduled();

        $announcement = Announcement::published()->where('slug', $slug)->first();

        if (!$announcement) {
            abort(404);
        }

        return response()->json(['announcement' => $this->formatDetail($announcement)]);
    }

    protected function formatSummary(Announcement $a): array
    {
        return [
            'id'         => $a->id,
            'slug'       => $a->slug,
            'title'      => $a->title,
            'summary'    => $a->summary ?: str($a->content ?? '')->limit(160),
            'content'    => $a->content ?? '',
            'message'    => $a->content ?? '', // legacy alias — AnnouncementPopup.jsx reads this
            'image_url'  => $a->image_url,
            'type'       => $a->type ?? 'info',
            'category'   => $a->category ?? 'general_communication',
            'is_popup'   => (bool) $a->is_popup, // AnnouncementPopup.jsx filters on this
            'is_featured'=> (bool) $a->is_featured,
            'created_at' => $a->created_at->toDateString(),
            'time_ago'   => $a->created_at->diffForHumans(),
        ];
    }

    protected function formatDetail(Announcement $a): array
    {
        return [
            'id'          => $a->id,
            'slug'        => $a->slug,
            'title'       => $a->title,
            'summary'     => $a->summary,
            'content'     => $a->content ?? $a->message ?? '',
            'image_url'   => $a->image_url,
            'type'        => $a->type ?? 'info',
            'category'    => $a->category ?? 'general_communication',
            'is_featured' => (bool) $a->is_featured,
            'author'      => $a->creator->name ?? 'Smart System Investment',
            'created_at'  => $a->created_at->toDateString(),
            'time_ago'    => $a->created_at->diffForHumans(),
        ];
    }
}
