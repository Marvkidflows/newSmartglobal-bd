<?php
// LOCATION: app/Http/Controllers/MarvflowInboxController.php
//
// The SSI side of MarvFlow conversations: the Admin inbox and the Financial Team inbox. One controller, mounted twice
// (/admin/marvflow-messages and /financial/marvflow-messages); the inbox is fixed by the ROUTE, never by client input.
//
// Visibility (mirrors the existing shared Admin<->Financial channel): every user holding the recipient role sees every
// conversation addressed to that role. The Admin inbox is for admins only. The Financial inbox is for the Financial Team; admins
// may also read and answer it (the same "admins retain overall platform control" rule FinancialMiddleware already applies) but an
// admin opening it does NOT mark it read — "read" only ever means "opened by someone in the addressed role".
//
// Replies: any user who may open an inbox may answer a conversation in it. The reply is stored in the same conversation, is
// addressed (server-side) to the MarvFlow user who started it, and alerts only that user.
//
// RESTRICTED OWNER (status other than `active`): only an ADMIN may reply, and only after explicitly confirming (`confirm_pending`).
// The reply is stored PENDING DELIVERY (pending_since set): no alert, invisible to and uncounted for the owner, and released only the
// first time the owner uses messaging while active again. The Financial Team cannot reply to such a conversation.
// Like every MarvFlow messaging endpoint, these routes also require the signed-in staff account itself to be `active`
// (EnsureMessagingAccountActive).

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesMarvflowThreads;
use App\Models\MarvflowMessage;
use App\Models\User;
use App\Services\RichTextSanitizer;
use Illuminate\Http\Request;

class MarvflowInboxController extends Controller
{
    use HandlesMarvflowThreads;

    public function __construct(protected RichTextSanitizer $sanitizer) {}

    // GET …/marvflow-messages — one row per conversation (first messages), newest activity first
    public function index(Request $request)
    {
        $inbox = $this->inbox($request);
        $kind  = $request->validate(['kind' => ['nullable', 'in:email,direct']])['kind'] ?? null;

        $page = MarvflowMessage::with(['sender:id,name,role', 'reader:id,name'])
            ->where('recipient_role', $inbox)->where('direction', MarvflowMessage::TO_SSI)->whereNull('thread_id')
            ->when($kind, fn ($q) => $q->where('kind', $kind))->orderByRaw($this->lastActivityOrderSql())->paginate(25);

        $stats = $this->threadStats($page->getCollection()->pluck('id')->all());

        return response()->json([
            'data'         => $page->getCollection()->map(fn ($m) => $this->present($m, false, $stats[$m->id] ?? null))->values(),
            'meta'         => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'unread_count' => $this->unread($inbox),
            'unread_by_kind' => [
                'email'  => $this->unread($inbox, 'email'),
                'direct' => $this->unread($inbox, 'direct'),
            ],
            'inbox_label'  => MarvflowMessage::RECIPIENTS[$inbox],
        ]);
    }

    // GET …/marvflow-messages/unread-count
    public function unreadCount(Request $request)
    {
        $inbox = $this->inbox($request);
        return response()->json(['unread_count' => $this->unread($inbox)]);
    }

    // GET …/marvflow-messages/{id} — the whole conversation. Opening it as a member of the addressed role marks the MarvFlow
    // messages in it read (and clears that user's bell alerts for it).
    public function show(Request $request, $id)
    {
        $inbox = $this->inbox($request);
        $root  = $this->inboxRoot($inbox, $id);
        if (!$root) return response()->json(['message' => 'Message not found.'], 404);

        $user = $request->user();
        if ($user->role === $inbox) {
            // atomic: only rows still unread are touched, so only the first opener is recorded
            $this->markThreadRead($root, $user, MarvflowMessage::TO_SSI);
        }
        $this->clearAlerts($user, $root->id);

        return $this->conversation($root, 200, false, $user);
    }

    // POST …/marvflow-messages/{id}/reply   { body, client_token }
    public function reply(Request $request, $id)
    {
        $inbox = $this->inbox($request);
        $v = $request->validate([
            'body'         => ['required', 'string', 'max:40000'],
            'client_token' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
        ]);

        $root = $this->inboxRoot($inbox, $id);
        if (!$root) return response()->json(['message' => 'Message not found.'], 404);

        $user = $request->user();
        if (MarvflowMessage::where('sender_id', $user->id)->where('client_token', $v['client_token'])->exists()) {
            return $this->conversation($root, 200, true, $user);          // a retry of a reply that was already saved
        }

        // The addressee is whoever STARTED the conversation — resolved here, never taken from the request.
        $owner = User::find($root->sender_id);
        if (!$owner || !in_array($owner->role, ['marvflow_member', 'marvflow_lead'], true)) {
            return $this->invalidField('recipient', 'The MarvFlow user who started this conversation no longer has a MarvFlow account, so a reply cannot be delivered. Nothing was sent.');
        }

        $held = !$this->isActiveAccount($owner);
        if ($held) {
            if ($user->role !== 'admin') {
                return response()->json(['success' => false, 'code' => 'reply_not_permitted', 'message' => $this->replyInfo($root, $user)['notice']], 403);
            }
            if (!$request->boolean('confirm_pending')) {
                return response()->json(['success' => false, 'code' => 'owner_restricted', 'message' => $this->replyInfo($root, $user)['notice'] . ' Send again with confirmation to store it as pending delivery.'], 409);
            }
        }

        $body = $this->safeBody($root->kind ?? 'email', $v['body']);
        if (isset($body['error'])) return $this->invalidField('body', $body['error']);

        $saved = $this->saveThreadRow($root, $user, MarvflowMessage::TO_MARVFLOW, $owner->id, $body['html'], $body['text'], $v['client_token'], $held);
        if (!$saved) return response()->json(['message' => 'The reply could not be saved. Nothing was sent. Please try again.'], 500);
        if ($saved['duplicate']) return $this->conversation($root, 200, true, $user);

        $row   = $saved['row'];
        if ($held) {                                    // stored only: no alert now; the owner is alerted when it is released
            $row->forceFill(['notified_count' => 0])->save();
            return $this->conversation($root, 201, false, $user, true);
        }
        $label = MarvflowMessage::RECIPIENTS[$inbox];
        $direct = ($root->kind ?? 'email') === 'direct';
        $link  = $direct ? '/marvflow/message-admin' : '/marvflow/messages';
        // Subject only for formal email; for a quick message just the fact that a reply arrived — never the content.
        $count = $this->alertOnce(
            $owner,
            "Reply from {$label}",
            $direct ? "{$user->name} replied to your quick message" : "{$user->name}: Re: {$root->subject}",
            $row->id, $link, $root->id
        );
        $row->forceFill(['notified_count' => $count])->save();

        return $this->conversation($root, 201, false, $user);
    }

    /**
     * The inbox comes from the ROUTE definition (->defaults('inbox', …)), read by name so controller-argument order can
     * never matter, and is authorised here on every call (the route middleware is a second layer, not the only one).
     */
    protected function inbox(Request $request): string
    {
        $inbox = (string) $request->route('inbox');
        $role  = $request->user()?->role;

        abort_unless(array_key_exists($inbox, MarvflowMessage::RECIPIENTS), 404);
        abort_unless($inbox === 'admin' ? $role === 'admin' : in_array($role, ['financial', 'admin'], true), 403, 'You do not have access to this inbox.');

        return $inbox;
    }

    /** The conversation $id belongs to, only if it is addressed to THIS inbox (another inbox's conversation is "not found"). */
    protected function inboxRoot(string $inbox, $id): ?MarvflowMessage
    {
        $m = MarvflowMessage::where('recipient_role', $inbox)->find($id);
        if (!$m) return null;

        $root = $this->rootOf($m);
        return ($root && $root->direction === MarvflowMessage::TO_SSI && $root->recipient_role === $inbox) ? $root : null;
    }

    /** Unread = MarvFlow-written messages in this inbox nobody in the addressed role has opened yet (first messages AND follow-ups). */
    protected function unread(string $inbox, ?string $kind = null): int
    {
        return MarvflowMessage::where('recipient_role', $inbox)->where('direction', MarvflowMessage::TO_SSI)->whereNull('read_at')
            ->when($kind, fn ($q, $k) => $q->where('kind', $k))->count();
    }

    protected function conversation(MarvflowMessage $root, int $status = 200, bool $duplicate = false, ?User $viewer = null, bool $pending = false)
    {
        $root  = $root->fresh(['sender:id,name,role', 'reader:id,name']);
        $stats = $this->threadStats([$root->id]);

        return response()->json([
            'message'          => $this->present($root, true, $stats[$root->id] ?? null),
            'thread'           => $this->threadRows($root)->map(fn ($m) => $this->threadItem($m))->values(),   // staff see pending replies too (flagged)
            'duplicate'        => $duplicate,
            'pending_delivery' => $pending,
            'reply'            => $this->replyInfo($root, $viewer),
        ], $status);
    }

    /**
     * What the reply box should do for THIS staff user in THIS conversation (the server still enforces it on send):
     *   allowed  — may a reply be written at all;  pending — it will be stored pending delivery (owner restricted);  notice — text to show.
     * Only a yes/no about the owner's account is exposed, never the exact status.
     */
    protected function replyInfo(MarvflowMessage $root, ?User $viewer): array
    {
        $owner = User::find($root->sender_id);

        if (!$owner || !in_array($owner->role, ['marvflow_member', 'marvflow_lead'], true)) {
            return ['allowed' => false, 'pending' => false, 'notice' => 'The MarvFlow user who started this conversation no longer has a MarvFlow account, so a reply cannot be delivered.'];
        }
        if ($this->isActiveAccount($owner)) {
            return ['allowed' => true, 'pending' => false, 'notice' => null];
        }
        if ($viewer && $viewer->role === 'admin') {
            return ['allowed' => true, 'pending' => true, 'notice' => "This MarvFlow user's account is currently restricted. Your reply will be stored as PENDING DELIVERY: they will not be notified, cannot see it, and it will only become available after their account is active again."];
        }
        return ['allowed' => false, 'pending' => false, 'notice' => "This MarvFlow user's account is currently restricted. Only an Admin can reply to this conversation at this stage."];
    }

    protected function present(MarvflowMessage $m, bool $full, ?array $stats = null): array
    {
        $direct = ($m->kind ?? 'email') === 'direct';

        $out = [
            'id'          => $m->id,
            'kind'        => $direct ? 'direct' : 'email',
            'kind_label'  => $direct ? 'Quick message' : 'Email',
            'subject'     => $direct ? 'Quick message' : $m->subject,
            'preview'     => mb_substr(preg_replace('/\s+/u', ' ', $m->body_text), 0, 140),
            'body_html'   => $full ? $this->sanitizer->sanitize($m->body_html) : null,        // re-sanitised on the way out
            'sender_name' => $m->sender?->name ?? 'MarvFlow',
            'sender_role' => $m->sender?->role,
            'sent_at'     => $m->created_at?->toIso8601String(),
            'is_read'     => $m->read_at !== null,
            'read_at'     => $m->read_at?->toIso8601String(),
            'read_by'     => $m->reader?->name,
        ];

        if ($stats !== null) {
            // a conversation is "unread" while ANY MarvFlow message in it is unopened (a follow-up in an old conversation counts)
            $out['is_read']       = $stats['unread_ssi'] === 0;
            $out['unread_count']  = $stats['unread_ssi'];
            $out['reply_count']   = $stats['replies'];
            $out['last_activity'] = $stats['last_at'] ? \Illuminate\Support\Carbon::parse($stats['last_at'])->toIso8601String() : $out['sent_at'];
        }

        return $out;
    }
}
