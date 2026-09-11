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

    /** A failing mail send should never be silently reported as a success. */
    public function test_stage1_reports_email_sent_false_when_otp_send_fails(): void
    {
        $this->app->bind(OtpService::class, function () {
            return new class extends OtpService {
                public function generateAndSend(User $user): void
                {
                    throw new \Exception('Brevo API error (simulated)');
                }
            };
        });

        $response = $this->postJson('/api/register/stage1', $this->stage1Payload);

        // The account itself still gets created — only the email failed.
        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'email_sent' => false]);
        $this->assertStringContainsString('could not send', strtolower($response->json('message')));
        $this->assertDatabaseHas('users', ['email' => 'test.investor@example.com']);
    }

    public function test_stage1_reports_email_sent_true_when_otp_send_succeeds(): void
    {
        $this->app->bind(OtpService::class, function () {
            return new class extends OtpService {
                public function generateAndSend(User $user): void
                {
                    // no-op — simulates a successful send without hitting Brevo
                }
            };
        });

        $response = $this->postJson('/api/register/stage1', $this->stage1Payload);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'email_sent' => true]);
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
