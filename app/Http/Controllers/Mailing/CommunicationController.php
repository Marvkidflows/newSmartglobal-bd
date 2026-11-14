<?php
// TASK 3 — dashboards, communication history, failed-delivery monitoring, retry and assignment.
namespace App\Http\Controllers\Mailing;

use App\Http\Controllers\Controller;
use App\Jobs\SendMailingMessageJob;
use App\Models\Mailing\MailCampaign;
use App\Models\Mailing\MailConsent;
use App\Models\Mailing\MailMailbox;
use App\Models\Mailing\MailMessage;
use App\Models\Mailing\MailSubscription;
use App\Models\User;
use App\Services\Mailing\ConsentService;
use App\Services\Mailing\InvestorEligibility;
use App\Services\Mailing\MailingAudit;
use App\Services\Mailing\MailingPermissions;
use App\Services\Mailing\OutboundService;
use Illuminate\Http\Request;

class CommunicationController extends Controller
{
    use Concerns;

    // GET /api/mailing/{environment}/dashboard
    public function dashboard(Request $r, string $environment)
    {
        $since = now()->subDays(30);
        $msgs = MailMessage::where('environment', $environment)->where('direction', 'outbound')->where('created_at', '>=', $since);
        $byStatus = (clone $msgs)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $purposes = ConsentService::purposesFor($environment);

        $subs = [];
        foreach ($purposes as $p) {
            $subs[$p] = [
                'granted'   => MailSubscription::where('purpose', $p)->where('status', 'granted')->count(),
                'pending'   => MailSubscription::where('purpose', $p)->where('status', 'pending')->count(),
                'withdrawn' => MailSubscription::where('purpose', $p)->where('status', 'withdrawn')->count(),
            ];
        }

        $data = [
            'environment' => $environment,
            'window_days' => 30,
            'delivery' => [
                'queued' => (int) ($byStatus['queued'] ?? 0) + (int) ($byStatus['sending'] ?? 0),
                'sent' => (int) ($byStatus['sent'] ?? 0), 'delivered' => (int) ($byStatus['delivered'] ?? 0),
                'failed' => (int) ($byStatus['failed'] ?? 0), 'bounced' => (int) ($byStatus['bounced'] ?? 0), 'skipped' => (int) ($byStatus['skipped'] ?? 0),
            ],
            'campaigns' => MailCampaign::where('environment', $environment)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status'),
            'subscriptions' => $subs,
            'unsubscribes_30d' => MailConsent::whereIn('purpose', $purposes)->where('action', 'withdrawn')->where('created_at', '>=', $since)->count(),
            'inbound_open' => MailMessage::where('environment', $environment)->where('direction', 'inbound')->whereNull('assigned_to')->count(),
            'mailboxes' => [
                'total' => MailMailbox::whereHas('department', fn ($q) => $q->whereIn('environment', [$environment, 'both']))->count(),
                'connected' => MailMailbox::where('connection_status', 'connected')->whereHas('department', fn ($q) => $q->whereIn('environment', [$environment, 'both']))->count(),
            ],
            'recent_failures' => MailMessage::where('environment', $environment)->whereIn('status', ['failed', 'bounced'])->latest('id')->limit(5)
                ->get(['id', 'subject', 'status', 'error_code', 'failed_at'])->map(fn ($m) => $m->only(['id', 'subject', 'status', 'error_code', 'failed_at'])),
        ];
        if ($environment === 'investor') {
            $data['eligible_investors'] = InvestorEligibility::scope(User::query())->count();   // a count only — never the list
        }
        return response()->json($data);
    }

    // GET /api/mailing/{environment}/messages?status=&direction=&kind=&department_id=&q=&from=&to=
    public function index(Request $r, string $environment)
    {
        $q = MailMessage::with(['department:id,name', 'assignee:id,name', 'sender:id,name'])->where('environment', $environment);
        $this->filters($q, $r);
        return response()->json($q->latest('id')->paginate(min(100, (int) $r->integer('per_page', 25)))->through(fn ($m) => $this->messageRow($m)));
    }

    // GET /api/mailing/{environment}/failures
    public function failures(Request $r, string $environment)
    {
        $q = MailMessage::with(['department:id,name'])->where('environment', $environment)->whereIn('status', ['failed', 'bounced']);
        $this->filters($q, $r, statusFilter: false);
        return response()->json($q->latest('id')->paginate(min(100, (int) $r->integer('per_page', 25)))->through(fn ($m) => $this->messageRow($m)));
    }

    // GET /api/mailing/my/messages — correspondence of the departments this staff member belongs to (any environment)
    public function mine(Request $r)
    {
        $user = $r->user();
        $q = MailMessage::with(['department:id,name', 'assignee:id,name', 'sender:id,name'])->latest('id');
        if ($user->role !== 'admin') {
            $q->whereIn('department_id', MailingPermissions::departmentIds($user));
            if (in_array($user->role, MailingPermissions::REGISTRATION_ROLES, true)) $q->where('environment', 'public');   // applicants only; never investor mail
        }
        $this->filters($q, $r);
        return response()->json($q->paginate(min(100, (int) $r->integer('per_page', 25)))->through(fn ($m) => $this->messageRow($m)));
    }

    // GET /api/mailing/messages/{message} — opening a message body is itself an audited staff activity
    public function show(Request $r, int $message)
    {
        $message = MailMessage::findOrFail($message);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($this->canSeeMessage($r->user(), $message), 403);
        $message->load(['department:id,name', 'assignee:id,name', 'sender:id,name']);
        MailingAudit::record($r->user(), 'message.viewed', $message->environment, $message, ['direction' => $message->direction], $r);
        return response()->json(['message' => $this->messageRow($message, withBody: true)]);
    }

    // POST /api/mailing/{environment}/messages/{message}/retry
    public function retry(Request $r, string $environment, int $message)
    {
        $message = MailMessage::findOrFail($message);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($message->environment === $environment, 404);
        $n = MailMessage::whereKey($message->id)->where('direction', 'outbound')->where('status', 'failed')->update([
            'status' => 'queued', 'error_code' => null, 'error_message' => null, 'failed_at' => null, 'updated_at' => now(),
        ]);
        if ($n !== 1) return response()->json(['success' => false, 'message' => 'Only failed outbound messages can be retried.'], 409);

        MailingAudit::record($r->user(), 'message.retried', $environment, $message, [], $r);
        SendMailingMessageJob::dispatch($message->id);     // MessageSender's atomic claim guarantees at most one send
        return response()->json(['success' => true]);
    }

    // POST /api/mailing/{environment}/messages/{message}/assign   { assigned_to }
    public function assign(Request $r, string $environment, int $message)
    {
        $message = MailMessage::findOrFail($message);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($message->environment === $environment, 404);
        $d = $r->validate(['assigned_to' => ['nullable', 'integer', 'exists:users,id']]);
        if ($d['assigned_to']) {
            $assignee = User::find($d['assigned_to']);
            abort_unless(MailingPermissions::isStaff($assignee) && ($assignee->role === 'admin' || MailingPermissions::inDepartment($assignee, $message->department_id) || MailingPermissions::can($assignee, "$environment.view")), 422, 'That staff member has no access to this department.');
        }
        $message->update(['assigned_to' => $d['assigned_to']]);
        MailingAudit::record($r->user(), 'message.assigned', $environment, $message, ['assigned_to' => $d['assigned_to']], $r);
        return response()->json(['success' => true]);
    }

    // POST /api/mailing/{environment}/messages  — one-to-one staff correspondence / reply
    public function compose(Request $r, string $environment, OutboundService $outbound)
    {
        $d = $r->validate([
            'subject' => ['required', 'string', 'max:190', $this->noInjection()],
            'body_html' => ['required', 'string', 'max:200000'],
            'investor_id' => ['nullable', 'integer', 'exists:users,id'],
            'to_email' => ['nullable', 'email', 'max:190', $this->noInjection()],
            'reply_to_message_id' => ['nullable', 'integer', 'exists:mail_messages,id'],
            'department_id' => ['nullable', 'integer', 'exists:mail_departments,id'],
            'kind' => ['nullable', 'in:support,transactional'],
            'client_token' => ['nullable', 'string', 'max:64'],
        ]);
        $m = $outbound->compose($r->user(), $environment, $d, $r);
        return response()->json(['success' => true, 'message' => $this->messageRow($m)], 201);
    }

    protected function filters($q, Request $r, bool $statusFilter = true): void
    {
        if ($statusFilter && $r->filled('status')) $q->where('status', $r->string('status'));
        if ($r->filled('direction')) $q->where('direction', $r->string('direction'));
        if ($r->filled('kind')) $q->where('kind', $r->string('kind'));
        if ($r->filled('department_id')) $q->where('department_id', (int) $r->input('department_id'));
        if ($r->filled('campaign_id')) $q->where('campaign_id', (int) $r->input('campaign_id'));
        if ($r->filled('from')) $q->whereDate('created_at', '>=', $r->date('from'));
        if ($r->filled('to')) $q->whereDate('created_at', '<=', $r->date('to'));
        if ($r->filled('q')) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr(trim((string) $r->input('q')), 0, 100)) . '%';
            $q->where(fn ($w) => $w->where('subject', 'like', $term)->orWhere('recipient_email', 'like', $term)->orWhere('from_address', 'like', $term));
        }
    }
}
