<?php
// LOCATION: app/Http/Controllers/Financial/FinancialMessageController.php
//
// Financial Team mailbox. NOT a new messaging system: it reads and writes
// the existing `messages` table (one conversation per investor, the same
// rows MessageController serves to admins and investors), scoped with
// department = 'financial'. Staff-side rows keep initiated_by = 'admin'
// so every existing query — including the investor's unread badge —
// continues to work; `department` + `sender_label` carry the identity.
//
// Financial rows are never mixed into the Admin support inbox's unread
// counts (they are stored read_by_admin = true); admins can still open
// the thread for oversight through the existing admin messages screen.
//
// Identity: every staff message sent from here is stamped
// "Smart System Investment — Financial Team". It can never be sent as an
// individual investor or another department.

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Notifications\FinancialAlertNotification;
use App\Services\FinancialAuditService;
use App\Services\FinancialMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class FinancialMessageController extends Controller
{
    public function __construct(
        protected FinancialAuditService $audit,
        protected FinancialMailService $mail,
    ) {}

    // GET /financial/messages  — conversations (searchable)
    public function index(Request $request)
    {
        Gate::authorize('financial.communicate');

        $v = $request->validate([
            'q'      => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:all,unread'],
        ]);

        $base = Message::financial();

        if (!empty($v['q'])) {
            $s = $v['q'];
            $base->where(fn ($w) => $w->where('subject', 'like', "%{$s}%")
                ->orWhere('body', 'like', "%{$s}%")
                ->orWhereHas('investor', fn ($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")));
        }
        if (($v['filter'] ?? 'all') === 'unread') {
            $base->whereIn('investor_id', Message::unreadByFinancial()->select('investor_id'));
        }

        $page = $base->selectRaw('investor_id, MAX(id) as last_id')
            ->groupBy('investor_id')->orderByDesc('last_id')->paginate(30);

        $ids     = collect($page->items())->pluck('investor_id');
        $lastIds = collect($page->items())->pluck('last_id');
        $last    = Message::whereIn('id', $lastIds)->get()->keyBy('investor_id');
        $unread  = Message::unreadByFinancial()->whereIn('investor_id', $ids)
            ->selectRaw('investor_id, COUNT(*) as c')->groupBy('investor_id')->pluck('c', 'investor_id');
        $users   = User::whereIn('id', $ids)->where('role', 'investor')->get(['id', 'name', 'email', 'status'])->keyBy('id');

        $conversations = $ids->map(function ($id) use ($last, $unread, $users) {
            $u = $users->get($id);
            $m = $last->get($id);
            if (!$u || !$m) return null;
            return [
                'investor'     => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status ?? 'active'],
                'last_message' => [
                    'preview'    => Str::limit($m->body, 90),
                    'subject'    => $m->subject,
                    'from'       => $m->initiated_by === 'investor' ? 'investor' : 'financial',
                    'kind'       => $m->kind,
                    'created_at' => $m->created_at->toIso8601String(),
                ],
                'unread_count' => (int) ($unread[$id] ?? 0),
            ];
        })->filter()->values();

        return response()->json([
            'conversations' => $conversations,
            'total_unread'  => Message::unreadByFinancial()->count(),
            'meta'          => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    // GET /financial/messages/unread-count  (sidebar badge)
    public function unreadCount()
    {
        Gate::authorize('financial.communicate');
        return response()->json(['unread' => Message::unreadByFinancial()->count()]);
    }

    // GET /financial/messages/{investor}  — thread + mark investor messages read
    public function show(Request $request, User $investor)
    {
        Gate::authorize('financial.communicate');
        $this->ensureInvestor($investor);

        $rows = Message::financial()->conversation($investor->id)->with('sender:id,name')->get();

        Message::unreadByFinancial()->where('investor_id', $investor->id)->update(['read_by_financial' => true]);

        return response()->json([
            'investor' => ['id' => $investor->id, 'name' => $investor->name, 'email' => $investor->email, 'status' => $investor->status ?? 'active'],
            'thread'   => $rows->map(fn (Message $m) => $this->format($m, $investor))->values(),
        ]);
    }

    // POST /financial/messages/{investor}/send  — new message or reply
    public function send(Request $request, User $investor)
    {
        Gate::authorize('financial.communicate');
        $this->ensureInvestor($investor);

        $v = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body'    => ['required', 'string', 'max:5000'],
            'kind'    => ['nullable', 'in:message,notice'],
            'also_email' => ['nullable', 'boolean'],
        ]);
        $kind = $v['kind'] ?? 'message';
        if ($kind === 'notice') {
            Gate::authorize('financial.issue-notices');
        }
        if ($investor->status === 'deactivated') {
            return response()->json(['message' => 'This investor account is deactivated and cannot receive messages.'], 422);
        }

        $actor = $request->user();

        $message = DB::transaction(function () use ($actor, $investor, $v, $kind) {
            $m = Message::create([
                'sender_id'         => $actor->id,
                'receiver_id'       => $investor->id,
                'investor_id'       => $investor->id,
                'subject'           => $v['subject'] ?? null,
                'body'              => $v['body'],
                'initiated_by'      => 'admin',
                'department'        => 'financial',
                'sender_label'      => Message::FINANCIAL_SENDER_LABEL,
                'kind'              => $kind,
                'read_by_admin'     => true,
                'read_by_financial' => true,
                'read_by_investor'  => false,
            ]);

            if ($kind === 'notice') {
                $this->audit->record($actor, 'communication.notice_issued', 'message', $m->id, $investor->id,
                    null, ['kind' => 'notice', 'subject' => $m->subject, 'recipient_count' => 1], null);
            }
            return $m;
        });

        $investor->notify($this->notification($kind, $v['subject'] ?? null, $message->id));

        // Optional email copy. The in-app message above is already saved;
        // an email problem is reported back but never undoes it.
        $email = null;
        if ($request->boolean('also_email')) {
            $email = $this->mail->sendNow($actor, $investor, $v['subject'] ?? null, $v['body'], $kind);
        }

        return response()->json([
            'message' => $kind === 'notice' ? 'Notice issued.' : 'Message sent.',
            'data'    => $this->format($message->load('sender:id,name'), $investor),
            'email'   => $email,
        ], 201);
    }

    // POST /financial/messages/broadcast  — official communication to many
    public function broadcast(Request $request)
    {
        Gate::authorize('financial.issue-notices');

        $v = $request->validate([
            'scope'         => ['required', 'in:selected,all'],
            'investor_ids'  => ['required_if:scope,selected', 'array', 'min:1', 'max:500'],
            'investor_ids.*'=> ['integer', 'distinct'],
            'subject'       => ['required', 'string', 'max:255'],
            'body'          => ['required', 'string', 'max:5000'],
            'kind'          => ['nullable', 'in:message,notice'],
            'also_email'    => ['nullable', 'boolean'],
        ]);
        $kind      = $v['kind'] ?? 'notice';
        $actor     = $request->user();
        $alsoEmail = $request->boolean('also_email');

        // Recipients are resolved server-side from the users table —
        // frontend-supplied ids are only ever a filter over real, active
        // investor accounts (staff/other roles and deactivated accounts
        // are dropped, never messaged).
        $recipients = User::where('role', 'investor')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'deactivated'))
            ->when($v['scope'] === 'selected', fn ($q) => $q->whereIn('id', $v['investor_ids']))
            ->pluck('id');

        if ($recipients->isEmpty()) {
            return response()->json(['message' => 'No eligible investors found for this communication.'], 422);
        }

        // Refuse BEFORE anything is created: with no real queue, emailing a
        // large audience would time the request out half-way through.
        if ($alsoEmail && config('queue.default') === 'sync' && $recipients->count() > FinancialMailService::SYNC_LIMIT) {
            return response()->json([
                'message' => "Emailing {$recipients->count()} investors needs a real queue (QUEUE_CONNECTION=database with a running queue worker); the safe limit without one is " . FinancialMailService::SYNC_LIMIT . '. Untick "Also send by email" or select fewer investors.',
            ], 422);
        }

        $broadcastId = 'BC-' . strtoupper(Str::random(10));
        $now         = now();

        DB::transaction(function () use ($recipients, $v, $kind, $actor, $broadcastId, $now, $alsoEmail) {
            foreach ($recipients->chunk(200) as $chunk) {
                Message::insert($chunk->map(fn ($id) => [
                    'sender_id'         => $actor->id,
                    'receiver_id'       => $id,
                    'investor_id'       => $id,
                    'subject'           => $v['subject'],
                    'body'              => $v['body'],
                    'initiated_by'      => 'admin',
                    'department'        => 'financial',
                    'sender_label'      => Message::FINANCIAL_SENDER_LABEL,
                    'kind'              => $kind,
                    'broadcast_id'      => $broadcastId,
                    // raw insert (no casts) — explicit ints, never PHP bools
                    'read_by_admin'     => 1,
                    'read_by_financial' => 1,
                    'read_by_investor'  => 0,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ])->all());
            }

            $firstId = (int) Message::where('broadcast_id', $broadcastId)->min('id');
            $this->audit->record($actor, 'communication.broadcast_issued', 'message', $firstId, null, null, [
                'broadcast_id'    => $broadcastId,
                'kind'            => $kind,
                'scope'           => $v['scope'],
                'subject'         => $v['subject'],
                'recipient_count' => $recipients->count(),
                'also_emailed'    => $alsoEmail,
            ], null);
        });

        // Bell notifications after commit — the message rows are the record;
        // a failed notification must never roll the communication back.
        $notification = $this->notification($kind, $v['subject'], null);
        foreach (User::whereIn('id', $recipients)->get()->chunk(200) as $chunk) {
            try { Notification::send($chunk, $notification); } catch (\Throwable $e) { report($e); }
        }

        // Optional email copies, queued after the in-app records are committed.
        $emailsQueued = 0;
        if ($alsoEmail) {
            foreach (User::whereIn('id', $recipients)->get() as $investor) {
                try {
                    $this->mail->queue($actor, $investor, $v['subject'], $v['body'], $broadcastId, $kind);
                    $emailsQueued++;
                } catch (\Throwable $e) { report($e); }
            }
        }

        return response()->json([
            'message'         => "Communication sent to {$recipients->count()} investor(s)."
                . ($alsoEmail ? " {$emailsQueued} email cop" . ($emailsQueued === 1 ? 'y' : 'ies') . ' queued for delivery — track them in Email Center → Sent Emails / Logs.' : ''),
            'broadcast_id'    => $broadcastId,
            'recipient_count' => $recipients->count(),
            'emails_queued'   => $emailsQueued,
        ], 201);
    }

    // GET /financial/messages/broadcasts  — sent multi-recipient history
    public function broadcasts(Request $request)
    {
        Gate::authorize('financial.communicate');
        $s = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        $q = Message::financial()->whereNotNull('broadcast_id')
            ->selectRaw('broadcast_id, MIN(sender_id) as sender_id, MIN(subject) as subject, MIN(kind) as kind,
                         MIN(created_at) as sent_at, COUNT(*) as recipients, SUM(CASE WHEN read_by_investor THEN 1 ELSE 0 END) as read_count')
            ->groupBy('broadcast_id')->orderByDesc('sent_at');

        if ($s) {
            $q->where(fn ($w) => $w->where('subject', 'like', "%{$s}%")->orWhere('body', 'like', "%{$s}%"));
        }

        $page    = $q->paginate(20);
        $senders = User::whereIn('id', collect($page->items())->pluck('sender_id'))->pluck('name', 'id');

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => [
                'broadcast_id' => $r->broadcast_id,
                'subject'      => $r->subject,
                'kind'         => $r->kind,
                'sender_label' => Message::FINANCIAL_SENDER_LABEL,
                'issued_by'    => $senders[$r->sender_id] ?? 'Unknown',
                'sent_at'      => \Carbon\Carbon::parse($r->sent_at)->toIso8601String(),
                'recipients'   => (int) $r->recipients,
                'read_count'   => (int) $r->read_count,
            ])->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    // GET /financial/messages/broadcasts/{broadcastId}
    public function broadcastShow(string $broadcastId)
    {
        Gate::authorize('financial.communicate');

        $rows = Message::financial()->where('broadcast_id', $broadcastId)->with('investor:id,name,email')->get();
        abort_if($rows->isEmpty(), 404);

        $first = $rows->first();
        return response()->json([
            'broadcast' => [
                'broadcast_id' => $broadcastId,
                'subject'      => $first->subject,
                'body'         => $first->body,
                'kind'         => $first->kind,
                'sender_label' => $first->displaySender(),
                'issued_by'    => User::find($first->sender_id)?->name ?? 'Unknown',
                'sent_at'      => $first->created_at->toIso8601String(),
                'recipients'   => $rows->count(),
                'read_count'   => $rows->where('read_by_investor', true)->count(),
            ],
            'recipients' => $rows->take(1000)->map(fn ($m) => [
                'id'     => $m->investor_id,
                'name'   => $m->investor->name ?? 'Unknown',
                'email'  => $m->investor->email ?? '',
                'status' => $m->read_by_investor ? 'read' : 'unread',
            ])->values(),
        ]);
    }

    // ── helpers ────────────────────────────────────────────────────────

    protected function ensureInvestor(User $u): void
    {
        abort_unless($u->role === 'investor', 404);
    }

    protected function notification(string $kind, ?string $subject, ?int $messageId): FinancialAlertNotification
    {
        $isNotice = $kind === 'notice';
        return new FinancialAlertNotification(
            $isNotice ? 'Financial Team Notice' : 'New message from the Financial Team',
            $subject ?: ($isNotice ? 'You have a new official notice from the Financial Team.' : 'You have a new message from the Financial Team.'),
            $isNotice ? 'financial_notice' : 'financial_message',
            array_filter(['message_id' => $messageId, 'department' => 'financial'])
        );
    }

    protected function format(Message $m, User $investor): array
    {
        $fromInvestor = $m->initiated_by === 'investor';
        return [
            'id'           => $m->id,
            'from'         => $fromInvestor ? 'investor' : 'financial',
            'sender'       => $fromInvestor ? ($investor->name ?? 'Investor') : $m->displaySender(),
            'sent_by'      => $fromInvestor ? null : ($m->sender->name ?? null), // staff member behind the department identity
            'recipient'    => $fromInvestor ? Message::FINANCIAL_SENDER_LABEL : ($investor->name ?? 'Investor'),
            'subject'      => $m->subject,
            'body'         => $m->body,
            'kind'         => $m->kind,
            'department'   => $m->department,
            'broadcast_id' => $m->broadcast_id,
            'status'       => $fromInvestor
                ? ($m->read_by_financial ? 'read' : 'unread')
                : ($m->read_by_investor ? 'read' : 'delivered'),
            'created_at'   => $m->created_at->toIso8601String(),
        ];
    }
}
