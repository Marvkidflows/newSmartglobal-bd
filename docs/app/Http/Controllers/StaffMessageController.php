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
use App\Services\RichMessageBody;
use App\Services\RichTextSanitizer;

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
            'subject'   => ['nullable', 'string', 'max:150'],     // optional; plain text
            'body'      => ['nullable', 'string', 'max:5000'],    // legacy plain text (still accepted)
            'body_html' => ['nullable', 'string', 'max:40000'],   // formatted message (sanitised here)
        ]);
        $content = app(RichMessageBody::class)->resolve($validated);          // ['body' => plain text, 'body_html' => safe html|null]
        $subject = isset($validated['subject']) ? app(RichTextSanitizer::class)->plain($validated['subject'], 150) : '';
        $subject = $subject === '' ? null : $subject;

        $sender = Auth::user();

        $message = StaffMessage::create([
            'sender_id' => $sender->id,
            'subject'   => $subject,
            'body'      => $content['body'],
            'body_html' => $content['body_html'],
        ]);

        // Notify whichever side didn't just send this — an admin
        // posting pings every financial user, and vice versa. Reuses
        // the notification classes each side's own dashboard already
        // uses elsewhere, rather than adding a third generic one just
        // for this.
        $recipientRole = $sender->role === 'admin' ? 'financial' : 'admin';
        $preview       = $subject !== null ? str($subject)->limit(80) : str($content['body'])->limit(80);   // plain text only — never HTML in an alert
        $notification  = $sender->role === 'admin'
            ? new FinancialAlertNotification('New message from Admin', $sender->name . ': ' . $preview)
            : new AdminNotification('New message from Financial', $sender->name . ': ' . $preview);

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
            'subject'     => $m->subject,
            'body'        => $m->body,
            'body_html'   => app(RichMessageBody::class)->forDisplay($m->body_html),
            'sender_id'   => $m->sender_id,
            'sender_name' => $m->sender->name ?? 'Unknown',
            'sender_role' => $m->sender->role ?? null,
            'created_at'  => $m->created_at->toIso8601String(),
        ];
    }
}
