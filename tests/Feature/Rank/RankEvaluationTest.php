<?php
// LOCATION: tests/Feature/Rank/RankEvaluationTest.php

namespace Tests\Feature\Rank;

use App\Models\InvestorRankChange;
use App\Models\RankDowngradeReview;
use App\Models\RankEvaluationRun;
use App\Services\Rank\RankEvaluationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RankEvaluationTest extends RankTestCase
{
    protected function engine(): RankEvaluationService
    {
        return app(RankEvaluationService::class);
    }

    public function test_investor_meeting_active_criteria_is_upgraded_automatically_with_audit(): void
    {
        $u = $this->investor();
        $this->invest($u, 600);
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);

        $this->engine()->evaluateUser($u->id);

        $this->assertSame('silver', $this->freshRank($u));
        $change = InvestorRankChange::where('investor_id', $u->id)->sole();
        $this->assertSame('automatic_upgrade', $change->change_type);
        $this->assertTrue($change->is_automatic);
        $this->assertNull($change->actor_id);
        $this->assertSame('System (Rank Engine)', $change->actor_name);
        $this->assertNull($change->previous_rank_id);
        $this->assertSame($this->rank('silver')->id, $change->new_rank_id);
        $this->assertSame($u->account_id, $change->account_id);
        $this->assertSame('Silver criteria', $change->criteria_snapshot['criterion']['name']);
        $this->assertTrue($change->criteria_snapshot['evaluation']['satisfied']);
        $this->assertNotEmpty($change->reason);
    }

    public function test_investor_not_meeting_criteria_is_not_upgraded(): void
    {
        $u = $this->investor();
        $this->invest($u, 100);
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);

        $this->assertNull($this->engine()->evaluateUser($u->id));
        $this->assertNull($this->freshRank($u));
        $this->assertSame(0, InvestorRankChange::count());
    }

    public function test_highest_qualifying_rank_is_assigned_when_several_are_satisfied(): void
    {
        // Rules first: activating a rule re-evaluates EXISTING investors, so an investor who already
        // had data would (correctly) climb rung by rung as each rule goes live. Here the investor
        // arrives once all four rules are live, so one evaluation must make ONE jump to the highest.
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);
        $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)]);
        $this->createCriterion('platinum', [$this->cond('total_invested', '>=', 4000)]);
        $this->createCriterion('diamond', [$this->cond('total_invested', '>=', 9000)]);
        $u = $this->investor();
        $this->invest($u, 5000);

        $this->engine()->evaluateUser($u->id);

        $this->assertSame('platinum', $this->freshRank($u));
        $this->assertSame(1, InvestorRankChange::where('investor_id', $u->id)->count());
    }

    public function test_any_and_grouped_logic_are_evaluated_correctly(): void
    {
        $any = $this->investor(); $this->invest($any, 50);
        $none = $this->investor();
        $this->createCriterion('bronze', [
            $this->cond('total_invested', '>=', 10000), $this->cond('investment_count', '>=', 1),
        ], ['logic' => 'any']);
        $this->engine()->evaluateUser($any->id);
        $this->engine()->evaluateUser($none->id);
        $this->assertSame('bronze', $this->freshRank($any));
        $this->assertNull($this->freshRank($none));

        $g = $this->investor(); $this->invest($g, 50);
        $this->createCriterion('silver', [
            $this->cond('total_invested', '>=', 10000, 1), $this->cond('kyc_verified', '=', true, 1),
            $this->cond('investment_count', '>=', 1, 2), $this->cond('total_invested', '>=', 40, 2),
        ], ['logic' => 'grouped']);
        $this->engine()->evaluateUser($g->id);
        $this->assertSame('silver', $this->freshRank($g)); // group 2 satisfied; group 1 not needed
    }

    public function test_repeated_evaluation_creates_no_duplicate_change_or_notification_record(): void
    {
        $u = $this->investor(); $this->invest($u, 600);
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);

        for ($i = 0; $i < 5; $i++) $this->engine()->evaluateUser($u->id);

        $this->assertSame(1, InvestorRankChange::where('investor_id', $u->id)->count());
        $this->assertSame(1, DB::table('rank_change_notifications')->count());
    }

    public function test_a_stale_evaluation_cannot_overwrite_a_newer_higher_rank(): void
    {
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);
        $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)]);

        // Load only the (lower) Silver rule, as a slow/stale worker would have.
        $stale = $this->engine()->activeCriteria()->filter(fn ($c) => $c->rank->key === 'silver')->values();

        $u = $this->investor(); $this->invest($u, 5000);             // arrives with both rules live
        $this->engine()->evaluateUser($u->id);                       // fresh worker → Gold
        $this->assertSame('gold', $this->freshRank($u));

        $this->engine()->evaluateUser($u->id, 'event', null, $stale); // stale worker
        $this->assertSame('gold', $this->freshRank($u));              // never moves down/sideways
        $this->assertSame(1, InvestorRankChange::where('investor_id', $u->id)->count());
    }

    public function test_the_database_itself_blocks_two_transitions_from_the_same_state(): void
    {
        $u = $this->investor(); $this->invest($u, 600);
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);
        $this->engine()->evaluateUser($u->id);
        $first = InvestorRankChange::where('investor_id', $u->id)->sole();

        $this->expectException(QueryException::class);
        InvestorRankChange::create([
            'reference' => 'RK-DUPLICATE', 'investor_id' => $u->id, 'account_id' => $u->account_id,
            'sequence' => $first->sequence, 'previous_rank_id' => null, 'new_rank_id' => $first->new_rank_id,
            'change_type' => 'automatic_upgrade', 'is_automatic' => true, 'actor_name' => 'x', 'reason' => 'dup',
        ]);
    }

    public function test_changed_criteria_trigger_automatic_upgrades_for_existing_investors(): void
    {
        $qualifies = $this->investor(); $this->invest($qualifies, 2000);
        $short = $this->investor(); $this->invest($short, 100);
        $gold = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 5000)])['criterion']['id'];
        $this->engine()->evaluateUser($qualifies->id);
        $this->assertNull($this->freshRank($qualifies));

        // Admin lowers the threshold → run is queued → investors are re-evaluated.
        $res = $this->putJson("/api/admin/ranks/criteria/{$gold}", [
            'name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 1500)],
        ])->assertOk();
        $this->assertNotNull($res->json('run'));

        $run = RankEvaluationRun::findOrFail($res->json('run.id'));
        for ($i = 0; $i < 20 && $this->engine()->processNextChunk($run); $i++);

        $this->assertSame('gold', $this->freshRank($qualifies));
        $this->assertNull($this->freshRank($short));
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame($run->total, $run->processed);
        $this->assertGreaterThanOrEqual(1, $run->upgraded);
        $this->assertTrue(InvestorRankChange::where('investor_id', $qualifies->id)->where('evaluation_run_id', $run->id)->exists());
    }

    public function test_runs_are_chunked_and_not_reported_complete_until_every_chunk_is_done(): void
    {
        \Illuminate\Support\Facades\Queue::fake(); // drive the chunks by hand, deterministically
        config(['ranks.chunk_size' => 2]);
        $users = collect(range(1, 5))->map(fn () => tap($this->investor(), fn ($u) => $this->invest($u, 900)));
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]); // each user may already be handled by triggers
        $crit = \App\Models\RankCriterion::where('is_active', true)->first();

        $run = $this->engine()->startRun('manual', $crit, $this->admin);
        $this->assertSame(5, $run->total);

        $this->assertTrue($this->engine()->processNextChunk($run));
        $run->refresh();
        $this->assertNotSame('completed', $run->status);
        $this->assertLessThan($run->total, $run->processed + 1);

        for ($i = 0; $i < 10 && $this->engine()->processNextChunk($run); $i++);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(5, $run->processed);
        $users->each(fn ($u) => $this->assertSame('silver', $this->freshRank($u)));
    }

    public function test_investors_are_never_automatically_downgraded_and_are_flagged_for_review_once(): void
    {
        $u = $this->investor(); $this->invest($u, 2000);
        $gold = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)])['criterion']['id'];
        $this->engine()->evaluateUser($u->id);
        $this->assertSame('gold', $this->freshRank($u));

        // Management raises the bar above what the investor has.
        $this->putJson("/api/admin/ranks/criteria/{$gold}", [
            'name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 9000)],
        ])->assertOk();

        for ($i = 0; $i < 4; $i++) $this->engine()->evaluateUser($u->id);

        $this->assertSame('gold', $this->freshRank($u));                       // rank untouched
        $this->assertSame(0, InvestorRankChange::where('investor_id', $u->id)->where('change_type', 'downgrade')->count());
        $this->assertSame(1, RankDowngradeReview::where('investor_id', $u->id)->count());   // one review, no repeats
        $this->assertSame('open', RankDowngradeReview::where('investor_id', $u->id)->value('status'));
        $this->assertNotEmpty(RankDowngradeReview::where('investor_id', $u->id)->first()->failed_conditions);
    }

    public function test_making_a_live_rule_stricter_flags_existing_holders_without_a_manual_run(): void
    {
        $u = $this->investor(); $this->invest($u, 2000);
        $gold = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)])['criterion']['id'];
        $this->assertSame('gold', $this->freshRank($u));

        $this->putJson("/api/admin/ranks/criteria/{$gold}", [
            'name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 9000)],
        ])->assertOk();

        // No explicit evaluateUser(): the criteria-change run itself must cover current holders.
        $this->assertSame('gold', $this->freshRank($u));                                   // never downgraded
        $this->assertSame(1, RankDowngradeReview::where('investor_id', $u->id)->where('status', 'open')->count());
    }

    public function test_review_clears_itself_if_the_investor_qualifies_again(): void
    {
        $u = $this->investor(); $this->invest($u, 2000);
        $gold = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)])['criterion']['id'];
        $this->engine()->evaluateUser($u->id);
        $this->putJson("/api/admin/ranks/criteria/{$gold}", ['name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 9000)]]);
        $this->engine()->evaluateUser($u->id);
        $this->assertSame(1, RankDowngradeReview::open()->count());

        $this->invest($u, 8000);
        $this->engine()->evaluateUser($u->id);
        $this->assertSame(0, RankDowngradeReview::open()->count());
    }

    public function test_deactivating_criteria_never_changes_existing_ranks(): void
    {
        $u = $this->investor(); $this->invest($u, 2000);
        $id = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)])['criterion']['id'];
        $this->engine()->evaluateUser($u->id);

        $this->postJson("/api/admin/ranks/criteria/{$id}/deactivate")->assertOk();
        $this->engine()->evaluateUser($u->id);

        $this->assertSame('gold', $this->freshRank($u));
    }

    public function test_investment_event_triggers_evaluation_for_that_investor(): void
    {
        $u = $this->investor();
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);

        \Illuminate\Support\Facades\Queue::fake();
        $this->invest($u, 900);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\EvaluateInvestorRankJob::class,
            fn ($job) => $job->userId === $u->id);
    }

    public function test_rank_upgrades_do_not_touch_money_or_create_financial_records(): void
    {
        $u = $this->investor(['balance' => 1234.56]);
        $inv = $this->invest($u, 2000);
        $tables = array_values(array_filter(['deposits', 'withdrawals', 'balance_adjustments', 'financial_audit_logs', 'transactions'],
            fn ($t) => Schema::hasTable($t)));
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $invBefore = DB::table('investment_accounts')->where('id', $inv->id)->first();

        $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)]);
        $this->engine()->evaluateUser($u->id);
        $this->assertSame('gold', $this->freshRank($u));

        $this->assertEquals(1234.56, (float) $u->fresh()->balance);
        foreach ($tables as $t) $this->assertSame($before[$t], DB::table($t)->count(), "$t changed");
        $invAfter = DB::table('investment_accounts')->where('id', $inv->id)->first();
        $this->assertEquals($invBefore->amount, $invAfter->amount);
        $this->assertEquals($invBefore->expected_profit, $invAfter->expected_profit);
        $this->assertSame($invBefore->status, $invAfter->status);
    }

    public function test_only_investors_are_ranked(): void
    {
        $this->createCriterion('bronze', [$this->cond('account_age_days', '>=', 0)]);
        $this->engine()->evaluateUser($this->admin->id);
        $this->assertNull($this->admin->fresh()->investor_rank_id);
    }

    public function test_manual_full_reevaluation_endpoint_requires_active_criteria_and_returns_progress(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/runs')->assertStatus(422);

        $this->investor();
        $this->createCriterion('bronze', [$this->cond('account_age_days', '>=', 0)]);
        $res = $this->postJson('/api/admin/ranks/runs');
        $this->assertContains($res->status(), [202, 409]);
        $this->getJson('/api/admin/ranks/runs')->assertOk()->assertJsonStructure(['data']);
    }
}
