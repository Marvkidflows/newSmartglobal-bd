<?php
// LOCATION: tests/Feature/Rank/LendingLockTest.php
//
// Board decision §6: the Diamond/Elite lending programme is NOT authorised for production. The hard lock (config lending.enabled,
// default false) must refuse every lending path on the server and keep loan content out of investor-facing rank presentation,
// even when the database says the programme is authorised and active.
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php artisan test --filter=LendingLockTest

namespace Tests\Feature\Rank;

use App\Models\LoanApplication;
use App\Models\LoanProgrammeSetting;
use App\Models\User;
use App\Services\Rank\RankBenefitService;

class LendingLockTest extends RankTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(RankBenefitService::class)->syncDefaults();
    }

    private function ranked(string $key): User
    {
        $u = $this->investor();
        $u->forceFill(['investor_rank_id' => $this->rank($key)->id, 'rank_status' => 'active'])->save();
        return $u->fresh();
    }

    /** Simulate a database that says "authorised and open" — the lock must still win. */
    private function dbSaysOpen(): void
    {
        LoanProgrammeSetting::current()->forceFill(['is_authorized' => true, 'is_active' => true, 'policy_reference' => 'LEND-POL-TEST-1'])->save();
    }

    public function test_the_lock_is_on_by_default(): void
    {
        $this->assertFalse((bool) config('lending.enabled'));
        $this->assertSame(false, (bool) config('lending.enabled'), 'the shipped default must be locked');
    }

    public function test_an_investor_cannot_apply_even_if_the_database_says_the_programme_is_open(): void
    {
        $this->dbSaysOpen();
        $u = $this->ranked('elite');

        $this->actingAs($u, 'sanctum')->postJson('/api/investor-investment/loans', ['requested_amount' => 1000])->assertStatus(403);
        $this->assertSame(0, LoanApplication::count());
    }

    public function test_the_investor_loan_state_shows_nothing_about_lending_while_locked(): void
    {
        $this->dbSaysOpen();
        $r = $this->actingAs($this->ranked('diamond'), 'sanctum')->getJson('/api/investor-investment/loans')->assertOk()->json();

        $this->assertFalse($r['visible']);
        $this->assertTrue($r['locked']);
        $this->assertFalse($r['eligible']);
        $this->assertSame([], $r['applications']);
        $this->assertNull($r['disclaimer']);
    }

    public function test_an_admin_cannot_configure_authorise_or_activate_the_programme_while_locked(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->putJson('/api/admin/ranks/loans/settings', ['policy_reference' => 'LEND-POL-2026-01', 'is_authorized' => true, 'reason' => 'Attempt to authorise'])->assertStatus(403);
        $this->putJson('/api/admin/ranks/loans/settings', ['is_active' => true, 'reason' => 'Attempt to open'])->assertStatus(403);

        $s = LoanProgrammeSetting::current();
        $this->assertFalse($s->is_authorized);
        $this->assertFalse($s->is_active);
    }

    public function test_an_admin_cannot_review_approve_or_reject_while_locked(): void
    {
        $this->dbSaysOpen();
        $u = $this->ranked('diamond');
        $a = LoanApplication::create(['reference' => 'LN-LOCKTEST', 'user_id' => $u->id, 'rank_id_at_application' => $this->rank('diamond')->id, 'requested_amount' => 500, 'status' => 'submitted']);

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/ranks/loans/applications/{$a->id}/review", [])->assertStatus(403);
        $this->postJson("/api/admin/ranks/loans/applications/{$a->id}/approve", ['approved_terms' => 'Anything at all here', 'reason' => 'Nope'])->assertStatus(403);
        $this->postJson("/api/admin/ranks/loans/applications/{$a->id}/reject", ['reason' => 'Nope'])->assertStatus(403);
        $this->assertSame('submitted', $a->fresh()->status);
    }

    public function test_an_investor_cannot_withdraw_an_application_while_locked(): void
    {
        $u = $this->ranked('diamond');
        $a = LoanApplication::create(['reference' => 'LN-LOCKTEST2', 'user_id' => $u->id, 'rank_id_at_application' => $this->rank('diamond')->id, 'requested_amount' => 500, 'status' => 'submitted']);
        $this->actingAs($u, 'sanctum')->postJson("/api/investor-investment/loans/{$a->id}/withdraw")->assertStatus(403);
        $this->assertSame('submitted', $a->fresh()->status);
    }

    public function test_loan_benefits_and_the_loan_disclaimer_are_removed_from_the_investor_rank_view_while_locked(): void
    {
        $r = $this->actingAs($this->ranked('elite'), 'sanctum')->getJson('/api/investor-investment/rank/benefits')->assertOk()->json();

        foreach ($r['ranks'] as $rank) {
            foreach ($rank['benefits'] as $b) {
                $this->assertNotSame('loan', $b['category'], "{$b['key']} is a loan benefit and must not be shown");
            }
        }
        $this->assertNull($r['loan_disclaimer']);
        $this->assertStringNotContainsStringIgnoringCase('loan', json_encode($r['ranks']));
    }

    public function test_the_catalogue_rows_are_kept_for_admins_and_audit_so_nothing_is_deleted(): void
    {
        $this->assertGreaterThan(0, \App\Models\RankBenefit::where('category', 'loan')->count());
        $rows = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks/benefits')->assertOk()->json('ranks'))
            ->flatMap(fn ($r) => $r['benefits'])->where('category', 'loan');
        $this->assertGreaterThan(0, $rows->count());
    }

    public function test_the_admin_settings_view_reports_that_lending_is_locked(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks/loans/settings')->assertOk()->assertJsonPath('settings.locked', true);
    }

    public function test_when_the_environment_is_explicitly_unlocked_the_database_gates_still_apply(): void
    {
        config(['lending.enabled' => true]);   // only the Board's separate written authorisation would ever allow this
        $u = $this->ranked('elite');
        // programme still not authorised in the database → still no application
        $this->actingAs($u, 'sanctum')->postJson('/api/investor-investment/loans', ['requested_amount' => 1000])->assertStatus(422);
    }
}
