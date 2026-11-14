<?php
// LOCATION: tests/Feature/Registration/RegistrationDashboardTest.php
// Task 2 — monitoring dashboard, filters/search/pagination, date reporting, activity record, backfill.

namespace Tests\Feature\Registration;

use App\Models\RegistrationApplication as A;
use App\Models\RegistrationEvent;
use App\Models\RegistrationVisit;
use App\Models\User;

class RegistrationDashboardTest extends RegistrationTestCase
{
    private function dashboard(array $q = [])
    {
        return $this->asUser($this->member)->getJson('/api/registration/dashboard?' . http_build_query($q));
    }

    private function list(array $q = [])
    {
        return $this->asUser($this->member)->getJson('/api/registration/applications?' . http_build_query($q));
    }

    public function test_dashboard_reports_every_status_and_the_review_queue(): void
    {
        $this->submittedApplicant();                                   // submitted
        $b = $this->submittedApplicant();                              // → under review
        $this->asUser($this->member)->postJson("/api/registration/applications/{$b->id}/status", ['status' => 'under_review']);
        $c = $this->submittedApplicant();                              // → additional info
        $this->asUser($this->member)->postJson("/api/registration/applications/{$c->id}/request-info", ['message' => 'Need more documents']);
        $d = $this->submittedApplicant();                              // → approved
        $this->asUser($this->lead)->postJson("/api/registration/applications/{$d->id}/status", ['status' => 'approved']);
        $e = $this->submittedApplicant();                              // → rejected
        $this->asUser($this->lead)->postJson("/api/registration/applications/{$e->id}/status", ['status' => 'rejected', 'note' => 'Invalid ID']);
        $p = $this->applicant(); $this->tracker()->attempt($p, null, false);   // pending

        $r = $this->dashboard()->assertOk();
        $s = $r->json('current.statuses');
        $this->assertSame(1, $s['pending']['count']);
        $this->assertSame(1, $s['submitted']['count']);
        $this->assertSame(1, $s['under_review']['count']);
        $this->assertSame(1, $s['additional_info_required']['count']);
        $this->assertSame(1, $s['approved']['count']);
        $this->assertSame(1, $s['rejected']['count']);
        $this->assertSame('Additional Information Required', $s['additional_info_required']['label']);
        $this->assertSame(1, $r->json('current.awaiting_review'));
        $this->assertSame(1, $r->json('current.incomplete'));
        $this->assertSame(6, $r->json('current.total'));
        $this->assertSame(6, $r->json('period.applications_started'));
        $this->assertSame(5, $r->json('period.submitted'));
        $this->assertSame(1, $r->json('period.approved'));
        $this->assertSame(1, $r->json('period.rejected'));
    }

    public function test_dashboard_counts_visitors_and_attempts_and_says_when_tracking_is_off(): void
    {
        RegistrationVisit::create(['token_hash' => hash('sha256', 'v1'), 'opened_at' => now()]);
        RegistrationVisit::create(['token_hash' => hash('sha256', 'v2'), 'opened_at' => now()]);
        $this->tracker()->attempt($this->applicant(), null, false);

        $r = $this->dashboard()->assertOk();
        $this->assertSame(2, $r->json('period.visitors'));
        $this->assertSame(1, $r->json('period.attempts'));
        $this->assertTrue($r->json('visit_tracking_enabled'));

        config(['registration.visit_tracking_enabled' => false]);
        $this->assertFalse($this->dashboard()->json('visit_tracking_enabled'));
    }

    public function test_dashboard_date_range_filters_and_the_daily_series(): void
    {
        $old = $this->submittedApplicant();
        A::whereKey($old->id)->update(['started_at' => now()->subDays(20), 'submitted_at' => now()->subDays(20)]);
        $this->submittedApplicant();

        $week = $this->dashboard(['from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString()])->assertOk();
        $this->assertSame(1, $week->json('period.applications_started'));
        $this->assertCount(7, $week->json('daily'));
        $this->assertSame(1, collect($week->json('daily'))->firstWhere('date', now()->toDateString())['started']);

        $month = $this->dashboard(['from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString()]);
        $this->assertSame(2, $month->json('period.applications_started'));

        $this->dashboard(['from' => '2026-01-01', 'to' => '2024-01-01'])->assertStatus(422);
        $this->dashboard(['from' => '2020-01-01', 'to' => '2026-01-01'])->assertStatus(422);   // over one year
    }

    public function test_list_filters_by_status_search_and_flags(): void
    {
        $ada  = $this->submittedApplicant(true, ['name' => 'Adaeze Okafor', 'email' => 'adaeze@example.test']);
        $bola = $this->submittedApplicant(false, ['name' => 'Bola Ade', 'email' => 'bola@example.test']);
        $this->asUser($this->lead)->postJson("/api/registration/applications/{$ada->id}/status", ['status' => 'approved']);
        $inc = $this->applicant(['name' => 'Chidi Eze', 'email' => 'chidi@example.test']);
        $this->tracker()->attempt($inc, null, false);

        $this->assertSame(['Adaeze Okafor'], collect($this->list(['status' => 'approved'])->json('data'))->pluck('applicant.name')->all());
        $this->assertCount(2, $this->list(['status' => 'approved,submitted'])->json('data'));
        $this->assertSame(['Bola Ade'], collect($this->list(['search' => 'bola@example'])->json('data'))->pluck('applicant.name')->all());
        $this->assertSame(['Chidi Eze'], collect($this->list(['incomplete' => 1])->json('data'))->pluck('applicant.name')->all());
        $this->assertSame(['Bola Ade'], collect($this->list(['kyc_docs' => 'no', 'status' => 'submitted'])->json('data'))->pluck('applicant.name')->all());
        $this->assertSame(['Adaeze Okafor'], collect($this->list(['kyc_docs' => 'yes'])->json('data'))->pluck('applicant.name')->all());
        $this->assertCount(0, $this->list(['status' => 'bogus'])->json('data'), 'unknown statuses match nothing instead of everything');
    }

    public function test_list_overdue_follow_up_flag_and_assignment_filters(): void
    {
        $stale = $this->applicant(); $this->tracker()->attempt($stale, null, false);
        $fresh = $this->applicant(); $this->tracker()->attempt($fresh, null, false);
        A::where('user_id', $stale->id)->update(['last_activity_at' => now()->subHours(30)]);

        $rows = $this->list(['overdue' => 1])->json('data');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['overdue_follow_up']);

        $sub = $this->submittedApplicant();
        $this->asUser($this->member)->postJson("/api/registration/applications/{$sub->id}/assign", ['assignee_id' => $this->member->id]);
        $this->assertCount(1, $this->list(['assigned' => 'me'])->json('data'));
        $this->assertTrue(collect($this->list(['assigned' => 'unassigned'])->json('data'))->every(fn ($r) => $r['assigned_to'] === null));
    }

    public function test_list_is_paginated_and_sorted(): void
    {
        foreach (range(1, 5) as $i) $this->submittedApplicant();
        $r = $this->list(['per_page' => 2, 'page' => 2])->assertOk();
        $this->assertCount(2, $r->json('data'));
        $this->assertSame(5, $r->json('meta.total'));
        $this->assertSame(3, $r->json('meta.last_page'));
        $this->list(['per_page' => 500])->assertStatus(422);
        $this->list(['sort' => 'password'])->assertStatus(422);   // sort columns are an allowlist
    }

    public function test_search_input_is_not_an_injection_vector(): void
    {
        $this->submittedApplicant();
        $this->list(['search' => "' OR 1=1 --"])->assertOk();
        $this->assertCount(1, $this->list(['search' => '%'])->json('data'), 'wildcard characters are stripped, so "%" is an empty search and returns the normal list');
    }

    public function test_activity_record_is_filterable_and_paginated(): void
    {
        $app = $this->submittedApplicant();
        $this->asUser($this->member)->postJson("/api/registration/applications/{$app->id}/notes", ['note' => 'Looks fine']);

        $r = $this->asUser($this->member)->getJson('/api/registration/activity?type=note_added')->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertSame('Looks fine', $r->json('data.0.note'));
        $this->assertSame($this->member->id, $r->json('data.0.actor.id'));

        $all = $this->asUser($this->member)->getJson("/api/registration/activity?application_id={$app->id}&per_page=2")->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertGreaterThan(2, $all->json('meta.total'));
    }

    public function test_team_picker_lists_only_active_registration_staff(): void
    {
        User::factory()->create(['role' => 'registration_member', 'status' => 'suspended', 'name' => 'Sam Suspended']);
        $names = collect($this->asUser($this->member)->getJson('/api/registration/team')->assertOk()->json('team'))->pluck('name');
        $this->assertTrue($names->contains('Rhea Member'));
        $this->assertTrue($names->contains('Leo Lead'));
        $this->assertFalse($names->contains('Sam Suspended'));
        $this->assertFalse($names->contains('Fay Fin'));
        $this->assertFalse($names->contains('Ada Admin'));
    }

    public function test_the_team_gets_a_notification_it_can_read_and_it_is_theirs_alone(): void
    {
        $this->tracker()->attempt($this->applicant(), null, false);

        $mine = $this->asUser($this->member)->getJson('/api/registration/notifications')->assertOk();
        $this->assertSame(1, $mine->json('unread_count'));
        $this->assertSame('Registration started', $mine->json('notifications.0.title'));
        $this->assertStringNotContainsString('@', $mine->getContent(), 'alerts carry no applicant contact details');

        $id = $mine->json('notifications.0.id');
        $this->asUser($this->member)->postJson("/api/registration/notifications/{$id}/read")->assertOk();
        $this->asUser($this->financial)->getJson('/api/registration/notifications')->assertStatus(403);
    }

    public function test_backfill_creates_missing_applications_without_inventing_data_and_is_idempotent(): void
    {
        $pending  = $this->applicant(['registration_completed' => false]);
        $approved = $this->applicant(['registration_completed' => true, 'registration_stage' => 4, 'id_document_path' => 'x', 'kyc_status' => 'approved', 'kyc_verified' => true, 'kyc_verified_at' => now()->subDay()]);
        $rejected = $this->applicant(['registration_completed' => true, 'registration_stage' => 4, 'id_document_path' => 'x', 'kyc_status' => 'rejected', 'kyc_rejection_reason' => 'Blurry']);
        $waiting  = $this->applicant(['registration_completed' => true, 'registration_stage' => 4, 'id_document_path' => 'x', 'kyc_status' => 'pending']);

        $this->artisan('registration:backfill', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, A::count(), 'dry run writes nothing');

        $this->artisan('registration:backfill')->assertSuccessful();
        $this->artisan('registration:backfill')->assertSuccessful();   // second run adds nothing
        $this->assertSame(4, A::count());

        $by = fn (User $u) => A::where('user_id', $u->id)->first();
        $this->assertSame('pending', $by($pending)->review_status);
        $this->assertSame('approved', $by($approved)->review_status);
        $this->assertSame('rejected', $by($rejected)->review_status);
        $this->assertSame('Blurry', $by($rejected)->decision_reason);
        $this->assertSame('submitted', $by($waiting)->review_status);
        $this->assertTrue($by($waiting)->backfilled);
        $this->assertNull($by($waiting)->submitted_at, 'a submission time is not invented');
        $this->assertSame(0, $this->member->notifications()->count(), 'backfill sends no notifications');
        $this->assertSame(4, RegistrationEvent::where('event_type', 'backfilled')->count());
    }
}
