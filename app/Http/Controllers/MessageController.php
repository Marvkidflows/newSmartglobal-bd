<?php
// LOCATION: app/Http/Controllers/MessageController.php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\Message;
use App\Services\FinancialConversationService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\RichMessageBody;

class MessageController extends Controller
{
    // =========================================================================
    // ADMIN SIDE
    // =========================================================================

    /**
     * GET /admin/messages
     * Admin sees a list of ALL investors.
     * Each investor shows: last message preview + unread count.
     */
  /** Rich-text helper (plain `body` + sanitised `body_html`); resolved lazily so the constructor/DI of this controller is unchanged. */
    protected function rich(): RichMessageBody
    {
        return app(RichMessageBody::class);
    }

  public function adminIndex(Request $request)
{
    $investors = User::where('role', 'investor')->with('investorRank')->get();
    $investorIds = $investors->pluck('id');

    $allMessages = Message::whereIn('investor_id', $investorIds)
        ->orderByDesc('created_at')
        ->get()
        ->groupBy('investor_id');

    $unreadCounts = Message::whereIn('investor_id', $investorIds)
        ->where('initiated_by', 'investor')
        ->where('read_by_admin', false)
        ->get()
        ->groupBy('investor_id')
        ->map->count();

    $investors = $investors->map(function ($investor) use ($allMessages, $unreadCounts) {
        $lastMessage = $allMessages->get($investor->id)?->first();
        $unreadCount = $unreadCounts->get($investor->id, 0);

        return [
            'investor' => [
                'id'     => $investor->id,
                'name'   => $investor->name ?? $investor->full_name,
                'email'  => $investor->email,
                'status' => $investor->status ?? 'active',
                'rank'   => $investor->rankBadge(),
            ],
            'last_message' => $lastMessage ? [
              'body' => mb_substr($lastMessage->body, 0, 80, 'UTF-8') . (mb_strlen($lastMessage->body, 'UTF-8') > 80 ? '…' : ''),
                'created_at' => $lastMessage->created_at->diffForHumans(),
            ] : null,
            'unread_count' => $unreadCount,
            'has_messages' => !is_null($lastMessage),
            // raw timestamp used only for sorting, not sent differently to frontend
            '_sort_time' => $lastMessage->created_at ?? $investor->created_at,
        ];
    })
    ->sortByDesc('_sort_time')
    ->values()
    ->map(function ($item) {
        unset($item['_sort_time']);
        return $item;
    });

    $totalUnread = Message::where('initiated_by', 'investor')
        ->where('read_by_admin', false)
        ->count();

    if ($request->expectsJson()) {
        return response()->json([
            'investors'    => $investors,
            'total_unread' => $totalUnread,
        ]);
    }

    return view('admin.messages.index', compact('investors', 'totalUnread'));
}

    /**
     * GET /admin/messages/{investor}
     * Admin opens conversation thread with a specific investor.
     * Shows ALL messages between admin and this investor in order.
     */
    public function adminShow(Request $request, User $investor)
    {
        // Only works for investor users
        if ($investor->role !== 'investor') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'User is not an investor.'], 422);
            }
            abort(422);
        }

        // Get full conversation thread
        $thread = Message::conversation($investor->id)
            ->get()
            ->map(fn($m) => [
                'id'           => $m->id,
                'body'         => $m->body,
                'body_html'    => $this->rich()->forDisplay($m->body_html),
                'from'         => $m->initiated_by,  // 'admin' or 'investor'
                'subject'      => $m->subject,
                'created_at'   => $m->created_at->toDateTimeString(),
                'time_ago'     => $m->created_at->diffForHumans(),
                // Added: identity/department so admins can tell which team
                // wrote each row (Financial Team rows are read-only oversight).
                'department'   => $m->department ?? 'admin',
                'sender_label' => $m->initiated_by === 'admin' ? $m->displaySender() : null,
                'kind'         => $m->kind ?? 'message',
            ]);

        // Mark all investor messages in this thread as read by admin
        // (only those addressed to the admin/support inbox — Financial-
        // addressed rows are already stored read_by_admin = true).
        Message::where('investor_id', $investor->id)
            ->where('initiated_by', 'investor')
            ->where('read_by_admin', false)
            ->update(['read_by_admin' => true]);

        $data = [
            'investor' => [
                'id'      => $investor->id,
                'name'    => $investor->name ?? $investor->full_name,
                'email'   => $investor->email,
                'balance' => (float) ($investor->balance ?? 0),
                'status'  => $investor->status ?? 'active',
                'rank'    => $investor->rankBadge(),
            ],
            'thread' => $thread,
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }

        return view('admin.messages.show', $data);
    }

    /**
     * POST /admin/messages/{investor}
     * Admin sends a new message TO a specific investor.
     */
    public function adminSend(Request $request, User $investor)
    {
        if ($investor->role !== 'investor') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'User is not an investor.'], 422);
            }
            return back()->withErrors(['error' => 'Not an investor.']);
        }

        $validated = $request->validate([
            'subject'   => ['nullable', 'string', 'max:255'],
            'body'      => ['nullable', 'string', 'max:5000'],      // legacy plain text (still accepted)
            'body_html' => ['nullable', 'string', 'max:40000'],     // formatted message (sanitised here)
        ]);
        $content = $this->rich()->resolve($validated);               // ['body' => plain text, 'body_html' => safe html|null]

        $admin = Auth::user();

        $message = Message::create([
            'sender_id'        => $admin->id,
            'receiver_id'      => $investor->id,
            'investor_id'      => $investor->id,
            'subject'          => $validated['subject'] ?? null,
            'body'             => $content['body'],
            'body_html'        => $content['body_html'],
            'initiated_by'     => 'admin',
            'read_by_admin'    => true,   // admin wrote it so they've "read" it
            'read_by_investor' => false,  // investor hasn't seen it yet
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Message sent to investor.',
                'data'    => [
                    'id'         => $message->id,
                    'body'       => $message->body,
                    'body_html'  => $this->rich()->forDisplay($message->body_html),
                    'from'       => 'admin',
                    'created_at' => $message->created_at->toDateTimeString(),
                    'time_ago'   => $message->created_at->diffForHumans(),
                ],
            ], 201);
        }

        return back()->with('success', 'Message sent to investor.');
    }

    /**
     * DELETE /admin/messages/{message}
     * Admin deletes a specific message.
     */
    public function adminDelete(Request $request, Message $message)
    {
        // Financial Team correspondence and official notices are a permanent
        // record (compliance) — they cannot be deleted through the UI.
        if (($message->department ?? 'admin') === 'financial' || ($message->kind ?? 'message') === 'notice') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Financial Team communications and official notices are a permanent record and cannot be deleted.'], 403);
            }
            abort(403);
        }

        $message->delete();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Message deleted.']);
        }

        return redirect()->route('admin.messages.index')
            ->with('success', 'Message deleted.');
    }

    // =========================================================================
    // INVESTOR SIDE
    // =========================================================================

    /**
     * GET /investor-investment/messages
     * Investor sees their conversation with admin.
     * Shows ALL messages between them in thread order.
     */
    public function investorIndex(Request $request)
    {
        $investor = Auth::user();

        // Full correspondence for this investor — support AND Financial Team
        // rows share the one thread; `department`/`sender_label` say who wrote each.
        $rows   = Message::conversation($investor->id)->with('deposit:id,user_id,transaction_reference,amount,status')->get();
        $thread = $rows->map(fn ($m) => $this->investorRow($m));

        // Who the investor is talking to on the Financial Team: their assigned agent's NAME only (never contact details).
        $agent  = app(FinancialConversationService::class)->assignedAgent($investor);
        // Latest Financial row = highest id (the same rule the Financial Team inbox uses), never "last in created_at order",
        // which is ambiguous when two messages are written within the same second.
        $last   = $rows->where('department', 'financial')->sortBy('id')->last();

        // Count unread messages FROM admin
        $unreadCount = Message::where('investor_id', $investor->id)
            ->where('initiated_by', 'admin')
            ->where('read_by_investor', false)
            ->count();

        // Mark all admin messages as read by investor
        Message::where('investor_id', $investor->id)
            ->where('initiated_by', 'admin')
            ->where('read_by_investor', false)
            ->update(['read_by_investor' => true]);

        if ($request->expectsJson()) {
            return response()->json([
                'thread'       => $thread,
                'unread_count' => $unreadCount,
                'has_messages' => $thread->count() > 0,
                'agent'        => $agent ? ['name' => $agent->name ?: 'Your Financial Team Agent'] : null,
                // Financial conversation state for the chat header: none | awaiting_reply (investor wrote last) | answered
                'financial_status' => !$last ? 'none' : ($last->initiated_by === 'investor' ? 'awaiting_reply' : 'answered'),
            ]);
        }

        return view('investor.messages.index', compact('thread', 'unreadCount'));
    }


    /** One investor-facing thread row. Shared by the thread, the send response and (indirectly) polling, so they can never drift. */
    protected function investorRow(Message $m): array
    {
        $isStaff = $m->initiated_by === 'admin';
        $dep     = ($m->deposit_id && $m->relationLoaded('deposit')) ? $m->deposit : ($m->deposit_id ? $m->deposit()->first() : null);
        // Defence in depth: a deposit is only ever described to the investor who owns it.
        $dep     = ($dep && (int) $dep->user_id === (int) $m->investor_id) ? $dep : null;

        return [
            'id'           => $m->id,
            'body'         => $m->body,
            'body_html'    => $this->rich()->forDisplay($m->body_html),
            'from'         => $m->initiated_by,  // 'admin' or 'investor' (unchanged)
            'subject'      => $m->subject,
            'created_at'   => $m->created_at->toDateTimeString(),
            'time_ago'     => $m->created_at->diffForHumans(),
            'department'   => $m->department ?? 'admin',
            'sender_label' => $isStaff ? $m->displaySender() : 'You',
            'kind'         => $m->kind ?? 'message',
            'topic'        => $m->topic ?? 'general',
            'deposit'      => $dep ? ['id' => $dep->id, 'reference' => $dep->transaction_reference, 'amount' => (float) $dep->amount, 'status' => $dep->status] : null,
            'was_unread'   => $isStaff && !$m->read_by_investor,
            'status'       => $isStaff
                                ? ($m->read_by_investor ? 'read' : 'unread')
                                : (($m->department ?? 'admin') === 'financial'
                                    ? ($m->read_by_financial ? 'read' : 'delivered')
                                    : ($m->read_by_admin ? 'read' : 'delivered')),
        ];
    }

    /**
     * GET /investor-investment/messages/create
     * Show compose form (not needed for React but kept for Blade)
     */
    public function investorCreate(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['status' => 'ok']);
        }
        return view('investor.messages.create');
    }

    /**
     * POST /investor-investment/messages
     * Investor sends a message to admin.
     */
    public function investorStore(Request $request)
    {
        $investor = Auth::user();

        $validated = $request->validate([
            'subject'    => ['nullable', 'string', 'max:255'],
            'body'       => ['nullable', 'string', 'max:5000'],      // legacy plain text (still accepted)
            'body_html'  => ['nullable', 'string', 'max:40000'],     // formatted message (sanitised here)
            // Added: which team the investor is writing to. Defaults to the
            // existing support inbox so current clients are unaffected.
            'department' => ['nullable', 'in:admin,financial'],
            // Financial Team enquiries: what it is about, the investor's own deposit (ownership checked below) and a per-send
            // key so a double click / retry returns the SAME message instead of a duplicate.
            'topic'        => ['nullable', 'in:general,deposit'],
            'deposit_id'   => ['nullable', 'integer'],
            'client_token' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
        ]);

        $content    = $this->rich()->resolve($validated);            // ['body' => plain text, 'body_html' => safe html|null]
        $department = $validated['department'] ?? 'admin';

        if ($department === 'financial') {
            // Optional link to one of the investor's OWN deposits. Someone else's deposit id is treated as not found, so it can
            // neither be linked nor probed.
            $deposit = null;
            if (!empty($validated['deposit_id'])) {
                $deposit = Deposit::where('id', $validated['deposit_id'])->where('user_id', $investor->id)->first();
                if (!$deposit) return response()->json(['message' => 'That deposit could not be found.'], 404);
            }

            $result = app(FinancialConversationService::class)->submit(
                $investor, $content, $validated['subject'] ?? null,
                $validated['topic'] ?? Message::TOPIC_GENERAL, $deposit, $validated['client_token'] ?? null
            );
            if (!$result) {
                return response()->json(['message' => 'The Financial Team is currently unavailable.'], 503);
            }
            $message = $result['message'];

            return response()->json([
                'message'   => $result['duplicate'] ? 'Message already sent.' : 'Message sent to the Financial Team!',
                'duplicate' => $result['duplicate'],
                'data'      => $this->investorRow($message),
            ], $result['duplicate'] ? 200 : 201);
        }

        // Find any admin to receive the message
        $admin = User::where('role', 'admin')->first();

        if (!$admin) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Support is currently unavailable.'], 503);
            }
            return back()->withErrors(['error' => 'Support unavailable.']);
        }

        $message = Message::create([
            'sender_id'        => $investor->id,
            'receiver_id'      => $admin->id,
            'investor_id'      => $investor->id,
            'subject'          => $validated['subject'] ?? null,
            'body'             => $content['body'],
            'body_html'        => $content['body_html'],
            'initiated_by'     => 'investor',
            'read_by_admin'    => false,  // admin hasn't read it yet
            'read_by_investor' => true,   // investor wrote it
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Message sent to support!',
                'data'    => [
                    'id'         => $message->id,
                    'body'       => $message->body,
                    'body_html'  => $this->rich()->forDisplay($message->body_html),
                    'from'       => 'investor',
                    'created_at' => $message->created_at->toDateTimeString(),
                    'time_ago'   => $message->created_at->diffForHumans(),
                ],
            ], 201);
        }

        return redirect()->route('investor-investment.messages.index')
            ->with('success', 'Message sent to support!');
    }

    /**
     * GET /investor-investment/messages/{message}
     * Investor views a single message (kept for Blade compatibility).
     * In React we just use investorIndex which returns the full thread.
     */
    public function investorShow(Request $request, Message $message)
    {
        $investor = Auth::user();

        // Investor can only see messages in their own conversation
        if ($message->investor_id !== $investor->id) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
            abort(403);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => [
                    'id'         => $message->id,
                    'body'       => $message->body,
                    'body_html'  => $this->rich()->forDisplay($message->body_html),
                    'from'       => $message->initiated_by,
                    'created_at' => $message->created_at->toDateTimeString(),
                ],
            ]);
        }

        return view('investor.messages.show', compact('message'));
    }
    /**
     * GET /investor-investment/messages/unread-count
     * Read-only badge count. Added because the layout used to poll the
     * thread endpoint, which marks everything read as a side effect — that
     * made read/unread state meaningless.
     */
    public function investorUnreadCount()
    {
        $count = Message::where('investor_id', Auth::id())
            ->where('initiated_by', 'admin')
            ->where('read_by_investor', false)
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    /**
 * POST /api/public/contact-support
 * Used by deactivated (logged-out) users to reach support without a session.
 */
private const SUPPORT_ACK = 'If that address belongs to an account, your message has been sent to our support team.';

public function publicContactSupport(Request $request)
{
    $validated = $request->validate([
        'email' => ['required', 'email'],
        'body'  => ['required', 'string', 'max:5000'],
    ]);

    $investor = User::where('email', $validated['email'])
        ->where('role', 'investor')
        ->first();

    // Uniform answer: this public endpoint must not reveal which e-mail addresses have an account (account enumeration).
    // Nothing is stored for an unknown address.
    if (!$investor) {
        return response()->json([
            'success' => true,
            'message' => self::SUPPORT_ACK,
        ]);
    }

    $admin = User::where('role', 'admin')->first();
    if (!$admin) {
        // Same public answer as above (no enumeration); the failure is only logged for operators.
        \Log::warning('Public contact-support: no administrator account available to receive the request.');
        return response()->json(['success' => true, 'message' => self::SUPPORT_ACK]);
    }

    Message::create([
        'sender_id'        => $investor->id,
        'receiver_id'      => $admin->id,
        'investor_id'      => $investor->id,
        'subject'          => 'Account Deactivated — Support Request',
        'body'             => $validated['body'],
        'initiated_by'     => 'investor',
        'read_by_admin'    => false,
        'read_by_investor' => true,
    ]);

    return response()->json([
        'success' => true,
        'message' => self::SUPPORT_ACK,
    ]);
}
}
