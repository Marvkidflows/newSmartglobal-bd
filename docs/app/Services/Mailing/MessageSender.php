<?php
// TASK 3 — sends ONE mail_messages row. Every rule that keeps the two environments apart and every consent rule is re-checked HERE, at
// the moment of sending, not only when the audience was built (a person may have unsubscribed, been suspended, or become / ceased to be a
// verified investor in the meantime).
namespace App\Services\Mailing;

use App\Mail\MailingMail;
use App\Models\Mailing\MailCampaign;
use App\Models\Mailing\MailContact;
use App\Models\Mailing\MailMailbox;
use App\Models\Mailing\MailMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MessageSender
{
    public function __construct(protected ConsentService $consent, protected CampaignService $campaigns) {}

    /** @return 'sent'|'skipped'|'failed'|'retry'|'noop' */
    public function send(int $messageId): string
    {
        // Atomic claim: only ONE worker can move queued → sending. A duplicate job, a retried job, or a double dispatch finds 0 rows.
        $claimed = MailMessage::whereKey($messageId)->where('direction', 'outbound')->where('status', 'queued')
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) return 'noop';

        $m = MailMessage::find($messageId);
        $result = $this->deliver($m);
        if ($m->campaign_id) $this->campaigns->finalize($m->campaign_id);
        return $result;
    }

    protected function deliver(MailMessage $m): string
    {
        if ($reason = $this->skipReason($m)) {
            $m->update(['status' => 'skipped', 'skip_reason' => $reason]);
            return 'skipped';
        }

        $to = MailHeaderGuard::address($m->recipient_email);
        if (!$to) return $this->fail($m, 'invalid_address', 'Recipient address is not valid.', false);

        try {
            $mailable = $this->build($m);
            $sent = Mail::mailer((string) config('mailing.mailer'))->to($to, MailHeaderGuard::text($m->recipient_name, 120) ?: null)->send($mailable);
            $m->update([
                'status' => 'sent', 'sent_at' => now(), 'error_code' => null, 'error_message' => null,
                'provider_message_id' => $this->providerId($sent),
            ]);
            return 'sent';
        } catch (\Throwable $e) {
            return $this->fail($m, 'send_failed', $e->getMessage(), true);
        }
    }

    protected function skipReason(MailMessage $m): ?string
    {
        if ($m->campaign_id) {
            $status = MailCampaign::whereKey($m->campaign_id)->value('status');
            if (!in_array($status, ['queued', 'sending'], true)) return 'campaign_cancelled';
        }

        // Suppressed (hard-bounced / complained) addresses are not mailed again.
        $contact = MailContact::where('email', strtolower($m->recipient_email))->first();
        if ($contact && in_array($contact->status, ['bounced', 'suppressed'], true)) return 'suppressed';

        // Consent: only newsletter / announcement need it. essential, transactional, enquiry and support never consult marketing consent.
        if (ConsentService::requiresConsent($m->kind) && !$this->consent->isGranted($m->recipient_email, $m->purpose)) return 'no_consent';

        // Consent purposes are environment-bound: a public purpose can never authorise an investor message and vice versa.
        if ($m->purpose && ConsentService::environmentOf($m->purpose) !== $m->environment) return 'purpose_environment_mismatch';

        if ($m->environment === 'investor') {
            $user = $m->user_id ? User::find($m->user_id) : null;
            if (!InvestorEligibility::isEligible($user) || strtolower($user->email) !== strtolower($m->recipient_email)) return 'not_eligible_investor';
        } elseif ($m->campaign_id && InvestorEligibility::emailIsEligible($m->recipient_email)) {
            return 'verified_investor_in_public';       // bulk public mailing never reaches verified investors (they have their own channel)
        }
        return null;
    }

    protected function build(MailMessage $m): MailingMail
    {
        $contact = $m->contact_id ? MailContact::find($m->contact_id) : MailContact::where('email', strtolower($m->recipient_email))->first();
        $user = $m->user_id ? User::with('investorRank')->find($m->user_id) : null;

        $unsub = null;
        if (ConsentService::requiresConsent($m->kind) && $m->purpose) {
            $unsub = $this->consent->unsubscribeUrl($user && $m->environment === 'investor' ? null : $contact, $user, $m->purpose);
        }

        $first = trim(explode(' ', trim((string) ($m->recipient_name ?: $user?->name ?: '')))[0] ?? '');
        $tags = [
            'first_name'      => e($first !== '' ? $first : 'there'),
            'rank'            => e((string) ($user?->investorRank?->name ?? '')),
            'unsubscribe_url' => e((string) $unsub),
        ];
        $allowed = CampaignService::MERGE_TAGS[$m->environment] ?? [];
        $html = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', fn ($x) => in_array(strtolower($x[1]), $allowed, true) ? ($tags[strtolower($x[1])] ?? '') : '', (string) $m->body_html);
        $subject = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', fn ($x) => in_array(strtolower($x[1]), $allowed, true) ? strip_tags($tags[strtolower($x[1])] ?? '') : '', (string) $m->subject);

        $fromAddress = config('mailing.default_from_address');
        $fromName = config('mailing.default_from_name');
        $replyTo = null;
        if ($m->mailbox_id && ($box = MailMailbox::find($m->mailbox_id)) && $box->is_active) {
            $fromName = MailHeaderGuard::text($box->display_name, 120);
            if ($box->connection_status === 'connected') $fromAddress = $box->address;   // provider-verified sender only
            else $replyTo = $box->address;                                               // otherwise replies route to the mailbox
        }

        return new MailingMail($subject, (string) $html, $m->environment, $m->kind, $unsub, $fromAddress, $fromName, $replyTo);
    }

    protected function providerId($sent): ?string
    {
        try { return $sent?->getMessageId() ? trim((string) $sent->getMessageId(), '<>') : null; } catch (\Throwable) { return null; }
    }

    protected function fail(MailMessage $m, string $code, string $error, bool $mayRetry): string
    {
        $error = self::safeError($error);
        $max = (int) config('mailing.max_attempts', 3);
        Log::warning('mailing.send_failed', ['message_id' => $m->id, 'code' => $code, 'attempt' => $m->attempts]);   // no address, body or secret in logs

        if ($mayRetry && $m->attempts < $max) {
            $m->update(['status' => 'queued', 'error_code' => $code, 'error_message' => $error]);
            return 'retry';
        }
        $m->update(['status' => 'failed', 'failed_at' => now(), 'error_code' => $code, 'error_message' => $error]);
        return 'failed';
    }

    /** Provider errors can echo URLs, keys or addresses: keep the gist, drop anything credential-shaped. */
    public static function safeError(string $e): string
    {
        $e = preg_replace('/(api[-_ ]?key|authorization|bearer|token|password|secret)[^\s,;]*[\s:=]+[^\s,;]+/i', '$1=[redacted]', $e) ?? '';
        $e = preg_replace('/[A-Za-z0-9_\-]{32,}/', '[redacted]', $e) ?? '';
        return mb_substr(trim(preg_replace('/\s+/', ' ', $e) ?? ''), 0, 250);
    }
}
