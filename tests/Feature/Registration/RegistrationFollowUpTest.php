<?php
// LOCATION: tests/Feature/Registration/RegistrationFollowUpTest.php
// Task 2 — follow-up with applicants: consent/opt-out, cooldown, caps, duplicate-send prevention, delivery-failure logging.

namespace Tests\Feature\Registration;

use App\Mail\RegistrationApplicantMail;
use App\Models\RegistrationApplication as A;
use App\Models\RegistrationCommunication;
use App\Services\Registration\RegistrationCommunicationService;
use Illuminate\Support\Facades\Mail;

class RegistrationFollowUpTest extends RegistrationTestCase
{
    private function incomplete(): A
    {
        $u = $this->applicant();
        $this->tracker()->attempt($u, null, false);
        return $u->registrationApplication()->first();
    }

    private function follow(A $app, $actor = null, string $msg = 'We noticed you have not finished registering.')
    {
        return ($this)->asUser($actor ?? $this->member)->postJson("/api/registration/applications/{$app->id}/follow-up", ['message' => $msg]);
    }

    public function test_a_follow_up_is_sent_logged_and_recorded_in_the_history(): void
    {
        Mail::fake();
        $app = $this->incomplete();

        $this->follow($app)->assertOk();

        Mail::assertSent(RegistrationApplicantMail::class, fn ($m) => $m->hasTo($app->user->email) && $m->kind === 'follow_up');
        $app->refresh();
        $this->assertSame(1, $app->followup_count);
        $this->assertNotNull($app->last_followup_at);
        $this->assertDatabaseHas('registration_communications', ['application_id' => $app->id, 'kind' => 'follow_up', 'status' => 'sent', 'sent_by' => $this->member->id, 'to_email' => $app->user->email]);
        $this->assertDatabaseHas('registration_events', ['application_id' => $app->id, 'event_type' => 'communication_sent', 'actor_id' => $this->member->id]);
    }

    public function test_a_second_follow_up_inside_the_cooldown_is_refused_and_sends_nothing(): void
    {
        Mail::fake();
        $app = $this->incomplete();
        $this->follow($app)->assertOk();
        $this->follow($app)->assertStatus(422);
        $this->follow($app, $this->lead)->assertStatus(422);   // the rule applies to everyone, not per person

        Mail::assertSentCount(1);
        $this->assertSame(1, RegistrationCommunication::where('application_id', $app->id)->count());
    }

    public function test_after_the_cooldown_another_follow_up_is_allowed_up_to_the_maximum(): void
    {
        Mail::fake();
        config(['registration.followup_max' => 2, 'registration.followup_cooldown_hours' => 72]);
        $app = $this->incomplete();

        $this->follow($app)->assertOk();
        A::whereKey($app->id)->update(['last_followup_at' => now()->subHours(73)]);
        $this->follow($app)->assertOk();
        A::whereKey($app->id)->update(['last_followup_at' => now()->subHours(73)]);
        $this->follow($app)->assertStatus(422);   // cap reached

        Mail::assertSentCount(2);
        $this->assertSame(2, $app->fresh()->followup_count);
    }

    public function test_an_applicant_who_opted_out_receives_no_follow_up_and_it_can_be_lifted(): void
    {
        Mail::fake();
        $app = $this->incomplete();

        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/follow-up-opt-out", ['opted_out' => true, 'note' => 'Replied asking us to stop'])->assertOk();
        $this->follow($app)->assertStatus(422)->assertJsonFragment(['message' => 'This applicant has opted out of follow-up messages.']);
        Mail::assertNothingSent();

        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/follow-up-opt-out", ['opted_out' => false])->assertOk();
        $this->follow($app)->assertOk();
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('registration_events', ['application_id' => $app->id, 'event_type' => 'followup_opt_out']);
        $this->assertDatabaseHas('registration_events', ['application_id' => $app->id, 'event_type' => 'followup_opt_in']);
    }

    public function test_follow_up_is_not_available_once_an_application_is_under_review_or_decided(): void
    {
        Mail::fake();
        $app = $this->submittedApplicant();
        $this->follow($app)->assertStatus(422);

        $this->asUser($this->lead)->postJson("/api/registration/applications/{$app->id}/status", ['status' => 'approved'])->assertOk();
        Mail::fake();   // the decision itself legitimately emails the applicant; only the FOLLOW-UP attempt must send nothing
        $this->follow($app)->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_identical_sends_are_deduplicated_by_key_at_the_service_level(): void
    {
        Mail::fake();
        $app = $this->incomplete();
        $svc = app(RegistrationCommunicationService::class);

        $a = $svc->sendTransactional($app, $this->member, 'status_notice', 'Subject', 'Body', 'status:1:1');
        $b = $svc->sendTransactional($app, $this->member, 'status_notice', 'Subject', 'Body', 'status:1:1');

        $this->assertSame($a->id, $b->id);
        Mail::assertSentCount(1);
        $this->assertSame(1, RegistrationCommunication::count());
    }

    public function test_a_delivery_failure_is_recorded_not_hidden_and_does_not_consume_the_follow_up_allowance(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP connection refused'));
        $app = $this->incomplete();

        $this->follow($app)->assertStatus(422)->assertJsonFragment(['message' => 'The message could not be delivered. It has been logged as failed; check the mail configuration or try again later.']);

        $comm = RegistrationCommunication::where('application_id', $app->id)->first();
        $this->assertSame('failed', $comm->status);
        $this->assertStringContainsString('SMTP connection refused', $comm->error);
        $app->refresh();
        $this->assertSame(0, $app->followup_count);
        $this->assertNull($app->last_followup_at);
        $this->assertDatabaseHas('registration_events', ['application_id' => $app->id, 'event_type' => 'communication_failed']);
    }

    public function test_the_email_renders_staff_text_escaped_never_as_html(): void
    {
        $html = (new RegistrationApplicantMail('Hello', "<script>alert(1)</script>\n<img src=x onerror=y>", 'follow_up', 'Ada'))->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_follow_up_message_validation(): void
    {
        $app = $this->incomplete();
        $this->follow($app, null, '')->assertStatus(422);
        $this->follow($app, null, 'hi')->assertStatus(422);
        $this->follow($app, null, str_repeat('x', 2001))->assertStatus(422);
    }
}
