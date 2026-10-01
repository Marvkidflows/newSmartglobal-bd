<?php
// LOCATION: app/Http/Controllers/StaffMessageController.php
//
// The shared Admin<->Financial channel. Mounted at BOTH
// /api/admin/staff-messages and /api/financial/staff-messages — same
// controller, same data, reached via whichever role the logged-in user
// actually has. Unlike DevRequestController (which scopes to the
// sender's own requests), everyone here sees the SAME conversation —
// it's one shared channel, not a per-user inbox.

namespace App\Http\Controllers;

use App\Models\StaffMessage;
use App\Models\User;
use App\Notifications\AdminNotification;
use App\Notifications\FinancialAlertNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffMessageController extends Controller
{
    // GET /admin/staff-messages | /financial/staff-messages
    public function index(Request $request)
    {
        $messages = StaffMessage::with('sender:id,name,role')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (StaffMessage $m) => $this->format($m));

        return response()->json(['messages' => $messages]);
    }

    // POST /admin/staff-messages | /financial/staff-messages
    public function store(Request $request)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $sender = Auth::user();

        $message = StaffMessage::create([
            'sender_id' => $sender->id,
            'body'      => $validated['body'],
        ]);

        // Notify whichever side didn't just send this — an admin
        // posting pings every financial user, and vice versa. Reuses
        // the notification classes each side's own dashboard already
        // uses elsewhere, rather than adding a third generic one just
        // for this.
        $recipientRole = $sender->role === 'admin' ? 'financial' : 'admin';
        $notification  = $sender->role === 'admin'
            ? new FinancialAlertNotification('New message from Admin', $sender->name . ': ' . str($validated['body'])->limit(80))
            : new AdminNotification('New message from Financial', $sender->name . ': ' . str($validated['body'])->limit(80));

        User::where('role', $recipientRole)->get()->each(fn (User $u) => $u->notify($notification));

        return response()->json([
            'message'      => 'Sent.',
            'staff_message' => $this->format($message->fresh('sender')),
        ], 201);
    }

    protected function format(StaffMessage $m): array
    {
        return [
            'id'          => $m->id,
            'body'        => $m->body,
            'sender_id'   => $m->sender_id,
            'sender_name' => $m->sender->name ?? 'Unknown',
            'sender_role' => $m->sender->role ?? null,
            'created_at'  => $m->created_at->toIso8601String(),
        ];
    }
}
