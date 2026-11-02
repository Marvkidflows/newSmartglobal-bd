<?php
// LOCATION: tests/Feature/Rank/RankBenefitsPhase2Test.php
//
// Phase 2: investor report (cycle history / consolidated records), plan terms before commitment, the Bronze → Silver
// eligibility notice, and the safeguards that nothing financial is switched on by this module.
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php artisan test --filter=RankBenefitsPhase2Test

namespace Tests\Feature\Rank;

use App\Models\InvestmentAccount;
use App\Models\LoanProgrammeSetting;
use App\Models\RankBenefit;
use App\Models\RankCriterion;
use App\Models\RankIncentiveTerm;
use App\Models\RankReview;
use App\Models\User;
use App\Services\Rank\RankBenefitService;
use Illuminate\Support\Facades\DB;

class RankBenefitsPhase2Test extends RankTestCase
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

    private function enable(string $key): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/benefits/' . RankBenefit::where('key', $key)->value('id') . '/status',
            ['status' => 'active', 'reason' => 'Management approved', 'approval_reference' => 'MGT-2026-777'])->assertOk();
    }

    private function report(User $u)
    {
        return $this->actingAs($u, 'sanctum')->getJson('/api/investor-investment/rank/report')->assertOk();
    }

    // ── Report availability follows the catalogue ───────────────────────────────────────────────────────

    public function test_the_report_is_unavailable_until_management_enables_the_benefit(): void
    {
        $u = $this->ranked('gold');
        $this->investCompleted($u, 6000);

        $r = $this->report($u)->json();
        $this->assertFalse($r['available']);
        $this->assertArrayNotHasKey('cycles', $r);
        $this->assertArrayNotHasKey('summary', $r);
    }

    public function test_the_report_is_unavailable_to_a_lower_rank_even_when_a_higher_rank_benefit_is_enabled(): void
    {
        $this->enable('platinum.enhanced-reporting');
        $this->assertFalse($this->report($this->ranked('gold'))->json('available'));
        $this->assertTrue($this->report($this->ranked('platinum'))->json('available'));
    }

    public function test_an_unranked_or_suspended_investor_gets_no_report(): void
    {
        $this->enable('gold.performance-summary');
        $this->assertFalse($this->report($this->investor())->json('available'));
        $s = $this->ranked('gold'); $s->forceFill(['rank_status' => 'suspended'])->save();
        $this->assertFalse($this->report($s->fresh())->json('available'));
    }

    public function test_sections_unlock_by_rank_tier(): void
    {
        foreach (['gold.performance-summary', 'platinum.enhanced-reporting', 'diamond.enhanced-reporting'] as $k) $this->enable($k);

        $gold = $this->report($this->ranked('gold'))->json('sections');
        $this->assertSame([true, false, false], array_values($gold));
        $plat = $this->report($this->ranked('platinum'))->json('sections');
        $this->assertSame([true, true, false], array_values($plat));
        $dia = $this->report($this->ranked('diamond'))->json('sections');
        $this->assertSame([true, true, true], array_values($dia));
    }

    // ── Report content mirrors the engine's rules ───────────────────────────────────────────────────────

    public function test_the_report_flags_exactly_what_the_engine_counts_and_why_not(): void
    {
        $this->enable('gold.performance-summary');
        $u = $this->ranked('gold');
        $done = $this->investCompleted($u, 1000);
        $active = $this->invest($u, 500);
        $cancelled = $this->invest($u, 300, 30, 'cancelled', false);
        $unverified = $this->invest($u, 700, 30, 'active', false);

        $r = $this->report($u)->json();
        $byId = collect($r['cycles'])->keyBy('id');

        $this->assertTrue($byId[$done->id]['cycle_counted']);
        $this->assertTrue($byId[$done->id]['amount_counted']);
        $this->assertFalse($byId[$active->id]['cycle_counted']);
        $this->assertSame('Cycle not completed yet', $byId[$active->id]['not_counted_reason']);
        $this->assertSame('Cancelled', $byId[$cancelled->id]['not_counted_reason']);
        $this->assertFalse($byId[$cancelled->id]['amount_counted']);
        $this->assertFalse($byId[$unverified->id]['amount_counted']);
        $this->assertNotNull($byId[$unverified->id]['amount_note']);
        $this->assertSame(1, $r['summary']['completed_cycles_counted']);
        $this->assertEquals(1500.0, $r['summary']['verified_usd_total']);   // historical: completed 1000 + active 500; cancelled/unverified excluded
        $this->assertEquals(500.0, $r['summary']['current_eligible_principal'], 'only the running position is current principal');
        $this->assertEquals(500.0, $byId[$active->id]['current_principal_usd']);
        $this->assertEquals(0.0, $byId[$done->id]['current_principal_usd']);
    }

    public function test_a_completed_cycle_with_an_unverified_amount_counts_for_neither_and_matches_the_engine(): void
    {
        $this->enable('gold.performance-summary');
        $u = $this->ranked('gold');
        $inv = $this->invest($u, 400, 30, 'completed', false);   // completed but Financial has NOT verified its currency
        DB::table('investment_accounts')->where('id', $inv->id)->update(['start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(10)->toDateString()]);

        $report = $this->report($u)->json();
        $row = collect($report['cycles'])->firstWhere('id', $inv->id);
        $progress = $this->actingAs($u, 'sanctum')->getJson('/api/investor-investment/rank/criteria')->json('progress');

        $this->assertFalse($row['cycle_counted']);
        $this->assertSame('Awaiting currency verification', $row['not_counted_reason']);
        $this->assertFalse($row['amount_counted']);
        $this->assertSame(0, $report['summary']['completed_cycles_counted']);
        $this->assertSame($progress['completed_cycles'], $report['summary']['completed_cycles_counted'], 'report and engine must agree on cycles');
        $this->assertSame(1, $progress['excluded']['cycles_awaiting_verification']);

        $this->verifyUsd($inv->fresh());                         // Financial verifies → now it counts, once
        $report = $this->report($u)->json();
        $this->assertSame(1, $report['summary']['completed_cycles_counted']);
        $this->assertTrue(collect($report['cycles'])->firstWhere('id', $inv->id)['cycle_counted']);
    }

    public function test_the_report_agrees_with_the_investor_progress_figures(): void
    {
        $this->enable('gold.performance-summary');
        $u = $this->ranked('gold');
        $this->investCompleted($u, 1000); $this->investCompleted($u, 250);
        $this->invest($u, 300);                                   // the only current principal

        $report = $this->report($u)->json('summary');
        $progress = $this->actingAs($u, 'sanctum')->getJson('/api/investor-investment/rank/criteria')->json('progress');

        $this->assertEquals(300.0, $progress['current_amount_usd']);
        $this->assertEquals($progress['current_amount_usd'], $report['current_eligible_principal']);
        $this->assertEquals(1550.0, $report['verified_usd_total'], 'historical total includes the completed positions');
        $this->assertSame($progress['completed_cycles'], $report['completed_cycles_counted']);
    }

    public function test_a_possible_duplicate_is_shown_as_excluded_not_counted(): void
    {
        $this->enable('gold.performance-summary');
        $u = $this->ranked('gold');
        $first = $this->investCompleted($u, 800);
        // same investor, plan, amount and start date created immediately after → the engine's duplicate signature
        $dup = InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $first->investment_plan_id, 'amount' => 800,
            'profit_percentage' => 0, 'expected_profit' => 0, 'status' => 'completed', 'start_date' => $first->start_date, 'end_date' => $first->end_date]);

        $c = collect($this->report($u)->json('cycles'))->keyBy('id');
        $this->assertTrue($c[$first->id]['cycle_counted']);
        $this->assertFalse($c[$dup->id]['cycle_counted']);
        $this->assertFalse($c[$dup->id]['amount_counted']);
        $this->assertStringContainsString('duplicate', $c[$dup->id]['not_counted_reason']);
    }

    public function test_the_report_contains_only_the_investors_own_records_and_no_withdrawal_or_internal_data(): void
    {
        $this->enable('gold.performance-summary');
        $mine = $this->ranked('gold'); $other = $this->ranked('gold');
        $this->investCompleted($mine, 1000);
        $theirs = $this->investCompleted($other, 9999);

        $json = $this->report($mine)->json();
        $this->assertNotContains($theirs->id, collect($json['cycles'])->pluck('id')->all());
        $this->assertStringNotContainsString('9999', json_encode($json));
        $this->assertStringNotContainsString('withdraw', strtolower(json_encode(array_keys($json['cycles'][0]))));
    }

    public function test_reading_the_report_writes_nothing(): void
    {
        $this->enable('gold.performance-summary');
        $u = $this->ranked('gold');
        $this->investCompleted($u, 1000);
        $before = [DB::table('investment_accounts')->count(), DB::table('users')->where('id', $u->id)->value('balance'), DB::table('investor_rank_changes')->count(), DB::table('usd_valuations')->count()];

        $this->report($u);

        $this->assertSame($before, [DB::table('investment_accounts')->count(), DB::table('users')->where('id', $u->id)->value('balance'), DB::table('investor_rank_changes')->count(), DB::table('usd_valuations')->count()]);
    }

    public function test_consolidated_by_year_groups_records(): void
    {
        $this->enable('diamond.enhanced-reporting');
        $u = $this->ranked('diamond');
        $this->investCompleted($u, 1000); $this->investCompleted($u, 2000);

        $r = $this->report($u)->json();
        $this->assertSame(1, count($r['by_year']));
        $this->assertSame(2, $r['by_year'][0]['records']);
        $this->assertEquals(3000.0, $r['by_year'][0]['verified_usd']);
    }

    // ── Bronze → Silver notice ──────────────────────────────────────────────────────────────────────────

    private function silverReviewFor(User $u): RankReview
    {
        return RankReview::forceCreate([
            'reference' => 'RR-TEST' . $u->id, 'investor_id' => $u->id, 'account_id' => $u->account_id, 'current_rank_id' => $u->investor_rank_id,
            'proposed_rank_id' => $this->rank('silver')->id, 'status' => RankReview::PENDING, 'pending_key' => $u->id . ':' . $this->rank('silver')->id,
            'basis_hash' => 'x', 'rank_criterion_id' => null, 'evaluation' => [],
        ]);
    }

    public function test_no_next_rank_notice_is_sent_while_the_benefit_is_pending_approval(): void
    {
        $u = $this->ranked('bronze');
        $this->assertFalse(app(RankBenefitService::class)->notifyNextRankEligibility($this->silverReviewFor($u)));
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $u->id)->count());
    }

    public function test_the_notice_is_sent_once_after_management_enables_it_and_says_review_not_awarded(): void
    {
        $this->enable('bronze.next-rank-notice');
        $u = $this->ranked('bronze');
        $review = $this->silverReviewFor($u);
        $svc = app(RankBenefitService::class);

        $this->assertTrue($svc->notifyNextRankEligibility($review));
        $this->assertFalse($svc->notifyNextRankEligibility($review), 'a second call must not duplicate it');

        $rows = DB::table('notifications')->where('notifiable_id', $u->id)->get();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('after SSI reviews and approves', $rows[0]->data);
        $this->assertSame($this->rank('bronze')->id, $u->fresh()->investor_rank_id, 'the rank itself is unchanged by the notice');
    }

    public function test_the_queued_notice_job_is_idempotent_and_skips_a_decided_review(): void
    {
        $this->enable('bronze.next-rank-notice');
        $u = $this->ranked('bronze');
        $review = $this->silverReviewFor($u);

        \App\Jobs\SendNextRankNoticeJob::dispatchSync($review->id);
        \App\Jobs\SendNextRankNoticeJob::dispatchSync($review->id);   // a retry / duplicate dispatch
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $u->id)->count());

        $other = $this->ranked('bronze');
        $decided = $this->silverReviewFor($other);
        $decided->forceFill(['status' => RankReview::REJECTED, 'pending_key' => null])->save();
        \App\Jobs\SendNextRankNoticeJob::dispatchSync($decided->id);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $other->id)->count());
    }

    public function test_the_notice_is_only_for_bronze_to_silver(): void
    {
        $this->enable('bronze.next-rank-notice');
        $u = $this->ranked('silver');
        $review = $this->silverReviewFor($u);
        $this->assertFalse(app(RankBenefitService::class)->notifyNextRankEligibility($review));
    }

    // ── Plan terms before commitment ────────────────────────────────────────────────────────────────────

    private function plan(): int
    {
        return DB::table('investment_plans')->insertGetId(['name' => 'Terms plan', 'min_amount' => 100, 'max_amount' => 5000, 'profit_percentage' => 12.5,
            'duration_days' => 60, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_plan_terms_show_the_contractual_return_and_no_incentive_by_default(): void
    {
        $id = $this->plan();
        $r = $this->actingAs($this->ranked('gold'), 'sanctum')->getJson("/api/investor-investment/rank/plan-terms/$id")->assertOk()->json();

        $this->assertEquals(12.5, $r['plan']['contractual_return_percent']);
        $this->assertSame(60, $r['plan']['duration_days']);
        $this->assertSame([], $r['rank_incentives']);
        $this->assertStringContainsString('does not change it', $r['notice']);
    }

    public function test_an_incentive_is_listed_only_when_active_approved_and_the_master_switch_is_on(): void
    {
        $id = $this->plan(); $u = $this->ranked('gold');
        $term = RankIncentiveTerm::create(['label' => 'Gold test bonus', 'investor_rank_id' => $this->rank('gold')->id, 'kind' => 'fixed_bonus', 'value' => 10,
            'status' => 'active', 'effective_from' => now()->subDay()->toDateString(), 'conditions' => 'Test conditions apply.']);

        config(['ranks.incentives_activation_enabled' => false]);
        $this->assertSame([], $this->actingAs($u, 'sanctum')->getJson("/api/investor-investment/rank/plan-terms/$id")->json('rank_incentives'));

        config(['ranks.incentives_activation_enabled' => true]);
        $this->assertCount(1, $this->getJson("/api/investor-investment/rank/plan-terms/$id")->json('rank_incentives'));

        $term->update(['status' => 'approved']);   // approved but not activated → never shown
        $this->assertSame([], $this->getJson("/api/investor-investment/rank/plan-terms/$id")->json('rank_incentives'));
    }

    public function test_investment_records_keep_the_terms_recorded_at_creation(): void
    {
        $this->enable('gold.performance-summary');
        $u = $this->ranked('gold');
        $inv = $this->investCompleted($u, 1000);
        DB::table('investment_accounts')->where('id', $inv->id)->update(['profit_percentage' => 9.5, 'expected_profit' => 95, 'total_return' => 1095]);
        DB::table('investment_plans')->where('id', $inv->investment_plan_id)->update(['profit_percentage' => 20]);   // plan changed later

        $c = $this->report($u)->json('cycles.0');
        $this->assertEquals(9.5, $c['recorded_terms']['profit_percentage']);
    }

    // ── Safeguards: this module never switches anything financial on ────────────────────────────────────

    public function test_syncing_the_catalogue_activates_nothing_financial_and_touches_no_financial_control(): void
    {
        DB::table('rank_currency_policies')->delete();   // the real shipped state: no confirmed policy
        $valuations = DB::table('usd_valuations')->count();
        $criteria = RankCriterion::count();

        app(RankBenefitService::class)->syncDefaults();
        $this->artisan('ranks:sync-benefits')->assertSuccessful();

        $this->assertSame(0, DB::table('rank_currency_policies')->count(), 'sync must never create or confirm a currency policy');
        $this->assertSame($valuations, DB::table('usd_valuations')->count(), 'sync must never assign USD values');
        $this->assertSame($criteria, RankCriterion::count());
        $this->assertSame(0, RankCriterion::where('is_active', true)->count());
        $this->assertSame(0, RankIncentiveTerm::count());
        $this->assertFalse(LoanProgrammeSetting::current()->is_authorized);
        $this->assertSame(0, RankBenefit::where('is_financial', true)->where('status', '!=', 'pending_approval')->count());
        $this->assertSame(0, RankBenefit::where('category', 'loan')->where('status', 'active')->count());
    }

    public function test_the_catalogue_covers_every_benefit_in_the_framework_for_each_rank(): void
    {
        // Counts taken from the framework PDF (§3 A–F): badge + listed benefits (+ one inactive incentive row for Silver…Elite).
        $expected = ['bronze' => 8, 'silver' => 9, 'gold' => 9, 'platinum' => 9, 'diamond' => 14, 'elite' => 15];
        foreach ($expected as $key => $count) {
            $this->assertSame($count, RankBenefit::where('investor_rank_id', $this->rank($key)->id)->count(), "$key benefit count changed");
        }
        $this->assertSame(64, RankBenefit::count());
    }

    public function test_every_benefit_that_is_not_a_baseline_platform_feature_still_needs_approval(): void
    {
        foreach (['bronze.next-rank-notice', 'gold.performance-summary', 'platinum.enhanced-reporting', 'diamond.enhanced-reporting', 'elite.highest-reporting'] as $k) {
            $b = RankBenefit::where('key', $k)->firstOrFail();
            $this->assertTrue((bool) $b->requires_approval, "$k must require approval");
            $this->assertSame('pending_approval', $b->status, "$k must start unapproved");
        }
    }

    public function test_all_six_ranks_and_every_benefit_status_stay_visible_to_an_investor(): void
    {
        $r = $this->actingAs($this->investor(), 'sanctum')->getJson('/api/investor-investment/rank/benefits')->assertOk()->json();
        $this->assertCount(6, $r['ranks']);
        $statuses = collect($r['ranks'])->flatMap(fn ($x) => collect($x['benefits'])->pluck('status'))->unique()->all();
        $this->assertContains('pending_approval', $statuses);
        $this->assertContains('active', $statuses);
        $this->assertContains('subject_to_eligibility', $statuses);
    }
}
