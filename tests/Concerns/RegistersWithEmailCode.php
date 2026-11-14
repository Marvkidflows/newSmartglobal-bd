<?php
// LOCATION: tests/Concerns/RegistersWithEmailCode.php
// Test helper for the verify-first registration contract: stage 1 creates NO account and issues NO token; the account (or the resume of an unfinished
// one) happens at POST /api/register/verify with the code that was emailed. Mail is faked, so the code is read from the captured mailable.

namespace Tests\Concerns;

use App\Mail\RegistrationEmailCodeMail;
use App\Mail\RegistrationResumeCodeMail;
use Illuminate\Support\Facades\Mail;

trait RegistersWithEmailCode
{
    /** The 6-digit code most recently emailed (new-email code or resume code), or null if none was sent. */
    protected function emailedCode(): ?string
    {
        $m = Mail::sent(RegistrationEmailCodeMail::class)->last() ?? Mail::sent(RegistrationResumeCodeMail::class)->last();
        return $m?->code;
    }

    /** Stage 1 only (the response is identical for every email). Mail is faked from here on. */
    protected function stage1(array $payload, array $extra = [])
    {
        Mail::fake();
        return $this->postJson('/api/register/stage1', array_merge($payload, $extra));
    }

    /** Verify with an explicit code. */
    protected function verifyWith(array $payload, string $code, array $extra = [])
    {
        return $this->postJson('/api/register/verify', array_merge($payload, $extra, ['code' => $code]));
    }

    /** Full journey for a NEW (or unfinished) email: stage 1, read the emailed code, verify. Returns the verify response. */
    protected function registerViaCode(array $payload, array $stage1Extra = [], array $verifyExtra = [])
    {
        $this->stage1($payload, $stage1Extra)->assertOk();
        $code = $this->emailedCode();
        $this->assertNotNull($code, 'a code must have been emailed');
        return $this->verifyWith($payload, $code, $verifyExtra);
    }
}
