<?php
// LOCATION: app/Http/Controllers/DevRequestController.php
//
// MarvFlow Team Dashboard — the Smart System Investment side. Mounted
// under BOTH /api/admin/dev-requests* (admin middleware) and
// /api/financial/dev-requests* (financial middleware) in routes/api.php
// — same controller, same behavior, reached via whichever role the
// logged-in user actually has. Not under Admin\ or Financial\ namespace
// because it isn't specific to either; it's the "authorized SSI user"
// side of this feature as a whole.
//
// Every query here is scoped to sender_id = the logged-in user — an
// admin or financial user only ever sees the requests they personally
// submitted, never each other's (see the spec: "the existing authorized
// Admin/Management users should be able to see the requests they
// submitted" — their own, not a shared pool).

namespace App\Http\Controllers;

use App\Jobs\SendMarvflowTelegramNotification;
use App\Models\TeamRequest;
use App\Models\TeamRequestActivity;
use App\Models\TeamRequestMessage;
use App\Services\MarvflowNotificationService;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DevRequestController extends Controller
{
    // GET /admin/dev-requests | /financial/dev-requests
    public function index(Request $request)
    {
        $requests = TeamRequest::where('sender_id', Auth::id())
            ->with('assignee:id,name')
            ->withCount('messages')
            ->latest()
            ->get()
            ->map(fn (TeamRequest $r) => $this->formatSummary($r));

        return response()->json(['requests' => $requests]);
    }

    // GET /admin/dev-requests/{id} | /financial/dev-requests/{id}
    public function show(Request $request, $id)
    {
        $teamRequest = TeamRequest::where('sender_id', Auth::id())
            ->with(['assignee:id,name', 'messages.sender:id,name,role'])
            ->find($id);

        if (!$teamRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        return response()->json([
            'request' => $this->formatDetail($teamRequest),
        ]);
    }

    // POST /admin/dev-requests | /financial/dev-requests
    public function store(Request $request, MarvflowNotificationService $marvflowNotifier)
    {
        $validated = $request->validate([
            'subject'    => ['required', 'string', 'max:200'],
            'message'    => ['required', 'string', 'max:5000'],
            'priority'   => ['required', 'in:normal,high,urgent'],
            'attachment' => ['nullable', 'image', 'max:5120'],
        ]);

        $attachmentUrl = null;
        if ($request->hasFile('attachment')) {
            $attachmentUrl = $this->uploadAttachment($request->file('attachment'));
        }

        $user = Auth::user();

        // Save first — this row exists in the database and is already
        // visible on the MarvFlow dashboard before either notification
        // step below even starts. Nothing after this point can cause
        // the request itself to be lost.
        $teamRequest = TeamRequest::create([
            'sender_id'      => $user->id,
            'subject'        => $validated['subject'],
            'message'        => $validated['message'],
            'priority'       => $validated['priority'],
            'status'         => 'new',
            'attachment_url' => $attachmentUrl,
        ]);

        TeamRequestActivity::create([
            'team_request_id' => $teamRequest->id,
            'actor_id'        => $user->id,
            'action'          => 'created',
            'to_value'        => 'new',
        ]);

        // In-app notification to the MarvFlow team — synchronous like
        // FinancialNotificationService's equivalent; this is a fast
        // local DB write, not an outbound API call, so there's no
        // reason to queue it the way the Telegram send below is.
        $marvflowNotifier->newRequest($teamRequest->id, $user->name, $validated['priority'], $validated['subject']);

        // Telegram — queued (see job class docblock for why), so a slow
        // or failed Telegram API call never delays this response or
        // risks the request itself.
        SendMarvflowTelegramNotification::dispatch($teamRequest->id);

        return response()->json([
            'message' => 'Request submitted successfully. Our development team has received your request.',
            'request' => $this->formatDetail($teamRequest->fresh()),
        ], 201);
    }

    // POST /admin/dev-requests/{id}/reply | /financial/dev-requests/{id}/reply
    public function reply(Request $request, $id)
    {
        $teamRequest = TeamRequest::where('sender_id', Auth::id())->find($id);

        if (!$teamRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        $validated = $request->validate([
            'body'       => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'image', 'max:5120'],
        ]);

        $attachmentUrl = null;
        if ($request->hasFile('attachment')) {
            $attachmentUrl = $this->uploadAttachment($request->file('attachment'));
        }

        $message = TeamRequestMessage::create([
            'team_request_id' => $teamRequest->id,
            'sender_id'       => Auth::id(),
            'sender_side'     => 'ssi',
            'body'            => $validated['body'],
            'attachment_url'  => $attachmentUrl,
        ]);

        // A reply from the SSI side to a request that had already been
        // marked resolved/closed reopens it — otherwise it would sit
        // answered-but-hidden in a "resolved" filter the MarvFlow team
        // isn't actively watching.
        if ($teamRequest->isResolved()) {
            $teamRequest->update(['status' => 'in_progress', 'resolved_at' => null, 'closed_at' => null]);
            TeamRequestActivity::create([
                'team_request_id' => $teamRequest->id,
                'actor_id'        => Auth::id(),
                'action'          => 'status_changed',
                'from_value'      => 'resolved/closed',
                'to_value'        => 'in_progress',
                'note'            => 'Reopened by a new reply from the sender.',
            ]);
        }

        app(MarvflowNotificationService::class)->notifyTeam(
            'New Reply on Request #' . $teamRequest->id,
            Auth::user()->name . ' replied on "' . $teamRequest->subject . '"',
            'reply',
            ['team_request_id' => $teamRequest->id]
        );

        return response()->json([
            'message' => 'Reply sent.',
            'request' => $this->formatDetail($teamRequest->fresh(['assignee:id,name', 'messages.sender:id,name,role'])),
        ]);
    }

    // ── HELPERS ──────────────────────────────────────────────────────

    protected function uploadAttachment($file): ?string
    {
        try {
            $cloudinary = new Cloudinary(env('CLOUDINARY_URL'));
            $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
                'folder' => 'dev-requests',
            ]);
            return $result['secure_url'] ?? null;
        } catch (\Throwable $e) {
            // An attachment upload failure should never block the
            // request/reply itself from saving — it just goes through
            // without the screenshot.
            \Illuminate\Support\Facades\Log::warning('Dev request attachment upload failed: ' . $e->getMessage());
            return null;
        }
    }

    protected function formatSummary(TeamRequest $r): array
    {
        return [
            'id'             => $r->id,
            'display_id'     => $r->displayId(),
            'subject'        => $r->subject,
            'priority'       => $r->priority,
            'status'         => $r->status,
            'assigned_to'    => $r->assignee?->name,
            'messages_count' => $r->messages_count,
            'created_at'     => $r->created_at->toIso8601String(),
        ];
    }

    protected function formatDetail(TeamRequest $r): array
    {
        return [
            'id'             => $r->id,
            'display_id'     => $r->displayId(),
            'subject'        => $r->subject,
            'message'        => $r->message,
            'priority'       => $r->priority,
            'status'         => $r->status,
            'attachment_url' => $r->attachment_url,
            'assigned_to'    => $r->assignee?->name,
            'created_at'     => $r->created_at->toIso8601String(),
            'resolved_at'    => $r->resolved_at?->toIso8601String(),
            'messages'       => $r->messages->map(fn (TeamRequestMessage $m) => [
                'id'             => $m->id,
                'sender_side'    => $m->sender_side,
                'sender_name'    => $m->sender->name ?? 'Unknown',
                'body'           => $m->body,
                'attachment_url' => $m->attachment_url,
                'created_at'     => $m->created_at->toIso8601String(),
            ]),
        ];
    }
}
