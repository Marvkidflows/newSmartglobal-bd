<?php
// LOCATION: app/Mail/RegistrationResumeCodeMail.php
// The one-time code that proves the holder owns the email of an unfinished registration. States plainly that nothing has changed and what to
// do if the recipient did not ask for it.

namespace App\Mail;

use Illuminate\Mail\Mailable;

class RegistrationResumeCodeMail extends Mailable
{
    public function __construct(public string $code, public string $userName, public int $ttlMinutes) {}

    public function build()
    {
        return $this->subject('Your code to continue your SSI registration')
            ->view('emails.registration-resume-code')
            ->with(['code' => $this->code, 'userName' => $this->userName, 'ttlMinutes' => $this->ttlMinutes]);
    }
}
