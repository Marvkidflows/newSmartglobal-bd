<?php
// LOCATION: tests/Feature/Rank/RankBenefitsFrameworkTest.php
//
// Benefits & privileges framework: catalogue population, investor display, consent, effective dates, suspension without
// deletion, the approval controls for benefits / incentive terms / the lending programme, loan application tracking,
// permissions, audit, and the visibility of the official requirements of all six ranks.
//
// STATUS: written against the existing helpers in RankTestCase. NOT EXECUTED by its author (no PHP in the authoring
// sandbox). Run:  php artisan test --filter=RankBenefitsFrameworkTest

namespace Tests\Feature\Rank;

use App\Models\InvestorConsentLog;
use App\Models\LoanApplication;
use App\Models\LoanProgrammeSetting;
use App\Models\RankBenefit;
use App\Models\RankBenefitAudit;
use App\Models\RankIncentiveTerm;
use App\Models\User;
use App\Services\Rank\BenefitDefaults;
use App\Services\Rank\RankAuditService;
use App\Services\Rank\RankBenefitService;
use Illuminate\Support\Facades\DB;
use LogicException;

class RankBenefitsFrameworkTest extends RankTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // These tests exercise the loan WORKFLOW itself, so they opt in to the unlocked state. The hard lock (the real default) is
        // covered by LendingLockTest.
        config(['lending.enabled' => true]);
        app(RankBenefitService::class)->syncDefaults();
    }

    private function asAdmin(?User $u = null): static
    {
        return $this->actingAs($u ?? $this->admin, 'sanctum');
    }

    private function ranked(string $rankKey, array $attrs = []): User
    {
        $u = $this->investor($attrs);
        $u->forceFill(['investor_rank_id' => $this->rank($rankKey)->id, 'rank_status' => 'active'])->save();
        return $u->fresh();
    }

    private function benefit(string $key): RankBenefit
    {
        return RankBenefit::where('key', $key)->firstOrFail();
    }

    private function investorView(User $u): array
    {
        return $this->actingAs($u, 'sanctum')->getJson('/api/investor-investment/rank/benefits')->assertOk()->json();
    }

    private function viewBenefit(array $view, string $rankKey, string $benefitKey): ?array
    {
        $rank = collect($view['ranks'])->firstWhere('key', $rankKey);
        return collect($rank['benefits'])->firstWhere('key', "$rankKey.$benefitKey");
    }

    private function secondAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    // ── Catalogue population ────────────────────────────────────────────────────────────────────────────

    public function test_every_one_of_the_six_ranks_has_benefits_and_a_badge_row_after_sync(): void
    {
        foreach (['bronze', 'silver', 'gold', 'platinum', 'diamond', 'elite'] as $key) {
            $this->assertGreaterThan(3, RankBenefit::where('investor_rank_id', $this->rank($key)->id)->count(), "$key has too few benefits");
            $this->assertTrue(RankBenefit::where('key', "$key.badge")->exists(), "$key has no badge benefit");
        }
    }

    public function test_sync_is_idempotent_and_never_overwrites_a_management_edit(): void
    {
        $before = RankBenefit::count();
        $this->benefit('gold.briefings')->update(['title' => 'Edited by management']);

        $again = app(RankBenefitService::class)->syncDefaults();

        $this->assertSame(0, $again['created']);
        $this->assertSame($before, RankBenefit::count());
        $this->assertSame('Edited by management', $this->benefit('gold.briefings')->title);
    }

    public function test_the_sync_command_is_safe_to_rerun(): void
    {
        $before = RankBenefit::count();
        $this->artisan('ranks:sync-benefits')->assertSuccessful();
        $this->assertSame($before, RankBenefit::count());
    }

    public function test_no_financial_benefit_starts_active_and_no_percentage_or_rate_is_invented(): void
    {
        $this->assertSame(0, RankBenefit::where('is_financial', true)->where('status', '!=', 'pending_approval')->count());
        $this->assertSame(5, RankBenefit::where('is_financial', true)->count());   // Silver … Elite; Bronze has no incentive
        $this->assertSame(0, RankIncentiveTerm::count(), 'no incentive value may ever be seeded');

        foreach (BenefitDefaults::all() as $d) {
            $this->assertDoesNotMatchRegularExpression('/\d\s?%/', $d['title'] . ' ' . $d['description'], "{$d['key']} states a percentage");
            $this->assertDoesNotMatchRegularExpression('/\$\s?\d/', $d['description'], "{$d['key']} states an amount");
        }
    }

    public function test_only_diamond_and_elite_have_loan_benefits_and_nothing_loan_related_is_active(): void
    {
        $this->assertEqualsCanonicalizing(['diamond', 'elite'], RankBenefit::where('category', 'loan')->with('rank')->get()->pluck('rank.key')->unique()->values()->all());
        $this->assertSame(0, RankBenefit::where('category', 'loan')->where('status', 'active')->count());
    }

    // ── Investor display ────────────────────────────────────────────────────────────────────────────────

    public function test_investor_sees_all_six_ranks_with_benefits_and_status_labels(): void
    {
        $view = $this->investorView($this->ranked('gold'));

        $this->assertCount(6, $view['ranks']);
        $this->assertSame('gold', $view['current_rank_key']);
        foreach ($view['ranks'] as $rank) {
            $this->assertNotEmpty($rank['benefits'], "{$rank['key']} shows no benefits");
            $this->assertNotEmpty($rank['roi_treatment']);
        }
        $this->assertSame('Pending approval', $this->viewBenefit($view, 'gold', 'support-escalation')['status_label']);
        $this->assertSame('Available and active', $this->viewBenefit($view, 'gold', 'badge')['status_label']);
    }

    public function test_benefits_apply_only_to_the_investors_own_rank_and_lower_cumulative_ones(): void
    {
        $view = $this->investorView($this->ranked('gold'));

        $this->assertTrue($this->viewBenefit($view, 'gold', 'badge')['applies_to_you']);
        $this->assertTrue($this->viewBenefit($view, 'bronze', 'dashboard-records')['applies_to_you'], 'a lower rank\'s cumulative benefit continues');
        $this->assertFalse($this->viewBenefit($view, 'bronze', 'badge')['applies_to_you'], 'a lower rank\'s badge is replaced by the current one');
        $this->assertFalse($this->viewBenefit($view, 'platinum', 'briefings')['applies_to_you']);
        $this->assertFalse($this->viewBenefit($view, 'elite', 'badge')['applies_to_you']);
    }

    public function test_an_unranked_or_suspended_investor_receives_no_rank_benefits(): void
    {
        $unranked = $this->investorView($this->investor());
        $this->assertNull($unranked['current_rank_key']);
        foreach ($unranked['ranks'] as $r) foreach ($r['benefits'] as $b) $this->assertFalse($b['applies_to_you']);

        $suspended = $this->ranked('silver');
        $suspended->forceFill(['rank_status' => 'suspended'])->save();
        $view = $this->investorView($suspended->fresh());
        $this->assertNull($view['current_rank_key']);
        foreach ($view['ranks'] as $r) foreach ($r['benefits'] as $b) $this->assertFalse($b['applies_to_you']);
    }

    public function test_investor_response_exposes_no_internal_approval_fields(): void
    {
        $json = json_encode($this->investorView($this->ranked('gold')));
        foreach (['approval_reference', 'approved_by', 'fulfilment', 'display_order', 'created_by'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_an_unpublished_benefit_is_hidden_from_investors_but_kept(): void
    {
        $b = $this->benefit('gold.briefings');
        $this->asAdmin()->putJson("/api/admin/ranks/benefits/{$b->id}", ['is_published' => false, 'reason' => 'Hide until wording is approved'])->assertOk();

        $this->assertNull($this->viewBenefit($this->investorView($this->ranked('gold')), 'gold', 'briefings'));
        $this->assertTrue(RankBenefit::where('key', 'gold.briefings')->exists());
    }

    public function test_rank_history_shows_dates_and_ranks_but_no_internal_reason(): void
    {
        $u = $this->ranked('gold');
        app(RankAuditService::class)->recordChange($u, null, $this->rank('gold')->id, 'manual_assignment', $this->admin, 'INTERNAL NOTE do not show');

        $view = $this->investorView($u);

        $this->assertCount(1, $view['history']);
        $this->assertSame('Rank assigned', $view['history'][0]['event']);
        $this->assertSame('gold', $view['history'][0]['new_rank']['key']);
        $this->assertStringNotContainsString('INTERNAL NOTE', json_encode($view));
    }

    // ── Official requirements are never hidden ──────────────────────────────────────────────────────────

    public function test_all_six_official_requirements_are_visible_even_when_no_rule_is_active(): void
    {
        $res = $this->actingAs($this->investor(), 'sanctum')->getJson('/api/investor-investment/rank/criteria')->assertOk()->json();

        $expected = ['bronze' => [100, 1], 'silver' => [1000, 2], 'gold' => [5000, 3], 'platinum' => [10000, 5], 'diamond' => [20000, 8], 'elite' => [50000, 12]];
        $this->assertCount(6, $res['ranks']);
        foreach ($res['ranks'] as $r) {
            $this->assertSame('awaiting_approval', $r['status'], 'status still tells the truth: not in force yet');
            $this->assertEquals($expected[$r['key']][0], $r['official_min_investment_usd']);
            $this->assertEquals($expected[$r['key']][1], $r['official_min_completed_cycles']);
        }
        $this->assertStringContainsString('BOTH', $res['both_required']);
    }

    // ── Consent ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_consent_is_off_by_default_and_recorded_append_only(): void
    {
        $u = $this->ranked('gold');
        $this->assertFalse($this->investorView($u)['consent']['recognition_communications']);

        $this->actingAs($u, 'sanctum')->putJson('/api/investor-investment/rank/consent', ['recognition_communications' => true])->assertOk()->assertJsonPath('consent.recognition_communications', true);
        $this->putJson('/api/investor-investment/rank/consent', ['recognition_communications' => true])->assertOk();   // repeat: no new row
        $this->putJson('/api/investor-investment/rank/consent', ['recognition_communications' => false])->assertOk();

        $rows = InvestorConsentLog::where('user_id', $u->id)->orderBy('id')->get();
        $this->assertSame([true, false], $rows->pluck('granted')->all());

        $this->expectException(LogicException::class);
        $rows->first()->update(['granted' => false]);
    }

    public function test_withdrawing_consent_to_a_non_consenting_investor_writes_nothing(): void
    {
        $u = $this->ranked('gold');
        $this->actingAs($u, 'sanctum')->putJson('/api/investor-investment/rank/consent', ['recognition_communications' => false])->assertOk();
        $this->assertSame(0, InvestorConsentLog::where('user_id', $u->id)->count());
    }

    public function test_the_recognition_benefit_reports_that_it_needs_consent(): void
    {
        $u = $this->ranked('gold');
        $this->assertStringContainsString('consent', $this->viewBenefit($this->investorView($u), 'gold', 'private-recognition')['note']);
    }

    // ── Effective dates and suspension without deletion ─────────────────────────────────────────────────

    public function test_a_future_start_date_or_a_past_end_date_makes_a_benefit_unavailable_to_investors(): void
    {
        $u = $this->ranked('gold');
        $b = $this->benefit('bronze.dashboard-records');

        $this->asAdmin()->putJson("/api/admin/ranks/benefits/{$b->id}", ['effective_from' => now()->addDays(10)->toDateString(), 'reason' => 'Starts next week'])->assertOk();
        $this->assertSame('suspended', $this->viewBenefit($this->investorView($u), 'bronze', 'dashboard-records')['status']);

        $this->asAdmin()->putJson("/api/admin/ranks/benefits/{$b->id}", ['effective_from' => now()->subDays(10)->toDateString(), 'effective_to' => now()->subDay()->toDateString(), 'reason' => 'Programme ended'])->assertOk();
        $this->assertSame('suspended', $this->viewBenefit($this->investorView($u), 'bronze', 'dashboard-records')['status']);

        $this->asAdmin()->putJson("/api/admin/ranks/benefits/{$b->id}", ['effective_to' => null, 'reason' => 'Extended indefinitely'])->assertOk();
        $this->assertSame('active', $this->viewBenefit($this->investorView($u), 'bronze', 'dashboard-records')['status']);
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $b = $this->benefit('gold.briefings');
        $this->asAdmin()->putJson("/api/admin/ranks/benefits/{$b->id}", ['effective_from' => '2027-02-01', 'effective_to' => '2027-01-01', 'reason' => 'Backwards dates'])->assertStatus(422);
    }

    public function test_suspending_a_benefit_keeps_the_record_and_its_history(): void
    {
        $b = $this->benefit('bronze.dashboard-records');
        $this->asAdmin()->postJson("/api/admin/ranks/benefits/{$b->id}/status", ['status' => 'suspended', 'reason' => 'Maintenance window'])
            ->assertOk()->assertJsonPath('status', 'suspended');

        $this->assertSame('suspended', $b->fresh()->status);
        $this->assertTrue(RankBenefitAudit::where('subject_type', 'benefit')->where('subject_id', $b->id)->where('action', 'suspended')->exists());
        $this->assertSame('Suspended or unavailable', $this->viewBenefit($this->investorView($this->ranked('bronze')), 'bronze', 'dashboard-records')['status_label']);
    }

    public function test_a_benefit_can_never_be_deleted(): void
    {
        $this->expectException(LogicException::class);
        $this->benefit('gold.briefings')->delete();
    }

    public function test_the_audit_trail_is_append_only(): void
    {
        $row = RankBenefitAudit::firstOrFail();
        try { $row->update(['reason' => 'tampered']); $this->fail('audit row was editable'); } catch (LogicException) { $this->assertTrue(true); }
        try { $row->delete(); $this->fail('audit row was deletable'); } catch (LogicException) { $this->assertTrue(true); }
    }

    // ── Approval controls ───────────────────────────────────────────────────────────────────────────────

    public function test_switching_on_an_operational_benefit_needs_a_written_approval_reference(): void
    {
        $b = $this->benefit('gold.support-escalation');

        $this->asAdmin()->postJson("/api/admin/ranks/benefits/{$b->id}/status", ['status' => 'active', 'reason' => 'Management approved'])->assertStatus(422);
        $this->assertSame('pending_approval', $b->fresh()->status);

        $this->postJson("/api/admin/ranks/benefits/{$b->id}/status", ['status' => 'active', 'reason' => 'Management approved', 'approval_reference' => 'MGT-2026-014'])
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('approval_reference', 'MGT-2026-014');
        $this->assertSame($this->admin->id, $b->fresh()->approved_by);
    }

    public function test_a_financial_incentive_row_can_never_be_switched_on_through_the_benefit_screen(): void
    {
        $b = $this->benefit('diamond.rank-incentive');
        foreach (['active', 'subject_to_eligibility'] as $status) {
            $this->asAdmin()->postJson("/api/admin/ranks/benefits/{$b->id}/status", ['status' => $status, 'reason' => 'Try to force it', 'approval_reference' => 'MGT-2026-099'])->assertStatus(422);
        }
        $this->assertSame('pending_approval', $b->fresh()->status);
    }

    public function test_only_an_admin_can_change_benefits_and_others_get_403(): void
    {
        $b = $this->benefit('gold.briefings');
        $financial = User::factory()->create(['role' => 'financial', 'status' => 'active']);

        foreach ([$financial, $this->investor()] as $who) {
            $this->actingAs($who, 'sanctum');
            $this->putJson("/api/admin/ranks/benefits/{$b->id}", ['title' => 'Hacked title', 'reason' => 'Not allowed'])->assertStatus(403);
            $this->postJson("/api/admin/ranks/benefits/{$b->id}/status", ['status' => 'suspended', 'reason' => 'Not allowed'])->assertStatus(403);
        }
        $this->assertNotSame('Hacked title', $b->fresh()->title);
    }

    public function test_every_admin_change_requires_a_reason_and_is_audited(): void
    {
        $b = $this->benefit('gold.briefings');
        $this->asAdmin()->putJson("/api/admin/ranks/benefits/{$b->id}", ['title' => 'New wording for briefings'])->assertStatus(422);

        $this->putJson("/api/admin/ranks/benefits/{$b->id}", ['title' => 'New wording for briefings', 'reason' => 'Clarified wording'])->assertOk();
        $audit = RankBenefitAudit::where('subject_id', $b->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame('Clarified wording', $audit->reason);
        $this->assertSame('New wording for briefings', $audit->new['title']);
    }

    public function test_a_new_custom_benefit_starts_pending_and_cannot_start_active(): void
    {
        $res = $this->asAdmin()->postJson('/api/admin/ranks/benefits', [
            'investor_rank_id' => $this->rank('silver')->id, 'category' => 'service', 'title' => 'Welcome call',
            'description' => 'A welcome call from the investor desk.', 'is_financial' => true, 'reason' => 'Management request',
        ])->assertCreated();

        $res->assertJsonPath('status', 'pending_approval')->assertJsonPath('is_financial', true);
    }

    // ── Incentive terms (the configurable ROI engine) ───────────────────────────────────────────────────

    private function draftTerm(array $over = []): int
    {
        return $this->asAdmin()->postJson('/api/admin/ranks/incentives', array_merge([
            'label' => 'Gold loyalty bonus (test)', 'investor_rank_id' => $this->rank('gold')->id, 'kind' => 'percentage_bonus',
            'value' => 1.5, 'conditions' => 'Applies to completed cycles of the named plan only.', 'effective_from' => now()->addDay()->toDateString(),
            'reason' => 'Prepared for management review',
        ], $over))->assertCreated()->json('id');
    }

    public function test_a_term_cannot_be_submitted_without_a_value_dates_and_conditions(): void
    {
        $id = $this->draftTerm(['value' => null, 'conditions' => null, 'effective_from' => null]);
        $this->postJson("/api/admin/ranks/incentives/$id/submit", ['reason' => 'Ready for approval'])->assertStatus(422);
    }

    public function test_a_percentage_over_one_hundred_is_rejected(): void
    {
        $this->asAdmin()->postJson('/api/admin/ranks/incentives', [
            'label' => 'Impossible', 'investor_rank_id' => $this->rank('gold')->id, 'kind' => 'percentage_bonus', 'value' => 150, 'reason' => 'Test over 100',
        ])->assertStatus(422);
    }

    public function test_the_author_cannot_approve_their_own_incentive_term(): void
    {
        $id = $this->draftTerm();
        $this->postJson("/api/admin/ranks/incentives/$id/submit", ['reason' => 'Ready for approval'])->assertOk();

        $this->postJson("/api/admin/ranks/incentives/$id/approve", ['reason' => 'Self approval', 'approval_reference' => 'MGT-2026-050'])->assertStatus(422);
        $this->assertSame('pending_approval', RankIncentiveTerm::find($id)->status);
    }

    public function test_an_incentive_cannot_be_activated_while_the_master_switch_is_off_even_when_approved(): void
    {
        $id = $this->draftTerm();
        $this->postJson("/api/admin/ranks/incentives/$id/submit", ['reason' => 'Ready for approval'])->assertOk();
        $this->asAdmin($this->secondAdmin())->postJson("/api/admin/ranks/incentives/$id/approve", ['reason' => 'Approved in writing', 'approval_reference' => 'MGT-2026-050'])
            ->assertOk()->assertJsonPath('status', 'approved');

        config(['ranks.incentives_activation_enabled' => false]);
        $this->postJson("/api/admin/ranks/incentives/$id/activate", ['reason' => 'Go live'])->assertStatus(422);
        $this->assertSame('approved', RankIncentiveTerm::find($id)->status);
    }

    public function test_an_incentive_activates_only_after_a_different_person_approved_it_and_the_switch_is_on(): void
    {
        $id = $this->draftTerm();
        $this->postJson("/api/admin/ranks/incentives/$id/submit", ['reason' => 'Ready for approval'])->assertOk();
        $approver = $this->secondAdmin();

        // Not approved yet → cannot activate even with the switch on.
        config(['ranks.incentives_activation_enabled' => true]);
        $this->asAdmin($approver)->postJson("/api/admin/ranks/incentives/$id/activate", ['reason' => 'Go live'])->assertStatus(422);

        $this->postJson("/api/admin/ranks/incentives/$id/approve", ['reason' => 'Approved in writing', 'approval_reference' => 'MGT-2026-050'])->assertOk();
        $this->postJson("/api/admin/ranks/incentives/$id/activate", ['reason' => 'Go live'])->assertOk()->assertJsonPath('status', 'active');

        $this->assertSame(['created', 'submitted', 'approved', 'activated'], RankBenefitAudit::where('subject_type', 'incentive')->where('subject_id', $id)->orderBy('id')->pluck('action')->all());
    }

    public function test_editing_an_approved_term_returns_it_to_draft_and_clears_the_approval(): void
    {
        $id = $this->draftTerm();
        $this->postJson("/api/admin/ranks/incentives/$id/submit", ['reason' => 'Ready for approval'])->assertOk();
        $this->asAdmin($this->secondAdmin())->postJson("/api/admin/ranks/incentives/$id/approve", ['reason' => 'Approved in writing', 'approval_reference' => 'MGT-2026-050'])->assertOk();

        $this->putJson("/api/admin/ranks/incentives/$id", ['value' => 2.5, 'reason' => 'Changed after approval'])->assertOk()->assertJsonPath('status', 'draft');
        $t = RankIncentiveTerm::find($id);
        $this->assertNull($t->approval_reference);
        $this->assertNull($t->approved_by);
    }

    public function test_the_financial_team_cannot_approve_or_activate_incentives(): void
    {
        $id = $this->draftTerm();
        $this->actingAs(User::factory()->create(['role' => 'financial', 'status' => 'active']), 'sanctum');
        $this->postJson("/api/admin/ranks/incentives/$id/approve", ['reason' => 'Nope', 'approval_reference' => 'MGT-2026-050'])->assertStatus(403);
        $this->postJson("/api/admin/ranks/incentives/$id/activate", ['reason' => 'Nope'])->assertStatus(403);
    }

    // ── Lending pathway ─────────────────────────────────────────────────────────────────────────────────

    private function openProgramme(): void
    {
        $this->asAdmin()->putJson('/api/admin/ranks/loans/settings', ['policy_reference' => 'LEND-POL-2026-01', 'is_authorized' => true, 'reason' => 'Policy approved'])->assertOk();
        $this->putJson('/api/admin/ranks/loans/settings', ['is_active' => true, 'reason' => 'Opening to applications'])->assertOk();
    }

    public function test_the_lending_programme_is_closed_by_default_and_investors_cannot_apply(): void
    {
        $this->assertFalse(LoanProgrammeSetting::current()->is_authorized);
        $this->assertFalse(LoanProgrammeSetting::current()->is_active);

        $u = $this->ranked('elite');
        $state = $this->actingAs($u, 'sanctum')->getJson('/api/investor-investment/loans')->assertOk()->json();
        $this->assertFalse($state['visible']);
        $this->assertFalse($state['eligible']);

        $this->postJson('/api/investor-investment/loans', ['requested_amount' => 1000])->assertStatus(422);
        $this->assertSame(0, LoanApplication::count());
    }

    public function test_loan_benefits_read_as_pending_until_the_programme_is_open(): void
    {
        $view = $this->investorView($this->ranked('diamond'));
        $this->assertSame('pending_approval', $this->viewBenefit($view, 'diamond', 'loan-pathway')['status']);

        $this->openProgramme();
        $view = $this->investorView($this->ranked('diamond'));
        $this->assertSame('subject_to_eligibility', $this->viewBenefit($view, 'diamond', 'loan-pathway')['status']);
        $this->assertSame('pending_approval', $this->viewBenefit($view, 'diamond', 'loan-limits')['status'], 'limits still need their own approval');
    }

    public function test_the_programme_cannot_be_authorised_without_a_policy_reference_or_activated_before_authorisation(): void
    {
        $this->asAdmin()->putJson('/api/admin/ranks/loans/settings', ['is_authorized' => true, 'reason' => 'No reference given'])->assertStatus(422);
        $this->putJson('/api/admin/ranks/loans/settings', ['is_active' => true, 'reason' => 'Open without authorisation'])->assertStatus(422);
        $this->assertFalse(LoanProgrammeSetting::current()->isOpen());
    }

    public function test_withdrawing_authorisation_closes_the_programme(): void
    {
        $this->openProgramme();
        $this->asAdmin()->putJson('/api/admin/ranks/loans/settings', ['is_authorized' => false, 'reason' => 'Policy under review'])->assertOk();
        $this->assertFalse(LoanProgrammeSetting::current()->is_active);
    }

    public function test_only_diamond_and_elite_can_apply_once_open_and_only_one_application_at_a_time(): void
    {
        $this->openProgramme();

        $this->actingAs($this->ranked('gold'), 'sanctum')->postJson('/api/investor-investment/loans', ['requested_amount' => 500])->assertStatus(422);

        $d = $this->ranked('diamond');
        $this->actingAs($d, 'sanctum')->getJson('/api/investor-investment/loans')->assertJsonPath('visible', true)->assertJsonPath('eligible', true);
        $this->postJson('/api/investor-investment/loans', ['requested_amount' => 2500, 'purpose' => 'Working capital'])->assertCreated();
        $this->postJson('/api/investor-investment/loans', ['requested_amount' => 100])->assertStatus(422);

        $this->assertSame(1, LoanApplication::where('user_id', $d->id)->count());
        $this->assertSame('submitted', LoanApplication::where('user_id', $d->id)->first()->status);
    }

    public function test_loan_eligibility_never_approves_or_prices_a_loan(): void
    {
        $this->openProgramme();
        $d = $this->ranked('elite');
        $this->actingAs($d, 'sanctum')->postJson('/api/investor-investment/loans', ['requested_amount' => 9000])->assertCreated();

        $a = LoanApplication::where('user_id', $d->id)->firstOrFail();
        $this->assertSame('submitted', $a->status);
        $this->assertNull($a->approved_terms);
        $this->assertNull($a->decided_by);
        $this->assertStringContainsString('not approval', $this->getJson('/api/investor-investment/loans')->json('disclaimer'));
    }

    public function test_a_loan_decision_follows_the_review_steps_and_needs_written_terms_and_a_reason(): void
    {
        $this->openProgramme();
        $d = $this->ranked('diamond');
        $this->actingAs($d, 'sanctum')->postJson('/api/investor-investment/loans', ['requested_amount' => 4000])->assertCreated();
        $a = LoanApplication::firstOrFail();

        $this->asAdmin();
        $this->postJson("/api/admin/ranks/loans/applications/{$a->id}/approve", ['approved_terms' => 'Terms agreed under policy LEND-POL-2026-01.', 'reason' => 'Verified'])->assertStatus(422);   // not under review yet
        $this->postJson("/api/admin/ranks/loans/applications/{$a->id}/review", [])->assertOk()->assertJsonPath('status', 'under_review');
        $this->postJson("/api/admin/ranks/loans/applications/{$a->id}/approve", ['approved_terms' => 'short', 'reason' => 'Verified'])->assertStatus(422);
        $this->postJson("/api/admin/ranks/loans/applications/{$a->id}/approve", ['approved_terms' => 'Terms agreed under policy LEND-POL-2026-01.', 'reason' => 'Verified affordability'])
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame(['submitted', 'under_review', 'approved'], $a->events()->pluck('event')->all());

        // The investor tracks it and sees the approved terms only after approval.
        $mine = $this->actingAs($d, 'sanctum')->getJson('/api/investor-investment/loans')->json('applications.0');
        $this->assertSame('approved', $mine['status']);
        $this->assertStringContainsString('LEND-POL-2026-01', $mine['approved_terms']);
        $this->assertStringNotContainsString('affordability', json_encode($mine), 'internal decision reasons are not shown for an approval');
    }

    public function test_rejection_needs_a_reason_and_is_final(): void
    {
        $this->openProgramme();
        $this->actingAs($this->ranked('elite'), 'sanctum')->postJson('/api/investor-investment/loans', ['requested_amount' => 4000])->assertCreated();
        $a = LoanApplication::firstOrFail();

        $this->asAdmin()->postJson("/api/admin/ranks/loans/applications/{$a->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/admin/ranks/loans/applications/{$a->id}/reject", ['reason' => 'Does not meet policy criteria'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson("/api/admin/ranks/loans/applications/{$a->id}/review", [])->assertStatus(422);
    }

    public function test_an_investor_can_withdraw_only_their_own_open_application(): void
    {
        $this->openProgramme();
        $mine = $this->ranked('diamond'); $other = $this->ranked('elite');
        $this->actingAs($mine, 'sanctum')->postJson('/api/investor-investment/loans', ['requested_amount' => 1000])->assertCreated();
        $a = LoanApplication::firstOrFail();

        $this->actingAs($other, 'sanctum')->postJson("/api/investor-investment/loans/{$a->id}/withdraw")->assertStatus(404);
        $this->actingAs($mine, 'sanctum')->postJson("/api/investor-investment/loans/{$a->id}/withdraw")->assertOk();
        $this->assertSame('withdrawn', $a->fresh()->status);
    }

    public function test_an_investor_sees_only_their_own_applications(): void
    {
        $this->openProgramme();
        $a = $this->ranked('diamond'); $b = $this->ranked('elite');
        $this->actingAs($a, 'sanctum')->postJson('/api/investor-investment/loans', ['requested_amount' => 1000])->assertCreated();

        $this->assertCount(0, $this->actingAs($b, 'sanctum')->getJson('/api/investor-investment/loans')->json('applications'));
    }

    public function test_only_admins_can_configure_review_or_decide_loans(): void
    {
        $this->openProgramme();
        $this->actingAs($this->ranked('diamond'), 'sanctum')->postJson('/api/investor-investment/loans', ['requested_amount' => 1000])->assertCreated();
        $a = LoanApplication::firstOrFail();

        foreach (['financial', 'investor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']), 'sanctum');
            $this->putJson('/api/admin/ranks/loans/settings', ['is_active' => false, 'reason' => 'Not allowed'])->assertStatus(403);
            $this->getJson('/api/admin/ranks/loans/applications')->assertStatus(403);
            $this->postJson("/api/admin/ranks/loans/applications/{$a->id}/approve", ['approved_terms' => 'Anything at all here', 'reason' => 'Nope'])->assertStatus(403);
        }
        $this->assertSame('submitted', $a->fresh()->status);
    }

    public function test_programme_changes_are_audited(): void
    {
        $this->openProgramme();
        $this->assertSame(2, RankBenefitAudit::where('subject_type', 'loan_programme')->where('action', 'configured')->count());
    }

    // ── Admin overview and export ───────────────────────────────────────────────────────────────────────

    public function test_admin_overview_lists_all_six_ranks_status_counts_and_the_unconfirmed_calculation_policy(): void
    {
        $res = $this->asAdmin()->getJson('/api/admin/ranks/benefits')->assertOk()->json();

        $this->assertCount(6, $res['ranks']);
        $this->assertSame(RankBenefit::count(), array_sum($res['counts']));
        $this->assertFalse($res['calculation_policy']['confirmed_by_management']);
        $this->assertFalse($res['incentives_activation_enabled']);
        $topics = collect($res['calculation_policy']['rules'])->pluck('topic')->all();
        foreach (['Withdrawals', 'Redeemed capital', 'Reinvestment'] as $t) $this->assertContains($t, $topics);
    }

    public function test_the_benefits_export_downloads_for_authorised_staff_only(): void
    {
        $this->asAdmin()->get('/api/admin/ranks/benefits/export?type=benefits')->assertOk();
        $this->actingAs($this->investor(), 'sanctum')->get('/api/admin/ranks/benefits/export?type=benefits')->assertStatus(403);
    }

    public function test_ranking_a_benefit_change_never_touches_investor_money_or_rank_records(): void
    {
        $u = $this->ranked('gold');
        $before = [DB::table('users')->where('id', $u->id)->value('balance'), DB::table('investment_accounts')->count(), DB::table('investor_rank_changes')->count()];

        $b = $this->benefit('gold.briefings');
        $this->asAdmin()->postJson("/api/admin/ranks/benefits/{$b->id}/status", ['status' => 'suspended', 'reason' => 'Check isolation'])->assertOk();

        $this->assertSame($before, [DB::table('users')->where('id', $u->id)->value('balance'), DB::table('investment_accounts')->count(), DB::table('investor_rank_changes')->count()]);
        $this->assertSame($this->rank('gold')->id, $u->fresh()->investor_rank_id);
    }
}
