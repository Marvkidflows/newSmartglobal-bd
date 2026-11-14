<?php
// LOCATION: tests/Feature/Registration/RegistrationTrackingTest.php
// Task 2 — registration tracking, consent, application lifecycle, team notifications and de-duplication.

namespace Tests\Feature\Registration;

use App\Models\RegistrationApplication;
use App\Models\RegistrationEvent;
use App\Models\RegistrationVisit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class RegistrationTrackingTest extends RegistrationTestCase
{
    private const TOKEN = 'abcdef1234567890-visitor-token';

    private function visit(bool $consent = true, string $token = self::TOKEN)
    {
        return $this->postJson('/api/public/registration-tracking/visit', ['visitor_token' => $token, 'consent' => $consent]);
    }

    public function test_nothing_is_stored_when_visit_tracking_is_disabled(): void
    {
        config(['registration.visit_tracking_enabled' => false]);
        $this->visit()->assertStatus(202)->assertJson(['tracked' => false]);
        $this->assertSame(0, RegistrationVisit::count());
        $this->assertSame(0, RegistrationEvent::count());
    }

    public function test_nothing_is_stored_without_consent(): void
    {
        $this->visit(false)->assertStatus(202)->assertJson(['tracked' => false]);
        $this->assertSame(0, RegistrationVisit::count());
        $this->assertSame(0, $this->member->notifications()->count());
    }

    public function test_the_notice_endpoint_reports_whether_tracking_is_enabled(): void
    {
        $this->getJson('/api/public/registration-tracking/notice')->assertOk()->assertJson(['enabled' => true]);
        config(['registration.visit_tracking_enabled' => false]);
        $this->getJson('/api/public/registration-tracking/notice')->assertOk()->assertJson(['enabled' => false]);
    }

    public function test_a_consented_visit_stores_only_a_hash_and_alerts_the_team_once(): void
    {
        $this->visit()->assertStatus(202)->assertJson(['tracked' => true]);
        $this->visit()->assertStatus(202);   // same visitor returns

        $this->assertSame(1, RegistrationVisit::count());
        $visit = RegistrationVisit::first();
        $this->assertSame(hash('sha256', self::TOKEN), $visit->token_hash);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($visit->getAttributes()));
        $this->assertSame(2, $visit->page_views);
        $this->assertSame(1, RegistrationEvent::where('event_type', 'page_opened')->count());

        // one alert per team member, never a duplicate for the returning visitor
        $this->assertSame(1, $this->member->notifications()->count());
        $this->assertSame(1, $this->lead->notifications()->count());
        // and no alert for staff outside the Registration Team
        $this->assertSame(0, $this->financial->notifications()->count());
        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_visitor_token_format_is_validated(): void
    {
        $this->postJson('/api/public/registration-tracking/visit', ['visitor_token' => 'short', 'consent' => true])->assertStatus(422);
        $this->postJson('/api/public/registration-tracking/visit', ['visitor_token' => str_repeat('<script>', 4), 'consent' => true])->assertStatus(422);
    }

    public function test_visit_alerts_are_capped_per_hour_so_bots_cannot_flood_the_team(): void
    {
        config(['registration.visit_alert_max_per_hour' => 2]);
        Cache::flush();
        foreach (range(1, 5) as $i) $this->visit(true, 'bot-visitor-token-' . str_pad((string) $i, 6, '0'));

        $this->assertSame(5, RegistrationVisit::count(), 'every consented visit is still recorded');
        $this->assertSame(2, $this->member->notifications()->count(), 'but only 2 alerts were sent this hour');
    }

    public function test_a_registration_attempt_creates_an_application_links_the_visit_and_alerts_once(): void
    {
        $this->visit();
        $u = $this->applicant();
        $app = $this->tracker()->attempt($u, self::TOKEN, false);

        $this->assertSame(RegistrationApplication::PENDING, $app->review_status);
        $this->assertNotNull($app->started_at);
        $this->assertSame(RegistrationVisit::first()->id, $app->visit_id);
        $this->assertSame($u->id, RegistrationVisit::first()->user_id);
        $this->assertNotNull(RegistrationVisit::first()->attempted_at);

        // resuming the same registration must not create a second application, a second start event, or a second alert
        $this->tracker()->attempt($u, self::TOKEN, true);
        $this->assertSame(1, RegistrationApplication::count());
        $this->assertSame(1, RegistrationEvent::where('event_type', 'application_started')->count());
        $this->assertSame(1, RegistrationEvent::where('event_type', 'application_resumed')->count());
        $this->assertSame(1, $this->member->notifications()->where('data->type', 'registration_started')->count());
    }

    public function test_a_visit_token_that_was_never_consented_cannot_be_linked(): void
    {
        $u = $this->applicant();
        $app = $this->tracker()->attempt($u, 'never-seen-token-0000000', false);
        $this->assertNull($app->visit_id);
        $this->assertSame(0, RegistrationVisit::count());
    }

    public function test_stage_endpoints_advance_the_application_and_stage_4_submits_it(): void
    {
        $u = $this->applicant(['email_verified_at' => now()]);
        $this->tracker()->attempt($u, null, false);

        $this->asUser($u)->postJson('/api/register/stage2', [
            'date_of_birth' => '1990-01-01', 'residential_address' => '1 Test Street', 'city' => 'Lagos',
        ])->assertOk();
        $this->asUser($u)->postJson('/api/register/stage3', [
            'employment_status' => 'employed', 'annual_income_range' => '10k-50k', 'source_of_funds' => 'salary',
            'investment_experience' => 'beginner', 'risk_tolerance' => 'low',
        ])->assertOk();

        $app = $u->registrationApplication()->first();
        $this->assertSame(3, (int) $app->stage_reached);
        $this->assertSame(RegistrationApplication::PENDING, $app->review_status, 'not submitted until the final stage');

        $this->asUser($u)->postJson('/api/register/stage4', [])->assertStatus(201);
        $app->refresh();
        $this->assertSame(4, (int) $app->stage_reached);
        $this->assertSame(RegistrationApplication::SUBMITTED, $app->review_status);
        $this->assertNotNull($app->submitted_at);
        $this->assertNotNull($app->details_completed_at);
        $this->assertSame(1, $this->lead->notifications()->where('data->type', 'registration_submitted')->count());

        // posting the final stage again must not re-submit or re-alert
        $this->asUser($u)->postJson('/api/register/stage4', []);
        $this->assertSame(1, RegistrationEvent::where('event_type', 'application_submitted')->count());
        $this->assertSame(1, $this->lead->notifications()->where('data->type', 'registration_submitted')->count());
    }

    public function test_tracking_failures_never_break_registration(): void
    {
        // No application row / no team: tracker errors are swallowed by the controller. Simulate by dropping the table the tracker writes to.
        $u = $this->applicant(['email_verified_at' => now()]);
        \Illuminate\Support\Facades\Schema::drop('registration_events');
        \Illuminate\Support\Facades\Schema::drop('registration_communications');
        \Illuminate\Support\Facades\Schema::drop('registration_applications');

        $this->asUser($u)->postJson('/api/register/stage2', [
            'date_of_birth' => '1990-01-01', 'residential_address' => '1 Test Street', 'city' => 'Lagos',
        ])->assertOk();
    }

    public function test_kyc_submission_returns_a_finished_application_to_submitted_and_alerts_once_per_submission(): void
    {
        $app = $this->submittedApplicant(false);
        $this->assertSame(RegistrationApplication::SUBMITTED, $app->review_status);

        $u = $app->user;
        $u->forceFill(['id_document_path' => 'https://example.test/a.jpg', 'selfie_path' => 'https://example.test/b.jpg'])->save();
        $this->tracker()->kycSubmitted($u);
        $app->refresh();
        $this->assertNotNull($app->kyc_submitted_at);
        $this->assertSame(1, $this->member->notifications()->where('data->type', 'registration_kyc_submitted')->count());

        $this->tracker()->kycSubmitted($u);   // a genuine resubmission is a new event → one more alert
        $this->assertSame(2, $this->member->notifications()->where('data->type', 'registration_kyc_submitted')->count());
    }

    public function test_incomplete_applications_are_flagged_to_the_team_once_and_never_emailed(): void
    {
        Mail::fake();
        $stale = $this->applicant();
        $fresh = $this->applicant();
        $this->tracker()->attempt($stale, null, false);
        $this->tracker()->attempt($fresh, null, false);
        RegistrationApplication::where('user_id', $stale->id)->update(['last_activity_at' => now()->subHours(30)]);

        $this->artisan('registration:maintain')->assertSuccessful();
        $this->artisan('registration:maintain')->assertSuccessful();   // second run must not alert again

        $alerts = $this->member->notifications()->where('data->type', 'registration_incomplete')->get();
        $this->assertCount(1, $alerts);
        $this->assertSame($stale->registrationApplication->id, $alerts->first()->data['application_id']);
        Mail::assertNothingSent();
    }

    public function test_maintain_prunes_only_old_anonymous_visits(): void
    {
        $old  = RegistrationVisit::create(['token_hash' => hash('sha256', 'old'), 'opened_at' => now()->subDays(120)]);
        $new  = RegistrationVisit::create(['token_hash' => hash('sha256', 'new'), 'opened_at' => now()->subDays(5)]);
        $kept = RegistrationVisit::create(['token_hash' => hash('sha256', 'linked'), 'opened_at' => now()->subDays(120), 'user_id' => $this->applicant()->id]);

        $this->artisan('registration:maintain')->assertSuccessful();

        $this->assertNull(RegistrationVisit::find($old->id));
        $this->assertNotNull(RegistrationVisit::find($new->id));
        $this->assertNotNull(RegistrationVisit::find($kept->id), 'a visit linked to an applicant is part of their record and is not pruned');
    }

    public function test_blocked_duplicate_attempts_are_collapsed_to_one_entry_per_hour(): void
    {
        $existing = $this->applicant(['registration_completed' => true, 'registration_stage' => 4]);
        foreach (range(1, 5) as $i) $this->tracker()->blockedDuplicate($existing);
        $this->assertSame(1, RegistrationEvent::where('event_type', 'attempt_blocked_duplicate')->count());
    }

    public function test_the_activity_record_is_append_only(): void
    {
        $event = RegistrationEvent::record('note_added', null, $this->member, null, null, 'x');
        $this->expectException(\LogicException::class);
        $event->update(['note' => 'rewritten']);
    }

    public function test_the_activity_record_cannot_be_deleted(): void
    {
        $event = RegistrationEvent::record('note_added', null, $this->member, null, null, 'x');
        $this->expectException(\LogicException::class);
        $event->delete();
    }
}
