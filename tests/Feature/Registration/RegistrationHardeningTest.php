<?php
// LOCATION: tests/Feature/Registration/RegistrationHardeningTest.php
// Task 2 security review follow-ups: output safety, header injection, secret redaction, and the "awaiting approval" configuration contract.

namespace Tests\Feature\Registration;

use App\Models\RegistrationCommunication;
use App\Services\Registration\RegistrationCommunicationService;
use Illuminate\Support\Facades\Mail;

class RegistrationHardeningTest extends RegistrationTestCase
{
    public function test_only_https_document_links_are_ever_returned_to_a_reviewer(): void
    {
        $app = $this->submittedApplicant();
        foreach (['javascript:alert(document.cookie)', 'data:text/html,<script>1</script>', 'http://insecure.example/x.jpg', 'https://ok.example/a b.jpg', 'https://ok.example/"onerror=x'] as $bad) {
            $app->user->forceFill(['id_document_path' => $bad, 'selfie_path' => $bad])->save();
            $r = $this->asUser($this->member)->getJson("/api/registration/applications/{$app->id}")->assertOk();
            $this->assertNull($r->json('application.kyc.id_document_url'), "{$bad} must not be returned");
            $this->assertNull($r->json('application.kyc.selfie_url'));
        }
        $app->user->forceFill(['id_document_path' => 'https://res.cloudinary.com/demo/id.jpg', 'selfie_path' => 'https://res.cloudinary.com/demo/selfie.jpg'])->save();
        $r = $this->asUser($this->member)->getJson("/api/registration/applications/{$app->id}")->assertOk();
        $this->assertSame('https://res.cloudinary.com/demo/id.jpg', $r->json('application.kyc.id_document_url'));
    }

    public function test_follow_up_subject_cannot_carry_line_breaks_that_would_inject_email_headers(): void
    {
        Mail::fake();
        $u = $this->applicant();
        $this->tracker()->attempt($u, null, false);
        $app = $u->registrationApplication()->first();

        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/follow-up", [
            'subject' => "Hello\r\nBcc: attacker@example.test", 'message' => 'Please finish registering.',
        ])->assertStatus(422)->assertJsonValidationErrors(['subject']);
        Mail::assertNothingSent();
    }

    public function test_delivery_errors_shown_to_staff_never_contain_credentials_or_addresses(): void
    {
        $clean = RegistrationCommunicationService::safeError('401 Unauthorized api-key=xkeysib-0123456789abcdef0123456789abcdef for person@example.com');
        $this->assertStringNotContainsString('xkeysib', $clean);
        $this->assertStringNotContainsString('person@example.com', $clean);
        $this->assertStringContainsString('401 Unauthorized', $clean);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('auth failed key=xkeysib-0123456789abcdef0123456789abcdef'));
        $u = $this->applicant();
        $this->tracker()->attempt($u, null, false);
        $app = $u->registrationApplication()->first();
        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/follow-up", ['message' => 'Please finish registering.'])->assertStatus(422);

        $stored = RegistrationCommunication::where('application_id', $app->id)->first();
        $this->assertSame('failed', $stored->status);
        $this->assertStringNotContainsString('xkeysib', (string) $stored->error);
    }

    public function test_a_foreign_visitor_token_cannot_hijack_a_visit_already_linked_to_someone_else(): void
    {
        $token = 'visitor-token-abcdef-123456';
        $this->postJson('/api/public/registration-tracking/visit', ['visitor_token' => $token, 'consent' => true])->assertStatus(202);

        $first = $this->applicant();
        $this->tracker()->attempt($first, $token, false);
        $second = $this->applicant();
        $this->tracker()->attempt($second, $token, false);   // someone replays the same token

        $this->assertSame($first->id, \App\Models\RegistrationVisit::first()->user_id, 'the first link wins and is never overwritten');
    }

    public function test_anonymous_visit_rows_have_a_global_hourly_ceiling(): void
    {
        config(['registration.visit_max_new_per_hour' => 3]);
        \Illuminate\Support\Facades\Cache::flush();

        foreach (range(1, 6) as $i) {
            $this->postJson('/api/public/registration-tracking/visit', ['visitor_token' => 'flood-visitor-token-' . str_pad((string) $i, 4, '0'), 'consent' => true])->assertStatus(202);
        }
        $this->assertSame(3, \App\Models\RegistrationVisit::count(), 'rows beyond the ceiling are dropped, visitors are unaffected');
    }

    // ── configuration contract: tracking OFF by default; follow-up defaults configurable and flagged as awaiting approval ──

    public function test_visit_tracking_ships_disabled_by_default(): void
    {
        $source = file_get_contents(config_path('registration.php'));
        $this->assertStringContainsString("env('REGISTRATION_VISIT_TRACKING', false)", $source, 'default must be false');
        $this->assertStringContainsString('PENDING MANAGEMENT / LEGAL APPROVAL', $source);
        $this->assertStringNotContainsString("env('REGISTRATION_VISIT_TRACKING', true)", $source);
    }

    public function test_follow_up_defaults_are_environment_configurable_and_marked_as_awaiting_approval(): void
    {
        $source = file_get_contents(config_path('registration.php'));
        foreach (['REGISTRATION_INCOMPLETE_AFTER_HOURS', 'REGISTRATION_FOLLOWUP_COOLDOWN_HOURS', 'REGISTRATION_FOLLOWUP_MAX'] as $env) {
            $this->assertStringContainsString("env('{$env}'", $source, "{$env} must be configurable");
        }
        $this->assertStringContainsString('PENDING MANAGEMENT APPROVAL', $source);
        $this->assertStringContainsString('NOT approved SSI policy', $source);
    }

    public function test_settings_command_reports_values_in_force_and_flags_what_awaits_approval(): void
    {
        config(['registration.visit_tracking_enabled' => false, 'registration.followup_max' => 7]);

        $this->artisan('registration:settings')
            ->expectsOutputToContain('AWAITING MANAGEMENT / LEGAL APPROVAL')
            ->expectsOutputToContain('= OFF')
            ->expectsOutputToContain('= 7')
            ->expectsOutputToContain('SECURITY CONTROLS')
            ->assertSuccessful();
    }

    public function test_changing_follow_up_settings_changes_behaviour_without_code_changes(): void
    {
        Mail::fake();
        config(['registration.followup_max' => 1]);
        $u = $this->applicant();
        $this->tracker()->attempt($u, null, false);
        $app = $u->registrationApplication()->first();

        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/follow-up", ['message' => 'First message here.'])->assertOk();
        \App\Models\RegistrationApplication::whereKey($app->id)->update(['last_followup_at' => now()->subDays(30)]);
        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/follow-up", ['message' => 'Second message here.'])->assertStatus(422);

        config(['registration.followup_max' => 2]);
        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/follow-up", ['message' => 'Second message here.'])->assertOk();
    }
}
