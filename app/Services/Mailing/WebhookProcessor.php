<?php
// TASK 3 — provider webhooks: delivery events (Brevo transactional-event shape) and inbound-parsing payloads.
// NOT verified against a live provider account in this delivery (no network access in the build environment); mappings are defensive and
// unknown events are ignored. See docs/TASK3_REPORT.md → Known limitations.
namespace App\Services\Mailing;

use App\Models\Mailing\MailContact;
use App\Models\Mailing\MailDepartment;
use App\Models\Mailing\MailMailbox;
use App\Models\Mailing\MailMessage;
use App\Models\User;
use App\Services\RichTextSanitizer;
use Illuminate\Http\Request;

class WebhookProcessor
{
    public function __construct(protected ConsentService $consent, protected RichTextSanitizer $sanitizer) {}

    public function delivery(array $p): bool
    {
        $event = strtolower((string) ($p['event'] ?? ''));
        $id = trim((string) ($p['message-id'] ?? $p['messageId'] ?? ''), '<> ');
        $email = MailHeaderGuard::address($p['email'] ?? null);
        $reason = SafeText::reason($p['reason'] ?? null);

        $m = $id !== '' ? MailMessage::where('provider_message_id', $id)->first() : null;

        switch ($event) {
            case 'delivered':
                if ($m && $m->status === 'sent') $m->update(['status' => 'delivered', 'delivered_at' => now()]);
                return (bool) $m;

            case 'hard_bounce': case 'blocked': case 'invalid': case 'invalid_email': case 'error':
                if ($m && in_array($m->status, ['sent', 'delivered', 'sending'], true)) {
                    $m->update(['status' => 'bounced', 'failed_at' => now(), 'error_code' => $event, 'error_message' => $reason]);
                }
                if ($email) $this->suppress($email, 'bounced');
                return true;

            case 'soft_bounce': case 'deferred':
                if ($m) $m->update(['error_code' => $event, 'error_message' => $reason]);   // status unchanged: the provider will retry
                return (bool) $m;

            case 'spam': case 'complaint':
                if ($email) { $this->suppress($email, 'suppressed'); $this->consent->withdrawAll($email, 'provider_complaint'); }
                return true;

            case 'unsubscribed': case 'unsubscribe':
                if ($email) $this->consent->withdrawAll($email, 'provider_unsubscribe');    // optional mail only; essential notices don't consult consent
                return true;
        }
        return false;
    }

    /** @return int number of stored inbound messages */
    public function inbound(array $p): int
    {
        $stored = 0;
        foreach ((array) ($p['items'] ?? []) as $item) {
            $from = MailHeaderGuard::address($item['From']['Address'] ?? null);
            if (!$from) continue;
            foreach ((array) ($item['To'] ?? []) as $to) {
                $box = MailMailbox::where('address', MailHeaderGuard::address($to['Address'] ?? null) ?? '-')->where('is_active', true)->first();
                if (!$box) continue;                                                     // not one of our mailboxes → dropped
                $dept = MailDepartment::find($box->department_id);
                $env = $dept?->environment === 'both' ? (InvestorEligibility::emailIsEligible($from) ? 'investor' : 'public') : ($dept?->environment ?? 'public');
                $key = sha1('inbound|' . ($item['MessageId'] ?? $item['Uuid'][0] ?? microtime(true)) . '|' . $box->id);
                if (MailMessage::where('idempotency_key', $key)->exists()) continue;

                $html = $this->sanitizer->sanitizeEmail((string) ($item['RawHtmlBody'] ?? '')) ?: nl2br(e(mb_substr(strip_tags((string) ($item['RawTextBody'] ?? '')), 0, 20000)), false);
                $user = User::whereRaw('LOWER(email) = ?', [$from])->where('role', 'investor')->first();
                MailMessage::create([
                    'environment' => $env, 'direction' => 'inbound', 'kind' => 'support', 'department_id' => $box->department_id, 'mailbox_id' => $box->id,
                    'assigned_to' => $box->user_id, 'user_id' => $user?->id, 'from_address' => $from, 'recipient_email' => $box->address,
                    'recipient_name' => MailHeaderGuard::text($item['From']['Name'] ?? '', 120) ?: null,
                    'subject' => MailHeaderGuard::text($item['Subject'] ?? '(no subject)', 255) ?: '(no subject)',
                    'body_html' => $html, 'status' => 'received', 'idempotency_key' => $key, 'queued_at' => now(),
                ]);
                $stored++;
            }
        }
        return $stored;
    }

    protected function suppress(string $email, string $status): void
    {
        $c = MailContact::firstOrCreate(['email' => $email], ['source' => 'provider_event']);
        $c->update(['status' => $status]);
    }

    /** Constant-time check of the shared webhook secret (Authorization: Bearer … or X-SSI-Webhook-Secret). */
    public static function authorised(Request $r): bool
    {
        $secret = (string) config('mailing.webhook_secret');
        if ($secret === '') return false;
        $given = (string) ($r->header('X-SSI-Webhook-Secret') ?: $r->bearerToken());
        return $given !== '' && hash_equals($secret, $given);
    }
}
