<?php
// LOCATION: app/Http/Controllers/Admin/AdminAnnouncementController.php
//
// Extended into the admin side of the News & Information Centre. This is
// the SAME Announcement model/table used by the pre-existing announcement
// popup/bell-dropdown feature — extended, not replaced, per approved plan.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Cloudinary\Cloudinary;

class AdminAnnouncementController extends Controller
{
    // All valid announcement "type" values — legacy, drives badge styling
    // (AnnouncementPopup.jsx). Left untouched.
    const VALID_TYPES = [
        'general',
        'profit_update',
        'investment_opportunity',
        'maintenance',
        'balance_adjustment',
        'info',
        'warning',
        'success',
        'danger',
    ];

    // GET /admin/announcements
    public function index(Request $request)
    {
        Announcement::syncScheduled();

        $query = Announcement::latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('title', 'like', "%{$s}%")->orWhere('summary', 'like', "%{$s}%"));
        }

        $announcements = $query->get()->map(fn($a) => $this->format($a));

        return response()->json(['announcements' => $announcements]);
    }

    public function create()
    {
        return response()->json(['status' => 'ok', 'categories' => Announcement::CATEGORIES]);
    }

    // POST /admin/announcements
    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $imageUrl = $this->uploadImageIfPresent($request);

        $status = $validated['status'] ?? ($request->boolean('is_active', true) ? 'published' : 'draft');
        $publishedAt = $status === 'published' ? now() : null;

        $announcement = Announcement::create([
            'title'        => $validated['title'],
            'summary'      => $validated['summary'] ?? null,
            'content'      => $validated['content'],
            'image_url'    => $imageUrl,
            'type'         => $validated['type'] ?? 'general',
            'category'     => $validated['category'] ?? 'general_communication',
            'is_popup'     => $request->boolean('is_popup', false),
            'is_featured'  => $request->boolean('is_featured', false),
            'status'       => $status,
            'published_at' => $publishedAt,
            'scheduled_at' => $status === 'scheduled' ? $validated['scheduled_at'] : null,
            'created_by'   => auth()->id(),
            'updated_by'   => auth()->id(),
        ]);

        return response()->json([
            'message'      => 'News item created.',
            'announcement' => $this->format($announcement),
        ], 201);
    }

    // GET /admin/announcements/{announcement}
    public function show(Request $request, Announcement $announcement)
    {
        return response()->json(['announcement' => $this->format($announcement)]);
    }

    public function edit(Announcement $announcement)
    {
        return response()->json(['announcement' => $this->format($announcement)]);
    }

    // PUT /admin/announcements/{announcement}
    public function update(Request $request, Announcement $announcement)
    {
        $validated = $this->validatePayload($request, isUpdate: true);

        $imageUrl = $this->uploadImageIfPresent($request) ?? $announcement->image_url;

        $status = $validated['status'] ?? $announcement->status;
        $publishedAt = $announcement->published_at;
        if ($status === 'published' && $announcement->status !== 'published') {
            $publishedAt = now();
        }

        $announcement->update([
            'title'        => $validated['title']    ?? $announcement->title,
            'summary'      => array_key_exists('summary', $validated) ? $validated['summary'] : $announcement->summary,
            'content'      => $validated['content']  ?? $announcement->content,
            'image_url'    => $imageUrl,
            'type'         => $validated['type']     ?? $announcement->type,
            'category'     => $validated['category'] ?? $announcement->category,
            'is_popup'     => $request->has('is_popup') ? $request->boolean('is_popup') : $announcement->is_popup,
            'is_featured'  => $request->has('is_featured') ? $request->boolean('is_featured') : $announcement->is_featured,
            'status'       => $status,
            'published_at' => $publishedAt,
            'scheduled_at' => $status === 'scheduled' ? ($validated['scheduled_at'] ?? $announcement->scheduled_at) : null,
            'updated_by'   => auth()->id(),
        ]);

        return response()->json([
            'message'      => 'News item updated.',
            'announcement' => $this->format($announcement->fresh()),
        ]);
    }

    // POST /admin/announcements/{announcement}/publish
    public function publish(Announcement $announcement)
    {
        $announcement->update([
            'status'       => 'published',
            'published_at' => now(),
            'scheduled_at' => null,
            'updated_by'   => auth()->id(),
        ]);

        return response()->json(['message' => 'Published.', 'announcement' => $this->format($announcement->fresh())]);
    }

    // POST /admin/announcements/{announcement}/unpublish
    public function unpublish(Announcement $announcement)
    {
        $announcement->update(['status' => 'unpublished', 'updated_by' => auth()->id()]);

        return response()->json(['message' => 'Unpublished.', 'announcement' => $this->format($announcement->fresh())]);
    }

    // DELETE /admin/announcements/{announcement}
    public function destroy(Request $request, Announcement $announcement)
    {
        $announcement->delete();
        return response()->json(['message' => 'News item deleted.']);
    }

    // ── HELPERS ────────────────────────────────────────────────────────────

    protected function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $rule = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'title'        => [$rule, 'string', 'max:255'],
            'summary'      => ['nullable', 'string', 'max:500'],
            'content'      => [$rule, 'string'],
            'type'         => ['nullable', 'in:' . implode(',', self::VALID_TYPES)],
            'category'     => ['nullable', 'in:' . implode(',', Announcement::CATEGORIES)],
            'status'       => ['nullable', 'in:' . implode(',', Announcement::STATUSES)],
            'scheduled_at' => ['required_if:status,scheduled', 'nullable', 'date', 'after:now'],
            'is_popup'     => ['nullable', 'boolean'],
            'is_featured'  => ['nullable', 'boolean'],
            'image'        => ['nullable', 'image', 'max:5120'],
        ]);
    }

    protected function uploadImageIfPresent(Request $request): ?string
    {
        if (!$request->hasFile('image')) {
            return null;
        }

        $cloudinary = new Cloudinary(env('CLOUDINARY_URL'));
        $result = $cloudinary->uploadApi()->upload(
            $request->file('image')->getRealPath(),
            ['folder' => 'news-images']
        );

        return $result['secure_url'];
    }

    protected function format(Announcement $a): array
    {
        return [
            'id'           => $a->id,
            'title'        => $a->title,
            'slug'         => $a->slug,
            'summary'      => $a->summary,
            'content'      => $a->content ?? '',
            'message'      => $a->content ?? '', // legacy alias, kept for existing frontend readers
            'image_url'    => $a->image_url,
            'type'         => $a->type ?? 'general',
            'category'     => $a->category ?? 'general_communication',
            'is_popup'     => (bool) $a->is_popup,
            'is_featured'  => (bool) $a->is_featured,
            'is_active'    => (bool) $a->is_active,
            'status'       => $a->status,
            'published_at' => optional($a->published_at)->toISOString(),
            'scheduled_at' => optional($a->scheduled_at)->toISOString(),
            'author'       => $a->creator->name ?? null,
            'created_at'   => $a->created_at->toDateString(),
        ];
    }
}
