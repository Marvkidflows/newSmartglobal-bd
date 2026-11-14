<?php
// TASK 3 — public-facing flows: contact / enquiry form, newsletter sign-up (double opt-in), confirmation and unsubscribe.
// Nothing here ever reads or returns investor data. Responses are identical whether or not an address already exists (no enumeration).
namespace App\Services\Mailing;

use App\Jobs\SendMailingMessageJob;
use App\Models\Mailing\MailContact;
use App\Models\Mailing\MailDepartment;
use App\Models\Mailing\MailMailbox;
use App\Models\Mailing\MailMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicMailingService
{
    public function __construct(protected ConsentService $consent) {}

    /** @return array{stored:bool} */
    public function submitEnquiry(array $d, Request $req): array
    {
        // Honeypot: real visitors never see the "website" field. Bots get the same success response and nothing is stored.
        if (!empty($d['website'])) return ['stored' => false];

        $email = MailHeaderGuard::address($d['email']);
        if (!$email) return ['stored' => false];
        $name = MailHeaderGuard::text($d['name'] ?? '', 120);
        $subject = MailHeaderGuard::text($d['subject'] ?? 'Website enquiry', 150) ?: 'Website enquiry';
        $message = $this->plainText((string) $d['message']);

        $window = (int) config('mailing.contact.dedupe_minutes', 10) * 60;
        $key = sha1('enquiry|' . $email . '|' . $subject . '|' . $message . '|' . intdiv(time(), max(60, $window)));
        if (MailMessage::where('idempotency_key', $key)->exists()) return ['stored' => false];   // identical resubmission → accepted, not duplicated

        $dept = MailDepartment::where('slug', 'general-enquiries')->first();
        $box = $dept ? MailMailbox::where('department_id', $dept->id)->whereNull('user_id')->where('is_active', true)->first() : null;

        $stored = DB::transaction(function () use ($email, $name, $subject, $message, $key, $dept, $box, $d, $req) {
            $contact = MailContact::firstOrCreate(['email' => $email], ['name' => $name ?: null, 'source' => 'contact_form']);

            MailMessage::create([
                'environment' => 'public', 'direction' => 'inbound', 'kind' => 'enquiry', 'department_id' => $dept?->id, 'mailbox_id' => $box?->id,
                'contact_id' => $contact->id, 'from_address' => $email, 'recipient_email' => $box?->address ?? ($dept?->slug . '@unrouted'),
                'recipient_name' => $name ?: null, 'subject' => $subject, 'body_html' => nl2br(e($message), false),
                'status' => 'received', 'idempotency_key' => $key, 'queued_at' => now(),
            ]);

            if (!empty($d['subscribe_newsletter'])) {
                $this->consent->request($email, 'public_newsletter', 'contact_form', $contact, null, $req);
            }
            return $contact;
        });

        $this->sendNewsletterConfirmation($stored, 'public_newsletter', onlyIfPending: true);
        $this->acknowledge($stored);
        return ['stored' => true];
    }

    /** Newsletter / announcement sign-up. Step 1 of double opt-in: nothing is granted until the person confirms from their inbox. */
    public function subscribe(string $email, ?string $name, array $purposes, Request $req): void
    {
        $email = MailHeaderGuard::address($email);
        if (!$email) return;
        $contact = MailContact::firstOrCreate(['email' => $email], ['name' => MailHeaderGuard::text($name, 120) ?: null, 'source' => 'newsletter_form']);
        foreach (array_intersect($purposes, ConsentService::PUBLIC_PURPOSES) as $p) {
            $this->consent->request($email, $p, 'newsletter_form', $contact, null, $req);
            $this->sendNewsletterConfirmation($contact, $p, onlyIfPending: true);
        }
    }

    /** @return bool true if a state change happened */
    public function confirm(string $ref, string $purpose, Request $req): bool
    {
        [$contact, $user, $email] = $this->consent->resolveRef($ref);
        if (!$email || ConsentService::environmentOf($purpose) !== 'public') return false;
        $this->consent->grant($email, $purpose, 'double_opt_in', $contact, $user, $req);
        return true;
    }

    public function unsubscribe(string $ref, string $purpose, Request $req): bool
    {
        [$contact, $user, $email] = $this->consent->resolveRef($ref);
        if (!$email || !ConsentService::environmentOf($purpose)) return false;
        $this->consent->withdraw($email, $purpose, 'unsubscribe_link', $contact, $user, $req);
        return true;
    }

    protected function sendNewsletterConfirmation(MailContact $contact, string $purpose, bool $onlyIfPending): void
    {
        if ($onlyIfPending && !\App\Models\Mailing\MailSubscription::where('email', $contact->email)->where('purpose', $purpose)->where('status', 'pending')->exists()) return;
        $url = $this->consent->confirmUrl($contact, $purpose);
        $label = $purpose === 'public_announcements' ? 'website announcements' : 'the SSI newsletter';
        $this->queueTransactional($contact, 'confirm|' . $purpose, 'Please confirm your subscription',
            '<p>Hello,</p><p>Someone (hopefully you) asked to receive ' . e($label) . ' from Smart System Investment.</p>'
            . '<p><a href="' . e($url) . '">Confirm my subscription</a></p><p>If this was not you, ignore this message — you will not be subscribed.</p>', 'confirmation');
    }

    protected function acknowledge(MailContact $contact): void
    {
        $this->queueTransactional($contact, 'ack', 'We have received your enquiry',
            '<p>Hello' . ($contact->name ? ' ' . e(explode(' ', $contact->name)[0]) : '') . ',</p>'
            . '<p>Thank you for contacting Smart System Investment. Your message has been received and the relevant team will reply to you by email.</p>'
            . '<p>For your security, please do not send passwords or PINs by email.</p>', 'enquiry_ack');
    }

    /** One transactional message per (address, kind, time bucket) — the unique key stops reflection / spam via the public forms. */
    protected function queueTransactional(MailContact $contact, string $keyPart, string $subject, string $html, string $purposeTag): void
    {
        $bucket = intdiv(time(), max(1, (int) config('mailing.contact.ack_hours', 24)) * 3600);
        $key = sha1('tx|' . $keyPart . '|' . $contact->email . '|' . $bucket);
        $dept = MailDepartment::where('slug', 'general-enquiries')->first();
        $now = now();

        $created = MailMessage::insertOrIgnore([[
            'environment' => 'public', 'direction' => 'outbound', 'kind' => 'transactional', 'purpose' => null, 'department_id' => $dept?->id,
            'contact_id' => $contact->id, 'recipient_email' => $contact->email, 'recipient_name' => $contact->name, 'subject' => $subject,
            'body_html' => $html, 'status' => 'queued', 'idempotency_key' => $key, 'queued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]]);
        if ($created) {
            $id = MailMessage::where('idempotency_key', $key)->value('id');
            if ($id) SendMailingMessageJob::dispatch($id);
        }
    }

    protected function plainText(string $s): string
    {
        $s = strip_tags($s);
        $s = preg_replace('/[^\P{C}\n]+/u', '', $s) ?? $s;          // drop control characters, keep line breaks (body only — headers never use this)
        return mb_substr(trim($s), 0, (int) config('mailing.contact.max_message', 5000));
    }
}
