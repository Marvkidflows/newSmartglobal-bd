<?php
// TASK 3 — campaigns (create, edit, audience preview, schedule, send, cancel) and templates, per environment.
namespace App\Http\Controllers\Mailing;

use App\Http\Controllers\Controller;
use App\Models\Mailing\MailCampaign;
use App\Models\Mailing\MailTemplate;
use App\Services\Mailing\AudienceResolver;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\MailingAudit;
use App\Services\Mailing\MailingPermissions;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    use Concerns;

    public function __construct(protected CampaignService $service, protected AudienceResolver $audiences) {}

    // GET /api/mailing/{environment}/audiences
    public function audiences(string $environment)
    {
        return response()->json(['audiences' => AudienceResolver::AUDIENCES[$environment], 'merge_tags' => CampaignService::MERGE_TAGS[$environment]]);
    }

    // GET /api/mailing/{environment}/campaigns
    public function index(Request $r, string $environment)
    {
        $q = MailCampaign::where('environment', $environment)->with('creator:id,name');
        if ($r->filled('status')) $q->where('status', $r->string('status'));
        if ($r->filled('q')) $q->where('name', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string) $r->input('q'), 0, 100)) . '%');
        return response()->json($q->latest('id')->paginate(min(100, (int) $r->integer('per_page', 25)))->through(fn ($c) => $this->row($c)));
    }

    public function show(string $environment, int $campaign)
    {
        $campaign = MailCampaign::findOrFail($campaign);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($campaign->environment === $environment, 404);
        return response()->json(['campaign' => $this->row($campaign) + ['body_html' => $campaign->body_html, 'audience_filters' => $campaign->audience_filters]]);
    }

    // POST /api/mailing/{environment}/campaigns
    public function store(Request $r, string $environment)
    {
        $d = $this->validated($r);
        $this->authoriseKind($r, $environment, $d['kind']);
        $c = $this->service->create($r->user(), $environment, $d, $r);
        return response()->json(['success' => true, 'campaign' => $this->row($c)], 201);
    }

    public function update(Request $r, string $environment, int $campaign)
    {
        $campaign = MailCampaign::findOrFail($campaign);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($campaign->environment === $environment, 404);
        $d = $this->validated($r);
        $this->authoriseKind($r, $environment, $d['kind']);
        return response()->json(['success' => true, 'campaign' => $this->row($this->service->update($r->user(), $campaign, $d, $r))]);
    }

    public function destroy(Request $r, string $environment, int $campaign)
    {
        $campaign = MailCampaign::findOrFail($campaign);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($campaign->environment === $environment, 404);
        if ($campaign->status !== 'draft') return response()->json(['success' => false, 'message' => 'Only drafts can be deleted.'], 409);
        MailingAudit::record($r->user(), 'campaign.deleted', $environment, $campaign, ['name' => $campaign->name], $r);
        $campaign->delete();
        return response()->json(['success' => true]);
    }

    // GET /api/mailing/{environment}/campaigns/{campaign}/audience-preview — a COUNT only, never addresses
    public function preview(Request $r, string $environment, int $campaign)
    {
        $campaign = MailCampaign::findOrFail($campaign);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($campaign->environment === $environment, 404);
        return response()->json(['recipient_count' => $this->audiences->count($campaign)]);
    }

    // POST /api/mailing/{environment}/campaigns/{campaign}/schedule  { scheduled_at }
    public function schedule(Request $r, string $environment, int $campaign)
    {
        $campaign = MailCampaign::findOrFail($campaign);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($campaign->environment === $environment, 404);
        $this->authoriseKind($r, $environment, $campaign->kind);
        $d = $r->validate(['scheduled_at' => ['required', 'date']]);
        return response()->json(['success' => true, 'campaign' => $this->row($this->service->schedule($r->user(), $campaign, Carbon::parse($d['scheduled_at']), $r))]);
    }

    // POST /api/mailing/{environment}/campaigns/{campaign}/send
    public function send(Request $r, string $environment, int $campaign)
    {
        $campaign = MailCampaign::findOrFail($campaign);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($campaign->environment === $environment, 404);
        $this->authoriseKind($r, $environment, $campaign->kind);
        if (!$this->service->sendNow($r->user(), $campaign, $r)) {
            return response()->json(['success' => false, 'message' => 'This campaign has already been started, finished or cancelled.'], 409);
        }
        return response()->json(['success' => true, 'campaign' => $this->row($campaign->refresh())], 202);
    }

    public function cancel(Request $r, string $environment, int $campaign)
    {
        $campaign = MailCampaign::findOrFail($campaign);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($campaign->environment === $environment, 404);
        if (!$this->service->cancel($r->user(), $campaign, $r)) return response()->json(['success' => false, 'message' => 'This campaign can no longer be cancelled.'], 409);
        return response()->json(['success' => true]);
    }

    // ── Templates ───────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function templates(Request $r, string $environment)
    {
        return response()->json(['templates' => MailTemplate::where('environment', $environment)->orderBy('name')->get(['id', 'name', 'subject', 'body_html', 'is_active', 'updated_at'])]);
    }

    public function templateStore(Request $r, string $environment)
    {
        $d = $this->templateData($r, $environment);
        $t = MailTemplate::create($d + ['environment' => $environment, 'created_by' => $r->user()->id, 'updated_by' => $r->user()->id]);
        MailingAudit::record($r->user(), 'template.created', $environment, $t, ['name' => $t->name], $r);
        return response()->json(['success' => true, 'template' => $t], 201);
    }

    public function templateUpdate(Request $r, string $environment, int $template)
    {
        $template = MailTemplate::findOrFail($template);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($template->environment === $environment, 404);
        $template->update($this->templateData($r, $environment) + ['updated_by' => $r->user()->id]);
        MailingAudit::record($r->user(), 'template.updated', $environment, $template, ['name' => $template->name], $r);
        return response()->json(['success' => true, 'template' => $template->refresh()]);
    }

    public function templateDestroy(Request $r, string $environment, int $template)
    {
        $template = MailTemplate::findOrFail($template);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless($template->environment === $environment, 404);
        MailingAudit::record($r->user(), 'template.deleted', $environment, $template, ['name' => $template->name], $r);
        $template->delete();
        return response()->json(['success' => true]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    protected function validated(Request $r): array
    {
        return $r->validate([
            'name' => ['required', 'string', 'max:150', $this->noInjection()],
            'subject' => ['required', 'string', 'max:190', $this->noInjection()],
            'body_html' => ['required', 'string', 'max:200000'],
            'kind' => ['required', 'in:newsletter,announcement,essential'],
            'audience_type' => ['required', 'string', 'max:40'],
            'audience_filters' => ['nullable', 'array'],
            'audience_filters.rank_ids' => ['nullable', 'array', 'max:50'],
            'audience_filters.rank_ids.*' => ['integer'],
            'template_id' => ['nullable', 'integer', 'exists:mail_templates,id'],
            'department_id' => ['nullable', 'integer', 'exists:mail_departments,id'],
            'client_token' => ['nullable', 'string', 'max:64'],
        ]);
    }

    /** Essential notices bypass marketing consent, so they need their own, narrower permission. */
    protected function authoriseKind(Request $r, string $environment, string $kind): void
    {
        if ($kind === 'essential' && $environment !== 'investor') {
            throw \Illuminate\Validation\ValidationException::withMessages(['kind' => ['Essential notices are not available in public mailing.']]);
        }
        if ($kind === 'essential') {
            abort_unless($environment === 'investor' && MailingPermissions::can($r->user(), 'investor.send_essential'), 403, 'Essential notices require the investor.send_essential permission.');
        }
    }

    protected function templateData(Request $r, string $environment): array
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:120', $this->noInjection()],
            'subject' => ['required', 'string', 'max:190', $this->noInjection()],
            'body_html' => ['required', 'string', 'max:200000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $clean = $this->service->cleanContent($environment, $d['subject'], $d['body_html']);
        return ['name' => $d['name'], 'subject' => $clean['subject'], 'body_html' => $clean['body_html'], 'is_active' => $d['is_active'] ?? true];
    }

    protected function row(MailCampaign $c): array
    {
        return [
            'id' => $c->id, 'environment' => $c->environment, 'name' => $c->name, 'subject' => $c->subject, 'kind' => $c->kind, 'purpose' => $c->purpose,
            'audience_type' => $c->audience_type, 'status' => $c->status, 'scheduled_at' => $c->scheduled_at, 'started_at' => $c->started_at,
            'completed_at' => $c->completed_at, 'recipient_count' => $c->recipient_count, 'sent_count' => $c->sent_count,
            'failed_count' => $c->failed_count, 'skipped_count' => $c->skipped_count, 'created_by' => $c->creator?->name, 'created_at' => $c->created_at,
        ];
    }
}
