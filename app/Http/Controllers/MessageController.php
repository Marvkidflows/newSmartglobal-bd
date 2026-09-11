<?php
// LOCATION: app/Http/Controllers/MessageController.php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
  public function adminIndex(Request $request)
{
    $investors = User::where('role', 'investor')->get();
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
                'from'         => $m->initiated_by,  // 'admin' or 'investor'
                'subject'      => $m->subject,
                'created_at'   => $m->created_at->toDateTimeString(),
                'time_ago'     => $m->created_at->diffForHumans(),
            ]);

        // Mark all investor messages in this thread as read by admin
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
            'subject' => ['nullable', 'string', 'max:255'],
            'body'    => ['required', 'string', 'max:5000'],
        ]);

        $admin = Auth::user();

        $message = Message::create([
            'sender_id'        => $admin->id,
            'receiver_id'      => $investor->id,
            'investor_id'      => $investor->id,
            'subject'          => $validated['subject'] ?? null,
            'body'             => $validated['body'],
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

        // Get full thread between this investor and admin
        $thread = Message::conversation($investor->id)
            ->get()
            ->map(fn($m) => [
                'id'         => $m->id,
                'body'       => $m->body,
                'from'       => $m->initiated_by,  // 'admin' or 'investor'
                'subject'    => $m->subject,
                'created_at' => $m->created_at->toDateTimeString(),
                'time_ago'   => $m->created_at->diffForHumans(),
            ]);

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
            ]);
        }

        return view('investor.messages.index', compact('thread', 'unreadCount'));
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
            'subject' => ['nullable', 'string', 'max:255'],
            'body'    => ['required', 'string', 'max:5000'],
        ]);

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
            'body'             => $validated['body'],
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
                    'from'       => $message->initiated_by,
                    'created_at' => $message->created_at->toDateTimeString(),
                ],
            ]);
        }

        return view('investor.messages.show', compact('message'));
    }
    /**
 * POST /api/public/contact-support
 * Used by deactivated (logged-out) users to reach support without a session.
 */
public function publicContactSupport(Request $request)
{
    $validated = $request->validate([
        'email' => ['required', 'email'],
        'body'  => ['required', 'string', 'max:5000'],
    ]);

    $investor = User::where('email', $validated['email'])
        ->where('role', 'investor')
        ->first();

    if (!$investor) {
        return response()->json([
            'success' => false,
            'message' => 'We could not find an account with that email.',
        ], 404);
    }

    $admin = User::where('role', 'admin')->first();
    if (!$admin) {
        return response()->json([
            'success' => false,
            'message' => 'Support is currently unavailable.',
        ], 503);
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
        'message' => 'Your message has been sent to our support team.',
    ]);
}
}
