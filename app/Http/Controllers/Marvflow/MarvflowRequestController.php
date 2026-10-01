<?php
// LOCATION: app/Http/Controllers/Marvflow/MarvflowRequestController.php
//
// MarvFlow Team Dashboard — the internal team's side of a team_request:
// viewing, replying, changing status/priority, assigning. Every write
// action logs a TeamRequestActivity row so the request detail page's
// "Activity/history" section is real, not decorative.
//
// Visibility: both marvflow_member and marvflow_lead can see every
// request (a member "permitted to view team requests" per the spec —
// simpler and more useful for a small team than hiding unassigned
// requests from members who might be the ones to pick them up). The
// permission split that DOES matter is on the WRITE side: priority
// changes and reassigning to someone else are lead-only, enforced by
// the 'marvflow.lead' middleware on those two routes in routes/api.php
// — a member can still reply, change status, add notes, and assign an
// unassigned request to themselves.

namespace App\Http\Controllers\Marvflow;

use App\Http\Controllers\Controller;
use App\Models\TeamRequest;
use App\Models\TeamRequestActivity;
use App\Models\TeamRequestMessage;
use App\Models\User;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MarvflowRequestController extends Controller
{
    // GET /marvflow/requests
    public function index(Request $request)
    {
        $query = TeamRequest::with(['sender:id,name,role', 'assignee:id,name'])
            ->withCount('messages');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->integer('assigned_to'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }
        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', "%{$term}%")
                  ->orWhere('id', 'like', "%{$term}%")
                  ->orWhereHas('sender', fn ($s) => $s->where('name', 'like', "%{$term}%"));
            });
        }

        $requests = $query->latest()->get()->map(fn (TeamRequest $r) => $this->formatSummary($r));

        return response()->json(['requests' => $requests]);
    }

    // GET /marvflow/requests/{id}
    public function show(Request $request, $id)
    {
        $teamRequest = TeamRequest::with([
            'sender:id,name,email,role',
            'assignee:id,name',
            'messages.sender:id,name,role',
            'activity.actor:id,name',
        ])->find($id);

        if (!$teamRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        return response()->json(['request' => $this->formatDetail($teamRequest)]);
    }

    // POST /marvflow/requests/{id}/reply
    public function reply(Request $request, $id)
    {
        $teamRequest = TeamRequest::find($id);
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

        TeamRequestMessage::create([
            'team_request_id' => $teamRequest->id,
            'sender_id'       => Auth::id(),
            'sender_side'     => 'marvflow',
            'body'            => $validated['body'],
            'attachment_url'  => $attachmentUrl,
        ]);

        // A reply is a natural "we're on it" signal — bump a brand new
        // request out of "New" automatically so it doesn't sit
        // miscategorized after someone has clearly already engaged with
        // it. Never overrides a status a team member set deliberately.
        if ($teamRequest->status === 'new') {
            $this->recordStatusChange($teamRequest, 'acknowledged', 'Auto-acknowledged on first reply.');
        }

        return response()->json([
            'message' => 'Reply sent.',
            'request' => $this->formatDetail($teamRequest->fresh(['sender:id,name,email,role', 'assignee:id,name', 'messages.sender:id,name,role', 'activity.actor:id,name'])),
        ]);
    }

    // POST /marvflow/requests/{id}/status
    public function updateStatus(Request $request, $id)
    {
        $teamRequest = TeamRequest::find($id);
        if (!$teamRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:new,acknowledged,in_progress,waiting_for_information,waiting_for_info,resolved,closed'],
        ]);

        // Accept the spec's exact wording ("Waiting for Information")
        // as well as the DB's shorter enum value, rather than making
        // the frontend guess which one the backend wants.
        $status = $validated['status'] === 'waiting_for_information' ? 'waiting_for_info' : $validated['status'];

        $this->recordStatusChange($teamRequest, $status);

        return response()->json([
            'message' => 'Status updated.',
            'request' => $this->formatDetail($teamRequest->fresh(['sender:id,name,email,role', 'assignee:id,name', 'messages.sender:id,name,role', 'activity.actor:id,name'])),
        ]);
    }

    // POST /marvflow/requests/{id}/priority — marvflow.lead only (route middleware)
    public function updatePriority(Request $request, $id)
    {
        $teamRequest = TeamRequest::find($id);
        if (!$teamRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        $validated = $request->validate([
            'priority' => ['required', 'in:normal,high,urgent'],
        ]);

        if ($validated['priority'] !== $teamRequest->priority) {
            TeamRequestActivity::create([
                'team_request_id' => $teamRequest->id,
                'actor_id'        => Auth::id(),
                'action'          => 'priority_changed',
                'from_value'      => $teamRequest->priority,
                'to_value'        => $validated['priority'],
            ]);
            $teamRequest->update(['priority' => $validated['priority']]);
        }

        return response()->json([
            'message' => 'Priority updated.',
            'request' => $this->formatDetail($teamRequest->fresh(['sender:id,name,email,role', 'assignee:id,name', 'messages.sender:id,name,role', 'activity.actor:id,name'])),
        ]);
    }

    // POST /marvflow/requests/{id}/assign
    // A member may only assign to themselves (claim it); a lead may
    // assign to anyone on the team. Enforced here rather than at the
    // route level since the same endpoint behaves differently based on
    // whose ID is in the payload, not on the route itself.
    public function assign(Request $request, $id)
    {
        $teamRequest = TeamRequest::find($id);
        if (!$teamRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        $targetId = $validated['assigned_to'] ?? null;
        $actor    = Auth::user();

        if ($actor->role === 'marvflow_member' && $targetId !== null && $targetId !== $actor->id) {
            return response()->json([
                'message' => 'Team members can only assign requests to themselves. Ask a Team Lead to reassign to someone else.',
            ], 403);
        }

        if ($targetId !== null) {
            $target = User::whereIn('role', ['marvflow_member', 'marvflow_lead'])->find($targetId);
            if (!$target) {
                return response()->json(['message' => 'That user is not a MarvFlow team member.'], 422);
            }
        }

        $from = $teamRequest->assignee?->name ?? 'Unassigned';

        TeamRequestActivity::create([
            'team_request_id' => $teamRequest->id,
            'actor_id'        => $actor->id,
            'action'          => $targetId ? ($teamRequest->assigned_to ? 'reassigned' : 'assigned') : 'unassigned',
            'from_value'      => $from,
            'to_value'        => $targetId ? User::find($targetId)->name : 'Unassigned',
        ]);

        $teamRequest->update(['assigned_to' => $targetId]);

        return response()->json([
            'message' => 'Assignment updated.',
            'request' => $this->formatDetail($teamRequest->fresh(['sender:id,name,email,role', 'assignee:id,name', 'messages.sender:id,name,role', 'activity.actor:id,name'])),
        ]);
    }

    // ── HELPERS ──────────────────────────────────────────────────────

    protected function recordStatusChange(TeamRequest $teamRequest, string $status, ?string $note = null): void
    {
        if ($status === $teamRequest->status) {
            return;
        }

        TeamRequestActivity::create([
            'team_request_id' => $teamRequest->id,
            'actor_id'        => Auth::id(),
            'action'          => 'status_changed',
            'from_value'      => $teamRequest->status,
            'to_value'        => $status,
            'note'            => $note,
        ]);

        $updates = ['status' => $status];
        if ($status === 'resolved') {
            $updates['resolved_at'] = now();
        }
        if ($status === 'closed') {
            $updates['closed_at'] = now();
        }
        $teamRequest->update($updates);

        // Let the sender know their request moved — this is the one
        // piece of the "SSI user can see the response/status" workflow
        // that needs an active push rather than the sender just
        // happening to reload the page.
        $teamRequest->sender?->notify(new \App\Notifications\MarvflowAlertNotification(
            'Update on Request #' . $teamRequest->id,
            'Your request "' . $teamRequest->subject . '" is now: ' . str_replace('_', ' ', ucfirst($status)),
            'status_update',
            ['team_request_id' => $teamRequest->id]
        ));
    }

    protected function uploadAttachment($file): ?string
    {
        try {
            $cloudinary = new Cloudinary(env('CLOUDINARY_URL'));
            $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
                'folder' => 'dev-requests',
            ]);
            return $result['secure_url'] ?? null;
        } catch (\Throwable $e) {
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
            'sender_name'    => $r->sender->name ?? 'Unknown',
            'sender_role'    => $r->sender->role ?? null,
            'priority'       => $r->priority,
            'status'         => $r->status,
            'assigned_to'    => $r->assignee?->name,
            'assigned_to_id' => $r->assigned_to,
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
            'sender_name'    => $r->sender->name ?? 'Unknown',
            'sender_email'   => $r->sender->email ?? null,
            'sender_role'    => $r->sender->role ?? null,
            'assigned_to'    => $r->assignee?->name,
            'assigned_to_id' => $r->assigned_to,
            'created_at'     => $r->created_at->toIso8601String(),
            'resolved_at'    => $r->resolved_at?->toIso8601String(),
            'closed_at'      => $r->closed_at?->toIso8601String(),
            'messages'       => $r->messages->map(fn (TeamRequestMessage $m) => [
                'id'             => $m->id,
                'sender_side'    => $m->sender_side,
                'sender_name'    => $m->sender->name ?? 'Unknown',
                'body'           => $m->body,
                'attachment_url' => $m->attachment_url,
                'created_at'     => $m->created_at->toIso8601String(),
            ]),
            'activity' => $r->activity->map(fn (TeamRequestActivity $a) => [
                'id'         => $a->id,
                'actor_name' => $a->actor->name ?? 'System',
                'action'     => $a->action,
                'from_value' => $a->from_value,
                'to_value'   => $a->to_value,
                'note'       => $a->note,
                'created_at' => $a->created_at->toIso8601String(),
            ]),
        ];
    }
}
