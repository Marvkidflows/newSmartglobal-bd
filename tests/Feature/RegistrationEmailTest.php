<?php
// LOCATION: tests/Feature/RegistrationEmailTest.php
//
// Covers the fix for silently-swallowed verification-email failures:
// RegisterController::submitStage1 and EmailVerificationController::resend
// used to catch a failed OtpService::generateAndSend() and still report
// success to the frontend, so a real send failure was invisible to the
// user. Both endpoints now report `email_sent`/an honest message (stage1)
// or a clean 422 (resend) instead of a false positive or an uncaught 500.

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationEmailTest extends TestCase
{
    use RefreshDatabase;

    protected array $stage1Payload = [
        'full_name'             => 'Test Investor',
        'email'                 => 'test.investor@example.com',
        'country_iso2'          => 'US',
        'phone'                 => '2015550123',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ];

    // CONTRACT CHANGE (enumeration closure). The original tests asserted that stage 1 created the account and told the caller when the OTP email
    // failed. Stage 1 now creates nothing and must answer identically in every case (a different answer for a failed send would reveal whether an
    // address is registered). The honesty requirement is kept differently: a failed send is recorded, retires its code (never usable) and still
    // counts toward the per-address cooldown/hourly cap, and the visitor can always request another code after the retry interval shown.

    public function test_a_failed_code_email_looks_identical_to_a_successful_one_but_is_recorded_and_unusable(): void
    {
        \Illuminate\Support\Facades\Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('Brevo API error (simulated)'));

        $response = $this->postJson('/api/register/stage1', $this->stage1Payload);

        $response->assertStatus(200)->assertJson(['success' => true, 'verification_required' => true, 'email_sent' => true]);
        $this->assertDatabaseMissing('users', ['email' => 'test.investor@example.com']);
        $row = \App\Models\RegistrationEmailChallenge::where('email', 'test.investor@example.com')->firstOrFail();
        $this->assertSame('failed', $row->delivery_status);
        $this->assertNotNull($row->consumed_at);
    }

    public function test_stage1_emails_a_code_and_creates_no_account_when_the_send_succeeds(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $response = $this->postJson('/api/register/stage1', $this->stage1Payload);

        $response->assertStatus(200)->assertJson(['success' => true, 'verification_required' => true, 'email_sent' => true]);
        $response->assertJsonMissingPath('token');
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\RegistrationEmailCodeMail::class, fn ($m) => $m->hasTo('test.investor@example.com'));
        $this->assertDatabaseMissing('users', ['email' => 'test.investor@example.com']);
    }

    /** Previously this threw an uncaught exception (raw 500). */
    public function test_resend_otp_returns_clean_error_when_send_fails(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->app->bind(OtpService::class, function () {
            return new class extends OtpService {
                public function generateAndSend(User $user): void
                {
                    throw new \Exception('Brevo API error (simulated)');
                }
            };
        });

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/register/resend-otp');

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }
}
