<?php
// LOCATION: tests/Feature/Rank/RankDecisionsTest.php
//
// Management's six decisions, one by one. RankTestCase turns the currency gate ON for the rest of the
// suite; here the tests that are ABOUT the gate switch it off again to prove the real default behaviour.

namespace Tests\Feature\Rank;

use App\Models\InvestorRankChange;
use App\Models\RankCriterion;
use App\Models\RankDowngradeReview;
use App\Services\Rank\RankEligibility;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankFieldRegistry;
use Illuminate\Support\Facades\DB;

class RankDecisionsTest extends RankTestCase
{
    protected function engine(): RankEvaluationService
    {
        return app(RankEvaluationService::class);
    }

    protected function unconfirmed(): void
    {
        config(['ranks.currency_confirmed' => false]);
    }

    // ── Shipped defaults mirror the decisions ───────────────────────────────────────

    public function test_shipped_defaults_are_the_safe_ones(): void
    {
        $defaults = require config_path('ranks.php');          // the file's own defaults, ignoring test overrides

        $this->assertFalse($defaults['currency_confirmed'], 'currency must start UNCONFIRMED');
        $this->assertFalse($defaults['allow_manual_override'], 'manual overrides must start DISABLED');
        $this->assertTrue($defaults['require_active_account']);
        $this->assertTrue($defaults['require_verified_kyc']);
        $this->assertSame('USD', $defaults['ranking_currency']);   // thresholds are in USD; values must still be VERIFIED
    }

    // ── Decision 1: currency is flagged and ENFORCED, not assumed ───────────────────

    public function test_money_criteria_can_be_saved_as_drafts_but_not_activated_while_currency_is_unconfirmed(): void
    {
        $this->unconfirmed();
        $this->actingAs($this->admin, 'sanctum');

        $draft = $this->postJson('/api/admin/ranks/criteria',
            $this->payload('bronze', [$this->cond('total_invested', '>=', 100)], ['is_active' => false]))->assertCreated();
        $id = $draft->json('criterion.id');

        $this->postJson("/api/admin/ranks/criteria/{$id}/activate")
            ->assertStatus(422)->assertJsonValidationErrors('conflicts');
        $this->assertFalse(RankCriterion::find($id)->is_active);

        // creating one directly as active is refused too, and nothing is stored
        $this->postJson('/api/admin/ranks/criteria',
            $this->payload('silver', [$this->cond('total_invested', '>=', 1000)]))->assertStatus(422);
        $this->assertSame(1, RankCriterion::count());
    }

    public function test_the_refusal_explains_that_the_currency_is_unconfirmed(): void
    {
        $this->unconfirmed();
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/criteria',
            $this->payload('bronze', [$this->cond('total_invested', '>=', 100)]));

        $res->assertStatus(422);
        $this->assertStringContainsString('currency', implode(' ', $res->json('errors.conflicts')));
        $this->assertStringContainsString('Total invested amount', implode(' ', $res->json('errors.conflicts')));
    }

    public function test_criteria_that_do_not_measure_money_are_unaffected_by_the_currency_gate(): void
    {
        $this->unconfirmed();
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/admin/ranks/criteria', $this->payload('silver', [$this->cond('completed_investment_count', '>=', 2)]))
            ->assertCreated()->assertJsonPath('criterion.is_active', true);
        $this->postJson('/api/admin/ranks/criteria', $this->payload('gold', [$this->cond('kyc_verified', '=', true)]))->assertCreated();
    }

    public function test_the_draft_can_be_activated_once_management_confirms_the_currency(): void
    {
        $this->unconfirmed();
        $this->actingAs($this->admin, 'sanctum');
        $id = $this->postJson('/api/admin/ranks/criteria',
            $this->payload('bronze', [$this->cond('total_invested', '>=', 100)], ['is_active' => false]))->json('criterion.id');

        config(['ranks.currency_confirmed' => true]);                  // Management confirmed; setting changed
        $this->postJson("/api/admin/ranks/criteria/{$id}/activate")->assertOk();
        $this->assertTrue(RankCriterion::find($id)->is_active);
    }

    public function test_validate_reports_the_currency_problem_and_preview_still_works_as_a_dry_run(): void
    {
        $this->unconfirmed();
        $this->actingAs($this->admin, 'sanctum');
        $body = $this->payload('bronze', [$this->cond('total_invested', '>=', 100)]);

        $this->postJson('/api/admin/ranks/criteria/validate', $body)
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('conflicts.0.type', 'currency_unconfirmed');
        $this->postJson('/api/admin/ranks/criteria/preview', $body)->assertOk()->assertJsonStructure(['would_qualify']);
    }

    public function test_the_engine_ignores_money_criteria_while_the_currency_is_unconfirmed_even_if_active(): void
    {
        $this->createCriterion('bronze', [$this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1)]);
        $this->unconfirmed();                                           // e.g. the flag is switched off later

        $inv = $this->investorWith(500, 1);
        $this->engine()->evaluateUser($inv->id);
        $this->assertNull($this->freshRank($inv));                      // qualifies on paper, ignored in practice

        config(['ranks.currency_confirmed' => true]);
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('bronze', $this->freshRank($inv));
    }

    public function test_non_money_criteria_keep_working_while_the_currency_is_unconfirmed(): void
    {
        $this->unconfirmed();
        $this->createCriterion('silver', [$this->cond('completed_investment_count', '>=', 2)]);

        $inv = $this->investorWith(10, 2);
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('silver', $this->freshRank($inv));
    }

    public function test_the_proposed_loader_cannot_activate_while_the_currency_is_unconfirmed(): void
    {
        $this->unconfirmed();

        $this->artisan('ranks:load-proposed --activate')->assertFailed();

        $this->assertSame(6, RankCriterion::count());                     // drafts exist for review…
        $this->assertSame(0, RankCriterion::where('is_active', true)->count());   // …none is live
    }

    public function test_the_proposed_loader_still_creates_inactive_drafts_while_unconfirmed(): void
    {
        $this->unconfirmed();
        $this->artisan('ranks:load-proposed')->assertSuccessful();
        $this->assertSame(0, RankCriterion::where('is_active', true)->count());
    }

    public function test_dashboard_reports_the_currency_status_and_which_fields_are_money(): void
    {
        $this->unconfirmed();
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/admin/ranks')->assertOk()
            ->assertJsonPath('currency_confirmed', false)->assertJsonPath('ranking_currency', 'USD');

        $fields = collect($this->getJson('/api/admin/ranks/criteria/schema')->json('fields'))->keyBy('key');
        foreach (['total_invested', 'active_investment_amount', 'total_approved_deposits'] as $money) {
            $this->assertTrue($fields[$money]['monetary'], "$money is money");
        }
        foreach (['completed_investment_count', 'investment_count', 'kyc_verified', 'account_age_days'] as $other) {
            $this->assertFalse($fields[$other]['monetary'], "$other is not money");
        }
    }

    // ── Decision 2: cumulative non-cancelled amount ─────────────────────────────────

    public function test_investment_amount_is_the_cumulative_total_of_active_and_completed_excluding_cancelled(): void
    {
        $inv = $this->investor();
        $this->invest($inv, 300, 30, 'active');
        $this->investCompleted($inv, 200);                               // completed, term elapsed
        $this->invest($inv, 100, 30, 'completed');                       // completed, term NOT elapsed — still money invested
        $this->invest($inv, 5000, 30, 'cancelled');                      // never counts

        $total = app(RankFieldRegistry::class)->resolveMany([$inv->id], ['total_invested'])[$inv->id]['total_invested'];

        $this->assertEquals(600.00, $total);
    }

    public function test_cancelling_an_investment_removes_it_from_the_total_and_flags_but_never_downgrades(): void
    {
        $this->createCriterion('bronze', [$this->cond('total_invested', '>=', 500)]);
        $inv = $this->investor();
        $a = $this->invest($inv, 600);                                   // arrives with the rule live → Bronze
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('bronze', $this->freshRank($inv));

        $a->update(['status' => 'cancelled']);                           // total drops to 0
        $this->engine()->evaluateUser($inv->id);

        $this->assertSame('bronze', $this->freshRank($inv));            // no automatic downgrade
        $this->assertSame(0, InvestorRankChange::where('investor_id', $inv->id)->where('change_type', 'downgrade')->count());
        $this->assertSame(1, RankDowngradeReview::open()->where('investor_id', $inv->id)->count());   // flagged for Admin
    }

    public function test_a_downward_amount_correction_flags_for_review_and_does_not_downgrade(): void
    {
        // The audited Financial correction changes the amount through the model; the rank module just reacts.
        $this->createCriterion('bronze', [$this->cond('total_invested', '>=', 500)]);
        $inv = $this->investor();
        $a = $this->invest($inv, 600);
        $this->assertSame('bronze', $this->freshRank($inv));

        $a->update(['amount' => 200]);

        $this->assertSame('bronze', $this->freshRank($inv));
        $this->assertSame(1, RankDowngradeReview::open()->where('investor_id', $inv->id)->count());
    }

    // ── Decision 3: one genuine completed record = one cycle ────────────────────────

    public function test_a_cycle_needs_completed_status_an_elapsed_end_date_and_a_positive_amount(): void
    {
        $inv = $this->investor();
        $this->investCompleted($inv, 100);                                          // ✓ counts
        $this->invest($inv, 100, 30, 'completed');                                  // ✗ early completion (end date in the future)
        $this->invest($inv, 100, 30, 'active');                                     // ✗ still running
        $this->invest($inv, 100, 30, 'cancelled');                                  // ✗ cancelled
        $zero = $this->invest($inv, 0.00, 30, 'completed');                         // ✗ amount is zero
        DB::table('investment_accounts')->where('id', $zero->id)->update(['end_date' => now()->subDays(5)->toDateString()]);

        $count = app(RankFieldRegistry::class)->resolveMany([$inv->id], ['completed_investment_count'])[$inv->id]['completed_investment_count'];

        $this->assertSame(1, $count);
    }

    public function test_each_genuinely_completed_record_counts_once_and_only_once(): void
    {
        $inv = $this->investor();
        $one = $this->investCompleted($inv, 100);
        $this->investCompleted($inv, 100);
        $registry = app(RankFieldRegistry::class);

        $this->assertSame(2, $registry->resolveMany([$inv->id], ['completed_investment_count'])[$inv->id]['completed_investment_count']);
        // resolving again (or the record being touched) never inflates the count
        $one->touch();
        $this->assertSame(2, $registry->resolveMany([$inv->id], ['completed_investment_count'])[$inv->id]['completed_investment_count']);
    }

    // ── Decision 4: exactly two compliance requirements ─────────────────────────────

    public function test_the_only_compliance_requirements_are_an_active_account_and_approved_kyc(): void
    {
        $reqs = app(RankEligibility::class)->requirements();
        $this->assertSame(['account_active', 'kyc_approved'], array_column($reqs, 'key'));

        $row = app(RankEligibility::class)->check($this->investor(['kyc_status' => 'approved', 'kyc_verified' => false]));
        $this->assertFalse($row['eligible']);                         // BOTH kyc_status=approved AND kyc_verified=true
        $this->assertCount(1, $row['failures']);

        $this->assertTrue(app(RankEligibility::class)->check($this->investor())['eligible']);
    }

    // ── Decision 5: minimums only; highest wins; no upper limit unless Management asks ──

    public function test_minimum_thresholds_have_no_upper_limit_and_the_highest_qualifying_rank_wins(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();

        foreach (RankCriterion::with('conditions')->get() as $c) {
            foreach ($c->conditions as $cond) {
                $this->assertSame('>=', $cond->operator, "{$c->name}: {$cond->field} must be a minimum, not a band");
            }
        }

        $whale = $this->investorWith(5_000_000, 40);
        $this->engine()->evaluateUser($whale->id);
        $this->assertSame('elite', $this->freshRank($whale));          // nothing caps the top rank
    }

    // ── Decision 6: overrides off by default; reason + audit when enabled ───────────

    public function test_with_overrides_disabled_nothing_can_be_forced_through_even_with_a_justification(): void
    {
        $defaults = require config_path('ranks.php');
        $this->assertFalse($defaults['allow_manual_override']);
        config(['ranks.allow_manual_override' => false]);
        $inv = $this->investor(['kyc_status' => 'pending', 'kyc_verified' => false]);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$inv->id}/assign", [
            'rank_id' => $this->rank('gold')->id, 'reason' => 'Management asked for this',
            'override' => true, 'override_justification' => 'A long and entirely convincing justification',
        ])->assertStatus(422)->assertJsonValidationErrors('override');

        $this->assertNull($this->freshRank($inv));
        $this->assertSame(0, InvestorRankChange::count());
    }

    public function test_when_authorized_an_override_needs_a_reason_and_is_recorded_with_the_admin_and_justification(): void
    {
        config(['ranks.allow_manual_override' => true]);
        $inv = $this->investor(['status' => 'suspended']);
        $this->actingAs($this->admin, 'sanctum');
        $url = "/api/admin/ranks/investors/{$inv->id}/assign";

        // no reason at all → refused
        $this->postJson($url, ['rank_id' => $this->rank('silver')->id, 'override' => true,
            'override_justification' => 'Authorized by the Compliance Officer'])->assertStatus(422)->assertJsonValidationErrors('reason');
        // reason but no justification → refused
        $this->postJson($url, ['rank_id' => $this->rank('silver')->id, 'reason' => 'Compliance sign-off', 'override' => true])
            ->assertStatus(422)->assertJsonValidationErrors('override_justification');
        $this->assertSame(0, InvestorRankChange::count());

        $this->postJson($url, ['rank_id' => $this->rank('silver')->id, 'reason' => 'Compliance sign-off', 'override' => true,
            'override_justification' => 'Authorized by the Compliance Officer'])->assertOk();

        $row = InvestorRankChange::sole();
        $this->assertSame('authorized_override', $row->change_type);
        $this->assertSame($this->admin->id, $row->actor_id);
        $this->assertSame('Compliance sign-off', $row->reason);
        $this->assertSame('Authorized by the Compliance Officer', $row->override_justification);
        $this->assertFalse((bool) $row->is_automatic);
    }

    // ── Standing requirement: the module never touches money ────────────────────────

    public function test_nothing_in_the_rank_module_writes_to_money_tables(): void
    {
        // Static guard: rank code may READ investments/deposits/users but must never call a write on them.
        $files = array_merge(
            glob(app_path('Services/Rank/*.php')),
            glob(app_path('Jobs/*Rank*.php')),
            glob(app_path('Http/Controllers/Admin/Rank*.php'))
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $src = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression("/'balance'/", $src, basename($file) . ' must not touch balances');
            $this->assertDoesNotMatchRegularExpression('/(Deposit|Withdrawal|InvestmentAccount|BalanceAdjustment)::(create|insert|update|where[^;]*->(update|delete|increment|decrement))/', $src, basename($file));
            $this->assertDoesNotMatchRegularExpression("/DB::table\('(deposits|withdrawals|investment_accounts|balance_adjustments)'\)[^;]*->(update|insert|delete|increment|decrement)/", $src, basename($file));
        }
    }
}
