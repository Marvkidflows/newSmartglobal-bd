<?php
// LOCATION: app/Http/Controllers/Admin/AdminEmailController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBulkEmailJob;
use App\Mail\AdminCustomEmail;
use App\Models\EmailTemplate;
use App\Models\SentEmail;
use App\Models\User;
use App\Notifications\EmailReceivedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminEmailController extends Controller
{
    /**
     * A hard safety cap for synchronous bulk sends.
     * If QUEUE_CONNECTION=sync, sending to more than this many recipients
     * in one request risks a PHP execution timeout — same class of bug
     * we already hit with the messages inbox N+1 query.
     */
    protected int $syncBulkLimit = 25;

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    // GET /admin/email-center/dashboard
    public function dashboard(Request $request)
    {
        $totalSent   = SentEmail::where('status', 'sent')->count();
        $totalFailed = SentEmail::where('status', 'failed')->count();
        $sentToday   = SentEmail::where('status', 'sent')->whereDate('sent_at', today())->count();
        $templates   = EmailTemplate::count();

        $recent = SentEmail::with('investor:id,name,full_name,email')
            ->latest()
            ->take(8)
            ->get()
            ->map(fn($e) => [
                'id'              => $e->id,
                'recipient_name'  => $e->recipient_name ?? $e->investor?->name,
                'recipient_email' => $e->recipient_email,
                'subject'         => $e->subject,
                'status'          => $e->status,
                'sent_at'         => optional($e->sent_at)->diffForHumans(),
            ]);

        return response()->json([
            'stats' => [
                'total_sent'   => $totalSent,
                'total_failed' => $totalFailed,
                'sent_today'   => $sentToday,
                'templates'    => $templates,
            ],
            'recent' => $recent,
        ]);
    }

    // =========================================================================
    // INVESTOR SEARCH (for Compose recipient picker)
    // =========================================================================

    // GET /admin/email-center/investors/search?q=
    public function searchInvestors(Request $request)
    {
        $q = trim((string) $request->get('q'));

        $query = User::where('role', 'investor');

        if ($q !== '') {
            $query->where(fn($w) => $w->where('name', 'like', "%$q%")
                ->orWhere('full_name', 'like', "%$q%")
                ->orWhere('email', 'like', "%$q%"));
        }

        $investors = $query->orderBy('name')->take(20)->get()
            ->map(fn($u) => [
                'id'     => $u->id,
                'name'   => $u->name ?? $u->full_name,
                'email'  => $u->email,
                'status' => $u->status ?? 'active',
            ]);

        return response()->json(['investors' => $investors]);
    }

    // =========================================================================
    // COMPOSE — single recipient
    // =========================================================================

    // POST /admin/email-center/send
    public function send(Request $request)
    {
        $validated = $request->validate([
            'investor_id' => ['required', 'exists:users,id'],
            'subject'     => ['required', 'string', 'max:255'],
            'body_html'   => ['required', 'string'],
            'attachment'  => ['nullable', 'file', 'max:10240'], // 10MB
        ]);

        $investor = User::findOrFail($validated['investor_id']);

        return response()->json(
            $this->dispatchEmail($request, $investor, $validated['subject'], $validated['body_html'])
        );
    }

    // POST /admin/email-center/send-test
    // Sends the composed email to the logged-in admin's own address, does NOT save to history.
    public function sendTest(Request $request)
    {
        $validated = $request->validate([
            'subject'   => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
        ]);

        $admin = $request->user();

        try {
            Mail::mailer('brevo')
                ->to($admin->email)
                ->send(new AdminCustomEmail('[TEST] ' . $validated['subject'], $validated['body_html']));

            return response()->json(['success' => true, 'message' => 'Test email sent to ' . $admin->email]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to send test email: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Shared send logic used by both single Compose and each recipient of Bulk.
     */
    protected function dispatchEmail(Request $request, User $investor, string $subject, string $bodyHtml, ?string $batchId = null): array
    {
        $admin = $request->user();

        $attachmentPath = null;
        $attachmentName = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $storedPath = $file->store('email-attachments', 'public');
            $attachmentPath = Storage::disk('public')->path($storedPath);
        }

        $sentEmail = SentEmail::create([
            'batch_id'        => $batchId,
            'admin_id'        => $admin->id,
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
                ->send(new AdminCustomEmail($subject, $bodyHtml, $attachmentPath, $attachmentName));

            $sentEmail->update(['status' => 'sent', 'sent_at' => now()]);

            // In-app notification — mirrors AccountStatusNotification's database-only pattern
            $investor->notify(new EmailReceivedNotification($sentEmail->id, $subject));

            return ['success' => true, 'message' => 'Email sent to ' . $investor->email, 'data' => $sentEmail];
        } catch (\Throwable $e) {
            $sentEmail->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Failed to send: ' . $e->getMessage(), 'data' => $sentEmail];
        }
    }

    /**
     * Bulk-send path: creates the record and hands off to the real queue
     * (SendBulkEmailJob) instead of sending inline — this is what actually
     * makes "send to many investors at once" safe and non-blocking. The
     * job itself does the Brevo send + status update once a queue worker
     * picks it up.
     */
    protected function queueEmail(User $investor, string $subject, string $bodyHtml, string $batchId, int $adminId): SentEmail
    {
        $sentEmail = SentEmail::create([
            'batch_id'        => $batchId,
            'admin_id'        => $adminId,
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

    // =========================================================================
    // BULK EMAIL
    // =========================================================================

    // GET /admin/email-center/bulk/count — preview recipient count before sending
    public function bulkCount(Request $request)
    {
        return response()->json(['count' => $this->resolveBulkQuery($request)->count()]);
    }

    // POST /admin/email-center/bulk/send
    public function bulkSend(Request $request)
    {
        $validated = $request->validate([
            'subject'   => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'filter'    => ['required', 'in:all,active,suspended,frozen,pending_kyc,verified,country,plan'],
            'country'   => ['nullable', 'string'],
            'plan_id'   => ['nullable', 'exists:investment_plans,id'],
        ]);

        $recipients = $this->resolveBulkQuery($request)->get();

        if ($recipients->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No investors match this filter.'], 422);
        }

        // Guardrail: without a real queue, a large synchronous batch will timeout the request.
        if (config('queue.default') === 'sync' && $recipients->count() > $this->syncBulkLimit) {
            return response()->json([
                'success' => false,
                'message' => "This filter matches {$recipients->count()} investors, which exceeds the safe synchronous limit ({$this->syncBulkLimit}). Please enable a real queue (QUEUE_CONNECTION=database) to send larger batches, or narrow your filter.",
            ], 422);
        }

        $batchId = (string) Str::uuid();
        $admin = $request->user();

        foreach ($recipients as $investor) {
            $this->queueEmail($investor, $validated['subject'], $validated['body_html'], $batchId, $admin->id);
        }

        return response()->json([
            'success'  => true,
            'message'  => "{$recipients->count()} email(s) queued for sending. Check Email Logs shortly for delivery status.",
            'batch_id' => $batchId,
            'queued'   => $recipients->count(),
        ]);
    }

    protected function resolveBulkQuery(Request $request)
    {
        $query = User::where('role', 'investor');

        switch ($request->get('filter')) {
            case 'active':      $query->where('status', 'active'); break;
            case 'suspended':   $query->where('status', 'suspended'); break;
            case 'frozen':      $query->where('status', 'frozen'); break;
            case 'pending_kyc': $query->where('kyc_status', 'pending'); break;
            case 'verified':    $query->where('kyc_status', 'approved'); break;
            case 'country':     $query->where('country', $request->get('country')); break;
            case 'plan':
                $planId = $request->get('plan_id');
                $query->whereHas('investmentAccounts', fn($q) => $q->where('investment_plan_id', $planId));
                break;
            case 'all':
            default:
                break;
        }

        return $query;
    }

    // GET /admin/email-center/countries — distinct list for the Bulk filter dropdown
    public function countries()
    {
        $countries = User::where('role', 'investor')
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country');

        return response()->json(['countries' => $countries]);
    }

    // =========================================================================
    // TEMPLATES
    // =========================================================================

    // GET /admin/email-center/templates
    public function templatesIndex()
    {
        return response()->json(['templates' => EmailTemplate::latest()->get()]);
    }

    // POST /admin/email-center/templates
    public function templatesStore(Request $request)
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'category'  => ['nullable', 'string', 'max:100'],
            'subject'   => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
        ]);

        $validated['created_by'] = $request->user()->id;

        $template = EmailTemplate::create($validated);

        return response()->json(['message' => 'Template created.', 'template' => $template], 201);
    }

    // PUT /admin/email-center/templates/{template}
    public function templatesUpdate(Request $request, EmailTemplate $template)
    {
        $validated = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'category'  => ['nullable', 'string', 'max:100'],
            'subject'   => ['sometimes', 'string', 'max:255'],
            'body_html' => ['sometimes', 'string'],
        ]);

        $template->update($validated);

        return response()->json(['message' => 'Template updated.', 'template' => $template]);
    }

    // DELETE /admin/email-center/templates/{template}
    public function templatesDestroy(EmailTemplate $template)
    {
        $template->delete();
        return response()->json(['message' => 'Template deleted.']);
    }

    // =========================================================================
    // LOGS / SENT EMAILS
    // =========================================================================

    // GET /admin/email-center/logs
    public function logs(Request $request)
    {
        $query = SentEmail::with(['admin:id,name', 'investor:id,name,full_name,email'])->latest();

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($w) => $w->where('recipient_email', 'like', "%$s%")
                ->orWhere('subject', 'like', "%$s%")
                ->orWhere('recipient_name', 'like', "%$s%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = (int) $request->get('per_page', 20);
        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    // GET /admin/email-center/logs/{sentEmail}
    public function logsShow(SentEmail $sentEmail)
    {
        $sentEmail->load(['admin:id,name', 'investor:id,name,full_name,email']);
        return response()->json(['email' => $sentEmail]);
    }
}