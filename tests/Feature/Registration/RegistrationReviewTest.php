<?php
// LOCATION: tests/Feature/Registration/RegistrationReviewTest.php
// Task 2 — KYC review workflow: statuses, transitions, role-based approval, notes, information requests, history, applicant notices.

namespace Tests\Feature\Registration;

use App\Mail\RegistrationApplicantMail;
use App\Models\RegistrationApplication as A;
use App\Models\RegistrationEvent;
use Illuminate\Support\Facades\Mail;

class RegistrationReviewTest extends RegistrationTestCase
{
    private function changeStatus(A $app, $actor, string $to, ?string $note = null)
    {
        return $this->asUser($actor)->postJson("/api/registration/applications/{$app->id}/status", array_filter(['status' => $to, 'note' => $note], fn ($v) => $v !== null));
    }

    public function test_all_six_required_statuses_exist(): void
    {
        $this->assertEqualsCanonicalizing(['pending', 'submitted', 'under_review', 'approved', 'rejected', 'additional_info_required'], A::STATUSES);
    }

    public function test_a_member_can_start_review_and_it_assigns_them_and_stamps_the_time(): void
    {
        $app = $this->submittedApplicant();
        $this->changeStatus($app, $this->member, 'under_review')->assertOk()->assertJsonPath('application.status', 'under_review');

        $app->refresh();
        $this->assertSame($this->member->id, $app->assigned_to);
        $this->assertNotNull($app->review_started_at);
        $this->assertSame(1, RegistrationEvent::where('application_id', $app->id)->where('event_type', 'status_changed')->where('from_status', 'submitted')->where('to_status', 'under_review')->where('actor_id', $this->member->id)->count());
    }

    public function test_a_member_cannot_approve_or_reject_server_side(): void
    {
        $app = $this->submittedApplicant();
        $this->changeStatus($app, $this->member, 'approved')->assertStatus(403);
        $this->changeStatus($app, $this->member, 'rejected', 'Not valid')->assertStatus(403);

        $app->refresh();
        $this->assertSame(A::SUBMITTED, $app->review_status);
        $this->assertNotSame('approved', $app->user->fresh()->kyc_status);
    }

    public function test_the_service_itself_refuses_a_member_deciding_even_if_a_controller_were_bypassed(): void
    {
        $app = $this->submittedApplicant();
        $this->expectException(\App\Services\Registration\RegistrationRuleException::class);
        app(\App\Services\Registration\RegistrationReviewService::class)->transition($app, $this->member, 'approved');
    }

    public function test_a_lead_can_approve_and_it_syncs_the_existing_kyc_flags(): void
    {
        $app = $this->submittedApplicant();
        $this->changeStatus($app, $this->lead, 'under_review')->assertOk();
        $this->changeStatus($app, $this->lead, 'approved', 'Documents verified')->assertOk()->assertJsonPath('application.status', 'approved');

        $u = $app->user->fresh();
        $this->assertSame('approved', $u->kyc_status);
        $this->assertTrue((bool) $u->kyc_verified);
        $this->assertNotNull($u->kyc_verified_at);
        $app->refresh();
        $this->assertSame($this->lead->id, $app->decided_by);
        $this->assertNotNull($app->decided_at);
    }

    public function test_approval_is_refused_without_kyc_documents(): void
    {
        $app = $this->submittedApplicant(false);
        $this->changeStatus($app, $this->lead, 'under_review')->assertOk();
        $this->changeStatus($app, $this->lead, 'approved')->assertStatus(422);
        $this->assertSame(A::UNDER_REVIEW, $app->fresh()->review_status);
        $this->assertFalse((bool) $app->user->fresh()->kyc_verified);
    }

    public function test_rejection_requires_a_reason_and_records_it_on_the_application_and_the_kyc_record(): void
    {
        $app = $this->submittedApplicant();
        $this->changeStatus($app, $this->lead, 'rejected')->assertStatus(422);
        $this->assertSame(A::SUBMITTED, $app->fresh()->review_status);

        $this->changeStatus($app, $this->lead, 'rejected', 'ID photo is unreadable')->assertOk();
        $app->refresh();
        $this->assertSame(A::REJECTED, $app->review_status);
        $this->assertSame('ID photo is unreadable', $app->decision_reason);
        $u = $app->user->fresh();
        $this->assertSame('rejected', $u->kyc_status);
        $this->assertSame('ID photo is unreadable', $u->kyc_rejection_reason);
        $this->assertFalse((bool) $u->kyc_verified);
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $pending = $this->applicant();
        $this->tracker()->attempt($pending, null, false);
        $pendingApp = $pending->registrationApplication()->first();
        $this->changeStatus($pendingApp, $this->lead, 'approved')->assertStatus(422);       // never submitted
        $this->changeStatus($pendingApp, $this->lead, 'under_review')->assertStatus(422);

        $app = $this->submittedApplicant();
        $this->changeStatus($app, $this->lead, 'approved')->assertOk();
        $this->changeStatus($app, $this->lead, 'under_review')->assertStatus(422);          // approved is final here
        $this->changeStatus($app, $this->lead, 'rejected', 'Changed mind')->assertStatus(422);
        $this->assertSame(A::APPROVED, $app->fresh()->review_status);
    }

    public function test_unknown_statuses_are_rejected_by_validation(): void
    {
        $app = $this->submittedApplicant();
        $this->changeStatus($app, $this->lead, 'verified')->assertStatus(422);
    }

    public function test_request_additional_information_changes_status_notifies_and_emails_the_applicant(): void
    {
        Mail::fake();
        $app = $this->submittedApplicant();

        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/request-info", ['message' => 'Please upload a clearer photo of the back of your ID.'])
            ->assertOk()->assertJsonPath('application.status', 'additional_info_required');

        $app->refresh();
        $this->assertSame(A::ADDITIONAL_INFO_REQUIRED, $app->review_status);
        $this->assertStringContainsString('clearer photo', $app->info_request);

        Mail::assertSent(RegistrationApplicantMail::class, fn ($m) => $m->hasTo($app->user->email) && $m->kind === 'info_request');
        $this->assertSame(1, $app->user->notifications()->count(), 'in-app notice for the applicant');
        $this->assertDatabaseHas('registration_communications', ['application_id' => $app->id, 'kind' => 'info_request', 'status' => 'sent']);
    }

    public function test_request_information_requires_an_actual_message(): void
    {
        $app = $this->submittedApplicant();
        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/request-info", ['message' => ''])->assertStatus(422);
        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/request-info", ['message' => 'ok'])->assertStatus(422);   // min 5
        $this->assertSame(A::SUBMITTED, $app->fresh()->review_status);
    }

    public function test_resubmitting_kyc_after_an_information_request_puts_it_back_in_the_review_queue(): void
    {
        Mail::fake();
        $app = $this->submittedApplicant();
        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/request-info", ['message' => 'Need the back of the ID'])->assertOk();

        $this->tracker()->kycSubmitted($app->user->fresh());
        $app->refresh();
        $this->assertSame(A::SUBMITTED, $app->review_status);
        $this->assertSame(1, RegistrationEvent::where('application_id', $app->id)->where('event_type', 'kyc_submitted')->where('from_status', 'additional_info_required')->where('to_status', 'submitted')->count());
    }

    public function test_a_rejected_application_can_be_reopened_by_the_team_and_clears_the_kyc_rejection(): void
    {
        $app = $this->submittedApplicant();
        $this->changeStatus($app, $this->lead, 'rejected', 'Blurry')->assertOk();
        $this->changeStatus($app, $this->member, 'under_review')->assertOk();

        $this->assertSame(A::UNDER_REVIEW, $app->fresh()->review_status);
        $u = $app->user->fresh();
        $this->assertSame('pending', $u->kyc_status);
        $this->assertNull($u->kyc_rejection_reason);
    }

    public function test_internal_notes_are_recorded_in_history_and_never_emailed(): void
    {
        Mail::fake();
        $app = $this->submittedApplicant();
        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/notes", ['note' => 'Name on ID differs slightly'])->assertOk();
        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/notes", ['note' => '   '])->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertDatabaseHas('registration_events', ['application_id' => $app->id, 'event_type' => 'note_added', 'note' => 'Name on ID differs slightly', 'actor_id' => $this->member->id]);
    }

    public function test_review_history_shows_who_did_what_in_order(): void
    {
        $app = $this->submittedApplicant();
        $this->changeStatus($app, $this->member, 'under_review');
        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/notes", ['note' => 'Checked ID']);
        $this->changeStatus($app, $this->lead, 'approved', 'All good');

        $res = $this->asUser($this->lead)->getJson("/api/registration/applications/{$app->id}")->assertOk();
        $types = collect($res->json('timeline'))->pluck('type');
        foreach (['application_started', 'application_submitted', 'kyc_submitted', 'status_changed', 'note_added'] as $t) {
            $this->assertTrue($types->contains($t), "timeline is missing {$t}");
        }
        $changes = collect($res->json('timeline'))->where('type', 'status_changed')->values();
        $this->assertSame('approved', $changes->first()['to_status'], 'newest first');
        $this->assertSame($this->lead->id, $changes->first()['actor']['id']);
    }

    public function test_viewing_identity_documents_is_audited_once_per_reviewer_per_window(): void
    {
        $app = $this->submittedApplicant();
        $this->asUser($this->member)->getJson("/api/registration/applications/{$app->id}")->assertOk();
        $this->asUser($this->member)->getJson("/api/registration/applications/{$app->id}")->assertOk();
        $this->asUser($this->lead)->getJson("/api/registration/applications/{$app->id}")->assertOk();

        $this->assertSame(2, RegistrationEvent::where('application_id', $app->id)->where('event_type', 'kyc_viewed')->count());
    }

    public function test_the_detail_view_shows_submitted_kyc_and_the_actions_this_role_may_take(): void
    {
        $app = $this->submittedApplicant();

        $m = $this->asUser($this->member)->getJson("/api/registration/applications/{$app->id}")->assertOk();
        $m->assertJsonPath('application.kyc.id_document_url', 'https://example.test/id.jpg')
          ->assertJsonPath('application.kyc.id_type', 'passport')
          ->assertJsonPath('application.permissions.can_decide', false);
        $this->assertNotContains('approved', $m->json('application.permissions.allowed_transitions'));
        $this->assertNotContains('rejected', $m->json('application.permissions.allowed_transitions'));

        $l = $this->asUser($this->lead)->getJson("/api/registration/applications/{$app->id}")->assertOk();
        $l->assertJsonPath('application.permissions.can_decide', true);
        $this->assertContains('approved', $l->json('application.permissions.allowed_transitions'));
    }

    public function test_assignment_rules(): void
    {
        $app = $this->submittedApplicant();

        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/assign", ['assignee_id' => $this->member->id])->assertOk();   // claim
        $this->assertSame($this->member->id, $app->fresh()->assigned_to);

        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/assign", ['assignee_id' => $this->lead->id])->assertStatus(422);   // member cannot assign others
        $this->asUser($this->lead)->postJson("/api/registration/applications/{$app->id}/assign", ['assignee_id' => $this->lead->id])->assertOk();
        $this->asUser($this->lead)->postJson("/api/registration/applications/{$app->id}/assign", ['assignee_id' => $this->financial->id])->assertStatus(422);   // only registration staff
        $this->asUser($this->lead)->postJson("/api/registration/applications/{$app->id}/assign", ['assignee_id' => null])->assertOk();
        $this->assertNull($app->fresh()->assigned_to);
    }

    public function test_admin_kyc_decision_through_the_existing_admin_routes_keeps_the_application_consistent(): void
    {
        $app = $this->submittedApplicant();
        $this->asUser($this->admin)->postJson("/api/admin/kyc/{$app->user_id}/approve")->assertOk();
        $this->assertSame(A::APPROVED, $app->fresh()->review_status);

        $app2 = $this->submittedApplicant();
        $this->asUser($this->admin)->postJson("/api/admin/kyc/{$app2->user_id}/reject", ['reason' => 'Fake document'])->assertOk();
        $app2->refresh();
        $this->assertSame(A::REJECTED, $app2->review_status);
        $this->assertSame('Fake document', $app2->decision_reason);
    }

    public function test_a_failed_decision_email_never_undoes_the_committed_decision(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $app = $this->submittedApplicant();

        $this->changeStatus($app, $this->lead, 'approved')->assertOk();
        $this->assertSame(A::APPROVED, $app->fresh()->review_status);
        $this->assertDatabaseHas('registration_communications', ['application_id' => $app->id, 'status' => 'failed']);
        $this->assertDatabaseHas('registration_events', ['application_id' => $app->id, 'event_type' => 'communication_failed']);
    }
}
