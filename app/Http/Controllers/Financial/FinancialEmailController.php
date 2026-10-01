<?php
// LOCATION: app/Http/Controllers/Financial/FinancialEmailController.php
//
// Financial Team access to the EXISTING Email Center. This is NOT a second
// email system: it extends AdminEmailController, so compose / bulk send /
// templates / logs, the Brevo mailer, the SendBulkEmailJob queue path, the
// sent_emails table and the investor's Email History are all the same code
// and data the admin Email Center uses.
//
// What this subclass changes (and why it is safe to hand to the Financial role):
//
//   * Scope     — every read is limited to department = 'financial', so the
//                 Financial Team never sees (or edits) the Admin team's logs
//                 or templates. Shared/general templates (department NULL)
//                 are readable but not editable.
//   * Identity  — every email is stamped department = 'financial' and
//                 sender_label = "Smart System Investment — Financial Team",
//                 and the email itself is branded the same way.
//   * Targets   — recipients must be role = investor and not deactivated
//                 (the admin version accepts any user id).
//   * Access    — single emails need `financial.communicate`; multi-recipient
//                 sends need `financial.issue-notices`.
//   * Audit     — each send writes a financial_audit_logs row (who, when,
//                 subject, recipient count) via FinancialAuditService.
//   * Privacy   — server file paths are never returned to the browser, and
//                 body HTML is stripped of scripts / event handlers.

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Admin\AdminEmailController;
use App\Mail\AdminCustomEmail;
use App\Models\EmailTemplate;
use App\Models\InvestmentPlan;
use App\Models\Message;
use App\Models\SentEmail;
use App\Models\User;
use App\Notifications\EmailReceivedNotification;
use App\Jobs\SendBulkEmailJob;
use App\Services\FinancialAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FinancialEmailController extends AdminEmailController
{
    protected const DEPARTMENT = 'financial';

    public function __construct(protected FinancialAuditService $audit) {}

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    // GET /financial/email-center/dashboard
    public function dashboard(Request $request)
    {
        Gate::authorize('financial.communicate');

        $base = fn () => SentEmail::where('department', self::DEPARTMENT);

        $recent = $base()->with('investor:id,name,full_name,email')
            ->latest()->take(8)->get()
            ->map(fn ($e) => [
                'id'              => $e->id,
                'recipient_name'  => $e->recipient_name ?? $e->investor?->name,
                'recipient_email' => $e->recipient_email,
                'subject'         => $e->subject,
                'status'          => $e->status,
                'sent_at'         => optional($e->sent_at)->diffForHumans(),
            ]);

        return response()->json([
            'stats' => [
                'total_sent'   => $base()->where('status', 'sent')->count(),
                'total_failed' => $base()->where('status', 'failed')->count(),
                'sent_today'   => $base()->where('status', 'sent')->whereDate('sent_at', today())->count(),
                'templates'    => $this->visibleTemplates()->count(),
            ],
            'recent' => $recent,
        ]);
    }

    // =========================================================================
    // LOOKUPS
    // =========================================================================

    // GET /financial/email-center/investors/search?q=
    public function searchInvestors(Request $request)
    {
        Gate::authorize('financial.communicate');

        $q = trim((string) $request->get('q'));

        $query = User::where('role', 'investor')
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'deactivated'));

        if ($q !== '') {
            $query->where(fn ($w) => $w->where('name', 'like', "%$q%")
                ->orWhere('full_name', 'like', "%$q%")
                ->orWhere('email', 'like', "%$q%"));
        }

        return response()->json([
            'investors' => $query->orderBy('name')->take(20)->get()->map(fn ($u) => [
                'id'     => $u->id,
                'name'   => $u->name ?? $u->full_name,
                'email'  => $u->email,
                'status' => $u->status ?? 'active',
            ]),
        ]);
    }

    // GET /financial/email-center/countries
    public function countries()
    {
        Gate::authorize('financial.communicate');
        return parent::countries();
    }

    // GET /financial/email-center/plans — plan dropdown for the Bulk filter
    // (the admin investment-plans route is not available to this role).
    public function plans()
    {
        Gate::authorize('financial.communicate');
        return response()->json([
            'plans' => InvestmentPlan::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // =========================================================================
    // COMPOSE — single recipient
    // =========================================================================

    // POST /financial/email-center/send
    public function send(Request $request)
    {
        Gate::authorize('financial.communicate');

        $validated = $request->validate([
            'investor_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'investor')],
            'subject'     => ['required', 'string', 'max:255'],
            'body_html'   => ['required', 'string'],
            'attachment'  => ['nullable', 'file', 'max:10240'],
        ]);

        $investor = User::where('id', $validated['investor_id'])->where('role', 'investor')->firstOrFail();

        if ($investor->status === 'deactivated') {
            return response()->json(['success' => false, 'message' => 'This investor account is deactivated and cannot receive email.'], 422);
        }

        $result = $this->dispatchEmail($request, $investor, $validated['subject'], $this->cleanHtml($validated['body_html']));

        return response()->json($result);
    }

    // POST /financial/email-center/send-test — to the sender's own address; not logged.
    public function sendTest(Request $request)
    {
        Gate::authorize('financial.communicate');

        $validated = $request->validate([
            'subject'   => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
        ]);

        try {
            Mail::mailer('brevo')
                ->to($request->user()->email)
                ->send(new AdminCustomEmail('[TEST] ' . $validated['subject'], $this->cleanHtml($validated['body_html']), null, null, self::DEPARTMENT));

            return response()->json(['success' => true, 'message' => 'Test email sent to ' . $request->user()->email]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to send test email.'], 500);
        }
    }

    /**
     * Overrides the parent's shared send path: same flow (record → Brevo →
     * status → investor bell notification) plus department identity + audit.
     */
    protected function dispatchEmail(Request $request, User $investor, string $subject, string $bodyHtml, ?string $batchId = null): array
    {
        $actor = $request->user();

        $attachmentPath = null;
        $attachmentName = null;

        if ($request->hasFile('attachment')) {
            $file           = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $storedPath     = $file->store('email-attachments', 'public');
            $attachmentPath = Storage::disk('public')->path($storedPath);
        }

        $sentEmail = SentEmail::create([
            'batch_id'        => $batchId,
            'admin_id'        => $actor->id,
            'department'      => self::DEPARTMENT,
            'sender_label'    => Message::FINANCIAL_SENDER_LABEL,
            'investor_id'     => $investor->id,
            'recipient_name'  => $investor->name ?? $investor->full_name,
            'recipient_email' => $investor->email,
            'subject'         => $subject,
            'body_html'       => $bodyHtml,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'status'          => 'queued',
        ]);

        try {
            Mail::mailer('brevo')
                ->to($investor->email)
                ->send(new AdminCustomEmail($subject, $bodyHtml, $attachmentPath, $attachmentName, self::DEPARTMENT));

            $sentEmail->update(['status' => 'sent', 'sent_at' => now()]);
            $investor->notify(new EmailReceivedNotification($sentEmail->id, $subject));

            $this->audit->record($actor, 'communication.email_sent', 'sent_email', $sentEmail->id, $investor->id,
                null, ['subject' => $subject, 'recipient_count' => 1, 'status' => 'sent'], null);

            return ['success' => true, 'message' => 'Email sent to ' . $investor->email, 'data' => $this->summary($sentEmail)];
        } catch (\Throwable $e) {
            report($e);
            $sentEmail->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            $this->audit->record($actor, 'communication.email_failed', 'sent_email', $sentEmail->id, $investor->id,
                null, ['subject' => $subject, 'recipient_count' => 1, 'status' => 'failed'], null);

            return ['success' => false, 'message' => 'Failed to send email. It has been logged as failed.', 'data' => $this->summary($sentEmail)];
        }
    }

    // =========================================================================
    // BULK — official communication to many investors
    // =========================================================================

    // GET /financial/email-center/bulk/count
    public function bulkCount(Request $request)
    {
        Gate::authorize('financial.issue-notices');
        return parent::bulkCount($request);
    }

    // POST /financial/email-center/bulk/send
    public function bulkSend(Request $request)
    {
        Gate::authorize('financial.issue-notices');

        $validated = $request->validate([
            'subject'   => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'filter'    => ['required', 'in:all,active,suspended,frozen,pending_kyc,verified,country,plan'],
            'country'   => ['nullable', 'string', 'required_if:filter,country'],
            'plan_id'   => ['nullable', 'exists:investment_plans,id', 'required_if:filter,plan'],
        ]);

        $recipients = $this->resolveBulkQuery($request)->get();

        if ($recipients->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No investors match this filter.'], 422);
        }

        if (config('queue.default') === 'sync' && $recipients->count() > $this->syncBulkLimit) {
            return response()->json([
                'success' => false,
                'message' => "This filter matches {$recipients->count()} investors, which exceeds the safe synchronous limit ({$this->syncBulkLimit}). Please enable a real queue (QUEUE_CONNECTION=database) to send larger batches, or narrow your filter.",
            ], 422);
        }

        $actor   = $request->user();
        $batchId = (string) Str::uuid();
        $html    = $this->cleanHtml($validated['body_html']);
        $firstId = null;

        foreach ($recipients as $investor) {
            $queued  = $this->queueEmail($investor, $validated['subject'], $html, $batchId, $actor->id);
            $firstId = $firstId ?? $queued->id;
        }

        $this->audit->record($actor, 'communication.bulk_email_issued', 'sent_email', (int) $firstId, null, null, [
            'batch_id'        => $batchId,
            'subject'         => $validated['subject'],
            'filter'          => $validated['filter'],
            'recipient_count' => $recipients->count(),
        ], null);

        return response()->json([
            'success'  => true,
            'message'  => "{$recipients->count()} email(s) queued for sending. Check Sent Emails / Logs shortly for delivery status.",
            'batch_id' => $batchId,
            'queued'   => $recipients->count(),
        ]);
    }

    protected function queueEmail(User $investor, string $subject, string $bodyHtml, string $batchId, int $adminId): SentEmail
    {
        $sentEmail = SentEmail::create([
            'batch_id'        => $batchId,
            'admin_id'        => $adminId,
            'department'      => self::DEPARTMENT,
            'sender_label'    => Message::FINANCIAL_SENDER_LABEL,
            'investor_id'     => $investor->id,
            'recipient_name'  => $investor->name ?? $investor->full_name,
            'recipient_email' => $investor->email,
            'subject'         => $subject,
            'body_html'       => $bodyHtml,
            'status'          => 'queued',
        ]);

        SendBulkEmailJob::dispatch($sentEmail->id);

        return $sentEmail;
    }

    // Deactivated accounts are never emailed (also keeps the count preview honest).
    protected function resolveBulkQuery(Request $request)
    {
        return parent::resolveBulkQuery($request)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'deactivated'));
    }

    // =========================================================================
    // TEMPLATES — own department's are editable; shared (NULL) are read-only
    // =========================================================================

    protected function visibleTemplates()
    {
        return EmailTemplate::where(fn ($q) => $q->where('department', self::DEPARTMENT)->orWhereNull('department'));
    }

    // GET /financial/email-center/templates
    public function templatesIndex()
    {
        Gate::authorize('financial.communicate');

        return response()->json([
            'templates' => $this->visibleTemplates()->latest()->get()->map(fn ($t) => [
                'id'        => $t->id,
                'name'      => $t->name,
                'category'  => $t->category,
                'subject'   => $t->subject,
                'body_html' => $t->body_html,
                'shared'    => $t->department === null,
                'editable'  => $t->department === self::DEPARTMENT,
                'updated_at'=> optional($t->updated_at)->toIso8601String(),
            ]),
        ]);
    }

    // POST /financial/email-center/templates
    public function templatesStore(Request $request)
    {
        Gate::authorize('financial.communicate');

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'category'  => ['nullable', 'string', 'max:100'],
            'subject'   => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
        ]);

        $validated['body_html']  = $this->cleanHtml($validated['body_html']);
        $validated['department'] = self::DEPARTMENT;
        $validated['created_by'] = $request->user()->id;

        return response()->json(['message' => 'Template created.', 'template' => EmailTemplate::create($validated)], 201);
    }

    // PUT /financial/email-center/templates/{template}
    public function templatesUpdate(Request $request, EmailTemplate $template)
    {
        Gate::authorize('financial.communicate');
        $this->ensureOwnTemplate($template);

        $validated = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'category'  => ['nullable', 'string', 'max:100'],
            'subject'   => ['sometimes', 'string', 'max:255'],
            'body_html' => ['sometimes', 'string'],
        ]);
        if (isset($validated['body_html'])) {
            $validated['body_html'] = $this->cleanHtml($validated['body_html']);
        }

        $template->update($validated);

        return response()->json(['message' => 'Template updated.', 'template' => $template]);
    }

    // DELETE /financial/email-center/templates/{template}
    public function templatesDestroy(EmailTemplate $template)
    {
        Gate::authorize('financial.communicate');
        $this->ensureOwnTemplate($template);

        $template->delete();
        return response()->json(['message' => 'Template deleted.']);
    }

    protected function ensureOwnTemplate(EmailTemplate $template): void
    {
        // 404, not 403 — never confirm that other teams' templates exist.
        abort_unless($template->department === self::DEPARTMENT, 404);
    }

    // =========================================================================
    // LOGS — correspondence history (Financial Team emails only)
    // =========================================================================

    // GET /financial/email-center/logs
    public function logs(Request $request)
    {
        Gate::authorize('financial.communicate');

        $query = SentEmail::where('department', self::DEPARTMENT)
            ->with(['admin:id,name', 'investor:id,name,full_name,email'])
            ->latest();

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($w) => $w->where('recipient_email', 'like', "%$s%")
                ->orWhere('subject', 'like', "%$s%")
                ->orWhere('recipient_name', 'like', "%$s%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $logs = $query->paginate(min((int) $request->get('per_page', 20), 100));
        $logs->getCollection()->transform(fn ($e) => $e->makeHidden(['attachment_path', 'body_html', 'error_message']));

        return response()->json($logs);
    }

    // GET /financial/email-center/logs/{sentEmail}
    public function logsShow(SentEmail $sentEmail)
    {
        Gate::authorize('financial.communicate');
        abort_unless($sentEmail->department === self::DEPARTMENT, 404);

        $sentEmail->load(['admin:id,name', 'investor:id,name,full_name,email']);
        return response()->json(['email' => $sentEmail->makeHidden(['attachment_path'])]);
    }

    // ── helpers ────────────────────────────────────────────────────────

    protected function summary(SentEmail $e): array
    {
        return [
            'id'              => $e->id,
            'status'          => $e->status,
            'subject'         => $e->subject,
            'recipient_email' => $e->recipient_email,
            'sent_at'         => optional($e->sent_at)->toIso8601String(),
        ];
    }

    /**
     * Defence-in-depth for HTML that is later rendered on the investor
     * dashboard: keep basic formatting, drop scripts / frames / inline event
     * handlers / javascript: URLs. (The Quill editor only ever produces the
     * allowed tags.)
     */
    protected function cleanHtml(string $html): string
    {
        $allowed = '<p><br><strong><b><em><i><u><s><ul><ol><li><a><h1><h2><h3><h4><span><div><blockquote><img><hr><table><thead><tbody><tr><th><td>';
        $html    = strip_tags($html, $allowed);
        $html    = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html    = preg_replace('/(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2/i', '$1="#"', $html);

        return $html;
    }
}
