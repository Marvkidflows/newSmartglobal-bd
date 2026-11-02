<?php
// LOCATION: app/Http/Controllers/Concerns/HandlesMarvflowThreads.php
//
// Shared conversation logic for the two MarvFlow message controllers (MarvFlow side and SSI inbox side), so threading,
// read tracking, reply storage and alert de-duplication exist in ONE place.
//
// Model: a conversation = its first message (thread_id NULL) + every row whose thread_id points at it.
//   direction 'to_ssi'      = written by MarvFlow   (first message or follow-up)
//   direction 'to_marvflow' = written by SSI        (reply; recipient_id = the conversation owner, set by the server)
// recipient_role on EVERY row of a conversation is the SSI inbox it belongs to ('admin' | 'financial').

namespace App\Http\Controllers\Concerns;

use App\Http\Middleware\EnsureMessagingAccountActive;
use App\Models\MarvflowMessage;
use App\Models\User;
use App\Notifications\MarvflowMessageNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait HandlesMarvflowThreads
{
    /** The first message of the conversation a row belongs to (null if it is an orphan). */
    protected function rootOf(MarvflowMessage $m): ?MarvflowMessage
    {
        if ($m->thread_id === null) return $m;
        $root = MarvflowMessage::find($m->thread_id);
        return ($root && $root->thread_id === null) ? $root : null;
    }

    /**
     * Every row of the conversation, oldest first, with the people the UI needs.
     * $ownerView = the MarvFlow owner's view: replies still PENDING DELIVERY (pending_since set) are never included.
     */
    protected function threadRows(MarvflowMessage $root, bool $ownerView = false)
    {
        return MarvflowMessage::with(['sender:id,name,role', 'reader:id,name'])
            ->where(fn ($q) => $q->where('id', $root->id)->orWhere('thread_id', $root->id))
            ->when($ownerView, fn ($q) => $q->whereNull('pending_since'))
            ->orderBy('id')->get();
    }

    /** Per-conversation counters for a page of first messages: total, unread each way, latest activity. Keyed by root id. */
    protected function threadStats(array $rootIds, bool $ownerView = false): array
    {
        if (!$rootIds) return [];

        $rows = MarvflowMessage::query()
            ->where(fn ($q) => $q->whereIn('id', $rootIds)->orWhereIn('thread_id', $rootIds))
            ->when($ownerView, fn ($q) => $q->whereNull('pending_since'))     // the owner never counts replies pending delivery
            ->selectRaw("COALESCE(thread_id, id) as tid, COUNT(*) as total,"
                . " SUM(CASE WHEN direction = 'to_ssi' AND read_at IS NULL THEN 1 ELSE 0 END) as unread_ssi,"
                . " SUM(CASE WHEN direction = 'to_marvflow' AND read_at IS NULL THEN 1 ELSE 0 END) as unread_mf,"
                . " MAX(created_at) as last_at")
            ->groupBy(DB::raw('COALESCE(thread_id, id)'))
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->tid] = [
                'replies'    => max(0, (int) $r->total - 1),
                'unread_ssi' => (int) $r->unread_ssi,
                'unread_mf'  => (int) $r->unread_mf,
                'last_at'    => $r->last_at,
            ];
        }
        return $out;
    }

    /** SQL ordering by latest activity in the conversation; with no replies it is identical to "newest first by id". */
    protected function lastActivityOrderSql(): string
    {
        return '(SELECT MAX(m2.id) FROM marvflow_messages m2 WHERE m2.id = marvflow_messages.id OR m2.thread_id = marvflow_messages.id) DESC';
    }

    /**
     * Mark the unread rows of ONE direction in a conversation as read, by $reader. Atomic: only rows still unread are touched,
     * so the first opener is the one recorded. The caller decides whether $reader is entitled to be recorded as the reader.
     */
    protected function markThreadRead(MarvflowMessage $root, User $reader, string $direction, ?int $onlyRecipientId = null): void
    {
        MarvflowMessage::query()
            ->where(fn ($q) => $q->where('id', $root->id)->orWhere('thread_id', $root->id))
            ->where('direction', $direction)
            ->when($onlyRecipientId, fn ($q) => $q->whereNull('pending_since'))   // a reply pending delivery is never marked read by (or for) the owner
            ->when($onlyRecipientId, fn ($q, $id) => $q->where('recipient_id', $id))
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'read_by' => $reader->id, 'status' => 'read']);
    }

    /** One message of a conversation, as the UI shows it (body re-sanitised on the way out). */
    protected function threadItem(MarvflowMessage $m): array
    {
        $fromSsi = $m->direction === MarvflowMessage::TO_MARVFLOW;
        $role    = $m->sender?->role;

        return [
            'id'          => $m->id,
            'direction'   => $fromSsi ? MarvflowMessage::TO_MARVFLOW : MarvflowMessage::TO_SSI,
            'from'        => $fromSsi ? 'ssi' : 'marvflow',
            'sender_name' => $m->sender?->name ?? ($fromSsi ? 'SSI' : 'MarvFlow'),
            'sender_label'=> $fromSsi ? (MarvflowMessage::RECIPIENTS[$role] ?? 'SSI') : 'MarvFlow',
            'body_html'   => $this->sanitizer->sanitize($m->body_html),
            'sent_at'     => $m->created_at?->toIso8601String(),
            'is_read'     => $m->read_at !== null,
            'read_at'     => $m->read_at?->toIso8601String(),
            'read_by'     => $m->reader?->name,
            'pending_delivery' => $m->pending_since !== null,     // staff view only: stored, not yet visible to the (restricted) owner
        ];
    }

    /**
     * Normalise a reply/quick-message body for its conversation kind. Returns ['html' => safe, 'text' => plain] or ['error' => msg].
     * email  → rich text through the allow-list sanitiser.   direct → plain text only, escaped into the same safe shape.
     */
    protected function safeBody(string $kind, string $raw): array
    {
        if ($kind === 'direct') {
            $text = preg_replace('/(?![\n\t])\p{Cc}/u', '', str_replace(["\r\n", "\r"], "\n", $raw)) ?? '';
            $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? '');
            if ($text === '') return ['error' => 'Please type a message.'];
            if (mb_strlen($text) > MarvflowMessage::DIRECT_MAX) return ['error' => 'Quick messages are limited to ' . MarvflowMessage::DIRECT_MAX . ' characters. Use Compose Email for longer messages.'];
            return ['html' => $this->sanitizer->sanitize('<p>' . str_replace("\n", '<br>', e($text)) . '</p>'), 'text' => $text];
        }

        $html = $this->sanitizer->sanitize($raw);
        $text = $this->sanitizer->toText($html);
        if ($text === '') return ['error' => 'The message is empty.'];
        if (mb_strlen($html) > 20000) return ['error' => 'The message is too long. Please shorten it.'];
        return ['html' => $html, 'text' => $text];
    }

    /**
     * Save one row of an existing conversation. The client never supplies thread, direction, recipient or role: they all come
     * from the verified root. Returns ['row' => MarvflowMessage, 'duplicate' => bool], or null if it could not be saved.
     */
    protected function saveThreadRow(MarvflowMessage $root, User $sender, string $direction, ?int $recipientId, string $html, string $text, string $token, bool $held = false): ?array
    {
        if ($existing = MarvflowMessage::where('sender_id', $sender->id)->where('client_token', $token)->first()) {
            return ['row' => $existing, 'duplicate' => true];
        }

        try {
            $row = new MarvflowMessage();
            $row->forceFill([
                'thread_id' => $root->id, 'sender_id' => $sender->id, 'recipient_role' => $root->recipient_role, 'direction' => $direction,
                'recipient_id' => $recipientId, 'kind' => $root->kind ?? 'email', 'subject' => $root->subject, 'body_html' => $html,
                'body_text' => $text, 'client_token' => $token, 'status' => 'sent', 'pending_since' => $held ? now() : null,
            ])->save();
        } catch (QueryException $e) {
            if ($existing = MarvflowMessage::where('sender_id', $sender->id)->where('client_token', $token)->first()) {
                return ['row' => $existing, 'duplicate' => true];   // two identical submits racing: the unique index let one through
            }
            Log::error('MarvFlow reply could not be saved', ['sender_id' => $sender->id, 'thread_id' => $root->id, 'error' => $e->getCode()]);
            return null;
        }

        return ['row' => $row, 'duplicate' => false];
    }

    /**
     * Bell alert, collapsed per conversation: a user who already has an UNREAD alert for this conversation is not alerted again
     * (the unread badge still counts the new message). Returns 1 if an alert was created. Alert text never contains the body.
     */
    protected function alertOnce(User $user, string $title, string $text, int $messageId, string $link, int $rootId): int
    {
        try {
            if ($this->hasUnreadAlert($user, $rootId)) return 0;
            $user->notify(new MarvflowMessageNotification($title, $text, $messageId, $link, $rootId));
            return 1;
        } catch (\Throwable $e) {
            Log::warning('MarvFlow message alert failed', ['message_id' => $messageId, 'user_id' => $user->id]);   // ids only, never content
            return 0;
        }
    }

    protected function hasUnreadAlert(User $user, int $rootId): bool
    {
        return $this->unreadAlerts($user, $rootId)->isNotEmpty();
    }

    /** Opening a conversation clears this user's own bell alerts for it. */
    protected function clearAlerts(User $user, int $rootId): void
    {
        try {
            $this->unreadAlerts($user, $rootId)->each->markAsRead();
        } catch (\Throwable $e) {
            Log::warning('MarvFlow alert could not be cleared', ['user_id' => $user->id, 'thread_id' => $rootId]);
        }
    }

    protected function unreadAlerts(User $user, int $rootId)
    {
        return $user->unreadNotifications()->where('type', MarvflowMessageNotification::class)->latest()->limit(100)->get()
            ->filter(fn ($n) => (int) ($n->data['thread_id'] ?? $n->data['message_id'] ?? 0) === $rootId);
    }

    /** Same definition of "active" as the messaging middleware (empty status counts as active, like the rest of the app). */
    protected function isActiveAccount(?User $user): bool
    {
        return EnsureMessagingAccountActive::isActive($user);
    }

    /**
     * Release replies that were stored PENDING DELIVERY while this user's account was restricted. Called only on requests that
     * already passed the active-account check, so "release" can only ever happen once the owner is active again. Atomic (only
     * rows still held are updated) and idempotent. The owner is then alerted ONCE per conversation, subject-only, never the text.
     */
    protected function releaseHeldReplies(User $owner): int
    {
        // Cheap pre-check: the overwhelming majority of requests have nothing held, and then no lock is taken.
        if (!MarvflowMessage::where('recipient_id', $owner->id)->where('direction', MarvflowMessage::TO_MARVFLOW)->whereNotNull('pending_since')->exists()) {
            return 0;
        }

        // EXACTLY ONCE: the owner's row is locked while the held rows are read, released and alerted. A second request arriving at the
        // same instant waits for the lock, then finds nothing held, so it neither releases nor alerts again. (On top of that the update
        // itself only touches rows still held, and alerts collapse per conversation.)
        return DB::transaction(function () use ($owner) {
            User::whereKey($owner->id)->lockForUpdate()->first();

            $held = MarvflowMessage::where('recipient_id', $owner->id)->where('direction', MarvflowMessage::TO_MARVFLOW)
                ->whereNotNull('pending_since')->with('sender:id,name,role')->orderBy('id')->get();
            if ($held->isEmpty()) return 0;

            $released = MarvflowMessage::whereIn('id', $held->pluck('id'))->whereNotNull('pending_since')->update(['pending_since' => null]);

            foreach ($held->groupBy('thread_id') as $rootId => $rows) {
                $last   = $rows->last();
                $root   = MarvflowMessage::find((int) $rootId);
                $direct = ($last->kind ?? 'email') === 'direct';
                $label  = MarvflowMessage::RECIPIENTS[$last->recipient_role] ?? 'SSI';
                $name   = $last->sender?->name ?? $label;
                $this->alertOnce(
                    $owner, "Reply from {$label}",
                    $direct ? "{$name} replied to your quick message" : "{$name}: Re: " . ($root?->subject ?? ''),
                    $last->id, $direct ? '/marvflow/message-admin' : '/marvflow/messages', (int) $rootId
                );
            }

            return $released;
        });
    }

    protected function activeUsersWithRole(string $role)
    {
        return User::where('role', $role)->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->get(['id', 'role']);
    }

    protected function invalidField(string $field, string $message)
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
