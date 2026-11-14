<?php
// TASK 3 — mailable for every mailing-module message. Deliberately NOT ShouldQueue: queueing happens at the job level (see
// SendMailingMessageJob), exactly as for the existing bulk-email path, so a message is never marked "sent" before it was handed over.
namespace App\Mail;

use App\Services\Mailing\MailHeaderGuard;
use App\Services\RichTextSanitizer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class MailingMail extends Mailable
{
    public string $safeSubject;
    public string $safeBody;

    public function __construct(
        string $subject, string $bodyHtml,
        public string $environment, public string $kind,
        public ?string $unsubscribeUrl = null,
        public ?string $fromAddress = null, public ?string $fromName = null, public ?string $replyToAddress = null,
    ) {
        $this->safeSubject = MailHeaderGuard::text($subject, 190);
        $this->safeBody = app(RichTextSanitizer::class)->sanitizeEmail($bodyHtml);   // last line of defence before the Blade view prints it raw
    }

    public function envelope(): Envelope
    {
        $from = MailHeaderGuard::address($this->fromAddress);
        $reply = MailHeaderGuard::address($this->replyToAddress);
        return new Envelope(
            from: $from ? new Address($from, MailHeaderGuard::text($this->fromName, 120)) : null,
            replyTo: $reply ? [new Address($reply)] : [],
            subject: $this->safeSubject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.mailing', with: [
            'subjectLine' => $this->safeSubject, 'bodyHtml' => $this->safeBody, 'environment' => $this->environment,
            'kind' => $this->kind, 'unsubscribeUrl' => $this->unsubscribeUrl,
        ]);
    }

    public function headers(): Headers
    {
        $text = ['X-SSI-Mail-Environment' => $this->environment];
        if ($this->unsubscribeUrl) {
            $text['List-Unsubscribe'] = '<' . MailHeaderGuard::text($this->unsubscribeUrl, 500) . '>';
            $text['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
        return new Headers(text: $text);
    }
}
