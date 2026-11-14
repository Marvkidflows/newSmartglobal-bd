<?php
// TASK 3 — one-to-one staff correspondence (replies to enquiries, investor support). The environment rules are enforced here:
//   investor environment → recipient MUST be a verified, eligible investor account;
//   public environment   → recipient MUST NOT be a verified investor (use investor mailing for them).
// The staff member's own mailbox is used when they have one; credentials are never involved.
namespace App\Services\Mailing;

use App\Jobs\SendMailingMessageJob;
use App\Models\Mailing\MailMailbox;
use App\Models\Mailing\MailMessage;
use App\Models\User;
use App\Services\RichTextSanitizer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OutboundService
{
    public function __construct(protected RichTextSanitizer $sanitizer) {}

    public function compose(User $actor, string $environment, array $d, ?Request $req = null): MailMessage
    {
        $kind = $d['kind'] ?? 'support';
        $reply = !empty($d['reply_to_message_id']) ? MailMessage::find($d['reply_to_message_id']) : null;
        if ($reply && $reply->environment !== $environment) {
            throw ValidationException::withMessages(['reply_to_message_id' => 'A message can only be answered in its own mailing environment.']);
        }

        $userId = null; $contactId = $reply?->contact_id; $email = null; $name = null;
        if ($environment === 'investor') {
            $investor = User::find($d['investor_id'] ?? $reply?->user_id);
            if (!InvestorEligibility::isEligible($investor)) {
                throw ValidationException::withMessages(['investor_id' => 'Investor mailing can only be sent to verified, eligible investors.']);
            }
            $userId = $investor->id; $email = $investor->email; $name = $investor->name ?? $investor->full_name;
        } else {
            $email = MailHeaderGuard::address($d['to_email'] ?? $reply?->from_address);
            if (!$email) throw ValidationException::withMessages(['to_email' => 'A valid recipient address is required.']);
            if (InvestorEligibility::emailIsEligible($email)) {
                throw ValidationException::withMessages(['to_email' => 'This address belongs to a verified investor. Use investor mailing.']);
            }
            $name = $reply?->recipient_name;
        }

        $content = app(CampaignService::class)->cleanContent($environment, (string) $d['subject'], (string) $d['body_html']);
        $box = MailMailbox::where('user_id', $actor->id)->where('is_active', true)->first();
        $departmentId = $d['department_id'] ?? $reply?->department_id ?? $box?->department_id;

        $key = !empty($d['client_token']) ? sha1('compose|' . $actor->id . '|' . $d['client_token']) : null;
        if ($key && ($dup = MailMessage::where('idempotency_key', $key)->first())) return $dup;    // double-clicked "Send" → same message

        $m = MailMessage::create([
            'environment' => $environment, 'direction' => 'outbound', 'kind' => $kind, 'department_id' => $departmentId, 'mailbox_id' => $box?->id,
            'user_id' => $userId, 'contact_id' => $contactId, 'sender_user_id' => $actor->id, 'recipient_email' => $email, 'recipient_name' => $name,
            'subject' => $content['subject'], 'body_html' => $content['body_html'], 'status' => 'queued', 'idempotency_key' => $key, 'queued_at' => now(),
        ]);
        if ($reply && $reply->direction === 'inbound') $reply->update(['assigned_to' => $reply->assigned_to ?? $actor->id]);

        MailingAudit::record($actor, 'message.composed', $environment, $m, ['kind' => $kind, 'department_id' => $departmentId, 'reply' => (bool) $reply], $req);
        SendMailingMessageJob::dispatch($m->id);
        return $m;
    }
}
