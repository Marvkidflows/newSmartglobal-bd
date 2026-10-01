<?php
// LOCATION: app/Services/FinancialMailService.php
//
// Sends an EMAIL COPY of a Financial Team in-app message / notice.
//
// This reuses the existing Email Center plumbing — the sent_emails table,
// the Brevo mailer, AdminCustomEmail (branded "Financial Team") and
// SendBulkEmailJob — so copies show up in Email Center → Sent Emails / Logs
// and in the investor's Email History exactly like emails composed there.
//
// The in-app message is always the primary record: callers create it first,
// and an email failure here is reported back, never thrown, so it can never
// roll back or hide the message itself.

namespace App\Services;

use App\Jobs\SendBulkEmailJob;
use App\Mail\AdminCustomEmail;
use App\Models\Message;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class FinancialMailService
{
    // Same guardrail as the Email Center: without a real queue, a large
    // synchronous batch would time the request out.
    public const SYNC_LIMIT = 25;

    public function __construct(protected FinancialAuditService $audit) {}

    // Plain-text message body -> safe HTML (escaped, line breaks kept).
    public static function toHtml(string $text): string
    {
        return '<p>' . nl2br(e($text)) . '</p>';
    }

    public static function defaultSubject(string $kind): string
    {
        return $kind === 'notice'
            ? 'Official notice from the Financial Team'
            : 'Message from the Financial Team';
    }

    /**
     * Send one email copy immediately (used for Inbox replies).
     * Returns ['status' => 'sent'|'failed', 'id' => int, 'error' => ?string].
     */
    public function sendNow(User $actor, User $investor, ?string $subject, string $text, string $kind = 'message'): array
    {
        $subject = $subject ?: self::defaultSubject($kind);
        $html    = self::toHtml($text);
        $sent    = $this->record($actor, $investor, $subject, $html, null);

        $error = null;
        try {
            Mail::mailer('brevo')
                ->to($investor->email)
                ->send(new AdminCustomEmail($subject, $html, null, null, 'financial'));

            $sent->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
            $error = $e->getMessage();
            $sent->update(['status' => 'failed', 'error_message' => $error]);
        }

        // Audit outside the try: an audit problem must not mark a delivered email as failed.
        $this->audit->record(
            $actor,
            $error === null ? 'communication.email_sent' : 'communication.email_failed',
            'sent_email', $sent->id, $investor->id, null,
            ['subject' => $subject, 'recipient_count' => 1, 'status' => $sent->status, 'via' => 'message_copy'],
            null
        );

        return ['status' => $sent->status, 'id' => $sent->id, 'error' => $error ? Str::limit($error, 300) : null];
    }

    /**
     * Queue one email copy (used for Official Communications to many).
     * Delivered by SendBulkEmailJob, which updates the sent_emails status.
     */
    public function queue(User $actor, User $investor, ?string $subject, string $text, string $batchId, string $kind = 'notice'): SentEmail
    {
        $subject = $subject ?: self::defaultSubject($kind);
        $sent    = $this->record($actor, $investor, $subject, self::toHtml($text), $batchId);

        SendBulkEmailJob::dispatch($sent->id);

        return $sent;
    }

    protected function record(User $actor, User $investor, string $subject, string $html, ?string $batchId): SentEmail
    {
        return SentEmail::create([
            'batch_id'        => $batchId,
            'admin_id'        => $actor->id,
            'department'      => 'financial',
            'sender_label'    => Message::FINANCIAL_SENDER_LABEL,
            'investor_id'     => $investor->id,
            'recipient_name'  => $investor->name ?? $investor->full_name,
            'recipient_email' => $investor->email,
            'subject'         => $subject,
            'body_html'       => $html,
            'status'          => 'queued',
        ]);
    }
}
