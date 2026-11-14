<?php
// LOCATION: app/Mail/RegistrationEmailCodeMail.php
// Code that proves the holder controls an email address before an SSI account is created. Deliberately contains NO name or other text taken from the
// (unauthenticated) request, so it cannot be used to put attacker-chosen wording into a message sent to a third party.

namespace App\Mail;

use Illuminate\Mail\Mailable;

class RegistrationEmailCodeMail extends Mailable
{
    public function __construct(public string $code, public int $ttlMinutes) {}

    public function build()
    {
        return $this->subject('Your SSI verification code')
            ->view('emails.registration-email-code')
            ->with(['code' => $this->code, 'ttlMinutes' => $this->ttlMinutes]);
    }
}
