<?php
// LOCATION: app/Http/Controllers/Marvflow/MarvflowMessageController.php
//
// MarvFlow team ↔ SSI conversations, MarvFlow side. Two kinds share ONE table, ONE delivery path and ONE inbox:
//   • 'email'  — formal: recipient (Admin | Financial Team), plain-text subject, sanitised rich-text body.
//   • 'direct' — "Message Admin": a short plain-text note to the SSI Admin, no subject.
// A conversation is its first message plus replies (see HandlesMarvflowThreads). SSI replies arrive as rows addressed to the
// conversation OWNER (the MarvFlow user who wrote the first message); only the owner can read or answer the conversation.
// Reached only through the `marvflow` middleware group (marvflow_member / marvflow_lead). Same backend, database and `users`
// table as the SSI dashboards, so "delivered" simply means: the row is saved and the SSI inbox endpoints read that same row.
//
// Security: the recipient is a ROLE chosen from a fixed list (direct messages are ALWAYS addressed to the admin role) —
// never a user id from the client; the sender is always the authenticated user; thread, direction and recipient of a reply are
// derived on the server from the verified conversation; rich text is sanitised on the server; a client token makes retries
// idempotent; alerts never contain message content.

namespace App\Http\Controllers\Marvflow;

use App\Http\Controllers\Concerns\HandlesMarvflowThreads;
use App\Http\Controllers\Controller;
use App\Models\MarvflowMessage;
use App\Models\User;
use App\Services\RichTextSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class MarvflowMessageController extends Controller
{
    use HandlesMarvflowThreads;

    public const DIRECT_MAX = MarvflowMessage::DIRECT_MAX;

    public function __construct(protected RichTextSanitizer $sanitizer) {}

    // GET /marvflow/messages/recipients — which inboxes exist right now (so the UI never offers an unreachable one)
    public function recipients()
    {
        return response()->json(['recipients' => collect(MarvflowMessage::RECIPIENTS)->map(fn ($label, $key) => [
            'key' => $key, 'label' => $label, 'available' => $this->activeRecipients($key)->count() > 0,
        ])->values()]);
    }

    // GET /marvflow/messages/unread-count — SSI replies the signed-in member has not opened yet (drives the sidebar badge)
    public function unreadCount(Request $request)
    {
        $this->releaseHeldReplies($request->user());      // replies held while the account was restricted become visible now (the account is active here)
        $id = $request->user()->id;
        return response()->json([
            'unread_count'   => $this->unreadReplies($id),
            'unread_by_kind' => ['email' => $this->unreadReplies($id, 'email'), 'direct' => $this->unreadReplies($id, 'direct')],
        ]);
    }

    // GET /marvflow/messages?kind=email|direct&page=
    //   (no kind / email) → the member's OWN conversations, one row each, newest activity first, with reply/unread counters
    //   kind=direct       → the quick-message chat: the member's own quick messages AND the SSI replies addressed to them
    public function index(Request $request)
    {
        $v  = $request->validate(['kind' => ['nullable', Rule::in(['email', 'direct'])]]);
        $me = $request->user();
        $this->releaseHeldReplies($me);

        if (($v['kind'] ?? null) === 'direct') {
            $page = MarvflowMessage::with(['reader:id,name', 'sender:id,name,role'])
                ->where('kind', 'direct')
                ->where(fn ($q) => $q
                    ->where(fn ($a) => $a->where('sender_id', $me->id)->where('direction', MarvflowMessage::TO_SSI))
                    ->orWhere(fn ($b) => $b->where('recipient_id', $me->id)->where('direction', MarvflowMessage::TO_MARVFLOW)->whereNull('pending_since')))
                ->orderByDesc('id')->paginate(25);

            // Opening the chat shows these replies, so record them as read — by the addressed owner only.
            $unseen = $page->getCollection()->filter(fn ($m) => $m->direction === MarvflowMessage::TO_MARVFLOW && $m->read_at === null);
            if ($unseen->isNotEmpty()) {
                MarvflowMessage::whereIn('id', $unseen->pluck('id'))->where('recipient_id', $me->id)->whereNull('read_at')
                    ->update(['read_at' => now(), 'read_by' => $me->id, 'status' => 'read']);
                $unseen->each(function ($m) use ($me) { $m->forceFill(['read_at' => now(), 'read_by' => $me->id, 'status' => 'read'])->syncOriginal(); $m->setRelation('reader', $me); });
                $this->clearAlertsFor($me, $unseen->pluck('thread_id')->filter()->unique()->all());
            }

            $items = $page->getCollection()->map(fn ($m) => $this->present($m, true))->values();
        } else {
            $page = MarvflowMessage::with('reader:id,name')
                ->where('sender_id', $me->id)->where('direction', MarvflowMessage::TO_SSI)->whereNull('thread_id')
                ->when($v['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
                ->orderByRaw($this->lastActivityOrderSql())->paginate(25);

            $stats = $this->threadStats($page->getCollection()->pluck('id')->all(), true);
            $items = $page->getCollection()->map(fn ($m) => $this->present($m, false, $stats[$m->id] ?? null))->values();
        }

        return response()->json([
            'data' => $items,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'unread_count' => $this->unreadReplies($me->id),
        ]);
    }

    // GET /marvflow/messages/{id} — own conversation only (anyone else's is 404, not 403: existence is not revealed).
    // Opening it records the SSI replies as read (by the owner) and clears the owner's bell alerts for it.
    public function show(Request $request, $id)
    {
        $me   = $request->user();
        $this->releaseHeldReplies($me);
        $root = $this->ownedRoot($me, $id);
        if (!$root) return response()->json(['message' => 'Message not found.'], 404);

        $this->markThreadRead($root, $me, MarvflowMessage::TO_MARVFLOW, $me->id);
        $this->clearAlerts($me, $root->id);

        return $this->conversation($root);
    }

    // POST /marvflow/messages   { recipient: admin|financial, subject, body (HTML), client_token }
    public function store(Request $request)
    {
        $v = $request->validate([
            'recipient'    => ['required', 'string', Rule::in(array_keys(MarvflowMessage::RECIPIENTS))],
            'subject'      => ['required', 'string', 'min:3', 'max:300'],
            'body'         => ['required', 'string', 'max:40000'],
            'client_token' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
        ]);

        $sender = $request->user();
        if ($existing = $this->findByToken($sender->id, $v['client_token'])) return $this->duplicate($existing);

        $subject = $this->sanitizer->plain($v['subject']);
        $html    = $this->sanitizer->sanitize($v['body']);
        $text    = $this->sanitizer->toText($html);

        if (mb_strlen($subject) < 3)  return $this->invalid('subject', 'Please enter a subject (at least 3 characters).');
        if ($text === '')             return $this->invalid('body', 'The message is empty.');
        if (mb_strlen($html) > 20000) return $this->invalid('body', 'The message is too long. Please shorten it.');

        return $this->deliver($sender, $v['recipient'], 'email', $subject, $html, $text, $v['client_token']);
    }

    // POST /marvflow/messages/direct   { body (plain text), client_token, new_conversation? } — "Message Admin"
    // The recipient is NOT a parameter: a quick message always goes to the SSI Admin inbox (every active admin, the same
    // policy as email to the Admin). Anything the client sends for recipient/sender/role/thread is ignored.
    //
    // Conversation rule (decided ON THE SERVER, never by the client): consecutive quick messages from the same user to the same
    // SSI role are ONE running conversation — before and after SSI replies. A message is attached to the user's most recent
    // quick conversation for that role; only `new_conversation: true` (the "Start a new conversation" button) starts a fresh one.
    public function storeDirect(Request $request)
    {
        $v = $request->validate([
            'body'             => ['required', 'string', 'max:' . (self::DIRECT_MAX * 2)],
            'client_token'     => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'new_conversation' => ['sometimes', 'boolean'],
        ]);

        $sender = $request->user();
        if ($existing = $this->findByToken($sender->id, $v['client_token'])) return $this->duplicate($existing);

        $body = $this->safeBody('direct', $v['body']);
        if (isset($body['error'])) return $this->invalid('body', $body['error']);

        $role     = 'admin';                                  // server-fixed
        $startNew = $request->boolean('new_conversation');

        // The user row is locked for the duration of look-up + insert, so two quick messages sent at the same instant cannot
        // both decide "there is no open conversation" and create two. (Ignored by SQLite, which serialises writers anyway.)
        return DB::transaction(function () use ($sender, $role, $startNew, $body, $v) {
            User::whereKey($sender->id)->lockForUpdate()->first();

            if ($existing = $this->findByToken($sender->id, $v['client_token'])) return $this->duplicate($existing);

            $open = $startNew ? null : $this->openQuickConversation($sender->id, $role);

            return $open
                ? $this->deliverFollowUp($sender, $open, $body['html'], $body['text'], $v['client_token'])
                : $this->deliver($sender, $role, 'direct', '', $body['html'], $body['text'], $v['client_token']);
        });
    }

    /**
     * The sender's OPEN quick conversation for a role = their most recent quick first-message addressed to that role.
     * Every condition is mandatory, so a message can never attach to someone else's conversation, to an email conversation,
     * or to a conversation addressed to a different SSI role.
     */
    protected function openQuickConversation(int $senderId, string $role): ?MarvflowMessage
    {
        return MarvflowMessage::query()
            ->where('sender_id', $senderId)->where('kind', 'direct')->where('recipient_role', $role)
            ->where('direction', MarvflowMessage::TO_SSI)->whereNull('thread_id')
            ->orderByDesc('id')->first();
    }

    /** A further quick message in an existing conversation: same recipients check, saved once (idempotent), same alert rules. */
    protected function deliverFollowUp(User $sender, MarvflowMessage $root, string $html, string $text, string $token)
    {
        $recipients = $this->activeRecipients($root->recipient_role);
        if ($recipients->isEmpty()) {
            return $this->invalid('recipient', 'There is no active account for ' . MarvflowMessage::RECIPIENTS[$root->recipient_role] . ' to receive this message yet. Nothing was sent — please contact SSI another way.');
        }

        $saved = $this->saveThreadRow($root, $sender, MarvflowMessage::TO_SSI, null, $html, $text, $token);
        if (!$saved) return response()->json(['message' => 'The message could not be saved. Nothing was sent. Please try again.'], 500);
        if ($saved['duplicate']) return $this->duplicate($saved['row']);

        $row = $saved['row'];
        $row->forceFill(['notified_count' => $this->notify($row, $recipients, $sender, $root)])->save();

        return response()->json(['message' => $this->present($row->fresh('reader'), true), 'duplicate' => false, 'new_conversation' => false], 201);
    }

    // POST /marvflow/messages/{id}/reply   { body, client_token }
    // Follow-up inside one of the member's OWN conversations. Thread, kind, SSI inbox and recipients come from the verified
    // conversation; the client cannot choose them (extra fields are ignored).
    public function reply(Request $request, $id)
    {
        $v = $request->validate([
            'body'         => ['required', 'string', 'max:40000'],
            'client_token' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
        ]);

        $me   = $request->user();
        $root = $this->ownedRoot($me, $id);
        if (!$root) return response()->json(['message' => 'Message not found.'], 404);

        if ($existing = $this->findByToken($me->id, $v['client_token'])) return $this->conversation($root, 200, true);

        $body = $this->safeBody($root->kind ?? 'email', $v['body']);
        if (isset($body['error'])) return $this->invalid('body', $body['error']);

        $recipients = $this->activeRecipients($root->recipient_role);
        if ($recipients->isEmpty()) {
            return $this->invalid('recipient', 'There is no active account for ' . MarvflowMessage::RECIPIENTS[$root->recipient_role] . ' to receive this message yet. Nothing was sent — please contact SSI another way.');
        }

        $saved = $this->saveThreadRow($root, $me, MarvflowMessage::TO_SSI, null, $body['html'], $body['text'], $v['client_token']);
        if (!$saved) return response()->json(['message' => 'The message could not be saved. Nothing was sent. Please try again.'], 500);
        if ($saved['duplicate']) return $this->conversation($root, 200, true);

        $row = $saved['row'];
        $row->forceFill(['notified_count' => $this->notify($row, $recipients, $me, $root)])->save();

        return $this->conversation($root, 201, false);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** One delivery path for both kinds: recipients exist → save once (idempotent) → alert → confirm. */
    protected function deliver(User $sender, string $role, string $kind, string $subject, string $html, string $text, string $token)
    {
        $recipients = $this->activeRecipients($role);
        if ($recipients->isEmpty()) {
            return $this->invalid('recipient', 'There is no active account for ' . MarvflowMessage::RECIPIENTS[$role] . ' to receive this message yet. Nothing was sent — please contact SSI another way.');
        }

        try {
            $message = new MarvflowMessage();
            $message->forceFill([
                'sender_id' => $sender->id, 'recipient_role' => $role, 'kind' => $kind, 'subject' => $subject,
                'body_html' => $html, 'body_text' => $text, 'client_token' => $token, 'status' => 'sent',
            ])->save();
        } catch (QueryException $e) {
            // Two identical submits racing: the unique (sender_id, client_token) index let only one through.
            if ($existing = $this->findByToken($sender->id, $token)) return $this->duplicate($existing);
            Log::error('MarvFlow message could not be saved', ['sender_id' => $sender->id, 'recipient' => $role, 'kind' => $kind, 'error' => $e->getCode()]);
            return response()->json(['message' => 'The message could not be saved. Nothing was sent. Please try again.'], 500);
        }

        // Saved = in the SSI inbox. Bell alerts are a courtesy on top; a failure here must not hide a saved message.
        $message->forceFill(['notified_count' => $this->notify($message, $recipients, $sender, $message)])->save();

        return response()->json(['message' => $this->present($message->fresh('reader'), true), 'duplicate' => false, 'new_conversation' => true], 201);
    }

    protected function duplicate(MarvflowMessage $existing)
    {
        return response()->json(['message' => $this->present($existing, true), 'duplicate' => true], 200);
    }

    /** The conversation response: the first message (existing shape) plus the whole thread. */
    protected function conversation(MarvflowMessage $root, int $status = 200, bool $duplicate = false)
    {
        $root = $root->fresh('reader');
        $stats = $this->threadStats([$root->id], true);

        return response()->json([
            'message'   => $this->present($root, true, $stats[$root->id] ?? null),
            'thread'    => $this->threadRows($root, true)->map(fn ($m) => $this->threadItem($m))->values(),
            'duplicate' => $duplicate,
        ], $status);
    }

    /** The conversation $id belongs to — only if $me started it. Everything else (other members, replies of nobody) is "not found". */
    protected function ownedRoot(User $me, $id): ?MarvflowMessage
    {
        $m = MarvflowMessage::where(fn ($q) => $q->where('sender_id', $me->id)->orWhere(fn ($r) => $r->where('recipient_id', $me->id)->whereNull('pending_since')))->find($id);
        if (!$m) return null;

        $root = $this->rootOf($m);
        return ($root && $root->sender_id === $me->id && $root->direction === MarvflowMessage::TO_SSI) ? $root : null;
    }

    protected function unreadReplies(int $userId, ?string $kind = null): int
    {
        return MarvflowMessage::where('recipient_id', $userId)->where('direction', MarvflowMessage::TO_MARVFLOW)->whereNull('read_at')->whereNull('pending_since')
            ->when($kind, fn ($q, $k) => $q->where('kind', $k))->count();
    }

    protected function clearAlertsFor(User $me, array $rootIds): void
    {
        foreach ($rootIds as $rid) $this->clearAlerts($me, (int) $rid);
    }

    protected function activeRecipients(string $role)
    {
        return $this->activeUsersWithRole($role);
    }

    protected function findByToken(int $senderId, string $token): ?MarvflowMessage
    {
        return MarvflowMessage::with('reader:id,name')->where('sender_id', $senderId)->where('client_token', $token)->first();
    }

    /** Alert every active user holding the addressed role (collapsed per conversation). $root is the conversation's first message. */
    protected function notify(MarvflowMessage $m, $recipients, User $sender, ?MarvflowMessage $root = null): int
    {
        $root ??= $m;
        $link   = $m->recipient_role === 'admin' ? '/admin/marvflow-messages' : '/financial/marvflow-messages';
        $name   = $sender->name ?? 'MarvFlow';
        $isNew  = $m->id === $root->id;
        // Subject for formal email; for a quick message only the fact that one arrived — never the content.
        [$title, $body] = $m->kind === 'direct'
            ? ['Quick message from MarvFlow', $isNew ? "{$name} sent you a quick message" : "{$name} sent another quick message"]
            : [$isNew ? 'New message from MarvFlow' : 'Reply from MarvFlow', "{$name}: " . ($isNew ? '' : 'Re: ') . $root->subject];

        $count = 0;
        foreach ($recipients as $user) $count += $this->alertOnce($user, $title, $body, $m->id, $link, $root->id);
        return $count;
    }

    protected function invalid(string $field, string $message)
    {
        return $this->invalidField($field, $message);
    }

    /** Status is only ever what the system can verify: saved (sent) or opened by a recipient-role user (read). */
    protected function present(MarvflowMessage $m, bool $full, ?array $stats = null): array
    {
        $label   = MarvflowMessage::RECIPIENTS[$m->recipient_role] ?? $m->recipient_role;
        $read    = $m->status === 'read' && $m->read_at;
        $direct  = ($m->kind ?? 'email') === 'direct';
        $fromSsi = $m->direction === MarvflowMessage::TO_MARVFLOW;

        $out = [
            'id'              => $m->id,
            'thread_id'       => $m->thread_id ?? $m->id,
            'direction'       => $fromSsi ? MarvflowMessage::TO_MARVFLOW : MarvflowMessage::TO_SSI,
            'from'            => $fromSsi ? 'ssi' : 'marvflow',
            'kind'            => $direct ? 'direct' : 'email',
            'kind_label'      => $direct ? 'Quick message' : 'Email',
            'recipient'       => $m->recipient_role,
            'recipient_label' => $label,
            'subject'         => $direct ? 'Quick message' : $m->subject,
            'sender_name'     => $m->relationLoaded('sender') ? $m->sender?->name : null,
            'preview'         => mb_substr(preg_replace('/\s+/u', ' ', $m->body_text), 0, 140),
            'body_html'       => $full ? $this->sanitizer->sanitize($m->body_html) : null,    // re-sanitised on the way out too
            'status'          => $read ? 'read' : 'sent',
            'status_label'    => $fromSsi
                ? 'Reply from ' . ($m->sender?->name ?? $label)
                : ($read ? 'Read by ' . ($m->reader?->name ?? $label) . ' on ' . $m->read_at->toDayDateTimeString() : "Sent — saved in the {$label} inbox"),
            'sent_at'         => $m->created_at?->toIso8601String(),
            'read_at'         => $m->read_at?->toIso8601String(),
            'is_read'         => $m->read_at !== null,
        ];

        if ($stats !== null) {
            $out += ['reply_count' => $stats['replies'], 'unread_replies' => $stats['unread_mf'], 'last_activity' => $stats['last_at'] ? \Illuminate\Support\Carbon::parse($stats['last_at'])->toIso8601String() : $out['sent_at']];
        }

        return $out;
    }
}
