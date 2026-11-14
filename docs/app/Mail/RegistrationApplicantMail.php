<?php
// LOCATION: app/Mail/RegistrationApplicantMail.php
// Task 2 — plain-text correspondence from the Registration Team to an applicant. The body is typed by a staff member and is rendered
// ESCAPED (never as HTML), so a reviewer cannot inject markup or tracking pixels. Sent synchronously (see AdminCustomEmail's note on
// queued mailables) so the delivery outcome can be recorded against the communication row.

namespace App\Mail;

use Illuminate\Mail\Mailable;

class RegistrationApplicantMail extends Mailable
{
    public function __construct(
        public string $emailSubject,
        public string $messageBody,
        public string $kind,
        public ?string $applicantName = null,
    ) {}

    public function build()
    {
        return $this->subject($this->emailSubject)
            ->view('emails.registration-applicant')
            ->with([
                'subjectLine'   => $this->emailSubject,
                'messageBody'   => $this->messageBody,
                'applicantName' => $this->applicantName,
                'isFollowUp'    => $this->kind === 'follow_up',
            ]);
    }
}
