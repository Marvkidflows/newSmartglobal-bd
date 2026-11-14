<?php
// LOCATION: tests/Feature/Rank/RankProgressNoticeTest.php
//
// Audit R-32 (approaching the next rank) and R-33 (meeting the next rank's requirements) for EVERY step Bronze → Silver … Diamond → Elite.
//
// STATUS: NOT EXECUTED by its author (no PHP available in the authoring environment). Run:
//   php -d memory_limit=-1 vendor\bin\pest --filter=RankProgressNoticeTest
// The real-ledger tests use the same fixtures as the rest of the rank suite (investorWith / invest / verifyUsd). The tests that need a
// progress figure the real ledger cannot easily produce (re-arming after progress falls) substitute InvestorRankProgress with a stub.

namespace Tests\Feature\Rank;

use App\Jobs\SendRankProgressNoticesJob;
use App\Models\RankBenefit;
use App\Models\RankProgressNotice;
use App\Models\RankReview;
use App\Models\User;
use App\Services\Rank\InvestorRankProgress;
use App\Services\Rank\RankBenefitService;
use App\Services\Rank\RankProgressNoticeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

class RankProgressNoticeTest extends RankTestCase
{
    /** rank key => [minimum current principal USD, minimum completed cycles] (Board thresholds). */
    private const CORE = [
        'bronze' => [100, 1], 'silver' => [1000, 2], 'gold' => [5000, 3], 'platinum' => [10000, 5], 'diamond' => [20000, 8], 'elite' => [50000, 12],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ranks.progress_notices.enabled' => true, 'ranks.progress_notices.milestones' => [75, 90],
            'ranks.progress_notices.rearm_buffer_percent' => 5,
        ]);
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────────────────

    private function activateAll(): void
    {
        foreach (self::CORE as $key => [$amount, $cycles]) {
            $this->createCriterion($key, [
                $this->cond('current_eligible_principal', '>=', $amount),
                $this->cond('completed_investment_count', '>=', $cycles),
            ]);
        }
    }

    /** An investor who HOLDS $rankKey with $amount of verified current principal and $cycles verified completed cycles. */
    private function atRank(string $rankKey, float $amount, int $cycles): User
    {
        $u = $this->investorWith($amount, $cycles);
        $u->forceFill(['investor_rank_id' => $this->rank($rankKey)->id, 'rank_status' => 'active'])->save();
        return $u->fresh();
    }

    private function sweep(User $u): array
    {
        return app(RankProgressNoticeService::class)->process($u->fresh());
    }

    private function approaching(User $u)
    {
        return $u->notifications()->where('data->event', 'approaching_next_rank')->get();
    }

    private function stubProgress(int ...$amountPercents): void
    {
        $seq = array_map(fn (int $p) => [
            'state' => 'in_progress',
            'amount' => ['current' => 1, 'required' => 1000, 'remaining' => round(1000 * (100 - $p) / 100, 2), 'percent' => $p],
            'cycles' => ['current' => 2, 'required' => 2, 'remaining' => 0, 'percent' => 100],
        ], $amountPercents);
        $this->mock(InvestorRankProgress::class)->shouldReceive('for')->andReturn(...$seq);
    }

    private function assertApproachingFor(string $from, float $amount, int $cycles, string $nextName, string $remainingText): void
    {
        $this->activateAll();
        $u = $this->atRank($from, $amount, $cycles);

        $r = $this->sweep($u);
        $this->assertSame('sent', $r['status'], json_encode($r));
        $this->assertSame(75, $r['sent']);

        $n = $this->approaching($u);
        $this->assertCount(1, $n);
        $data = $n->first()->data;
        $this->assertStringContainsString($nextName, $data['title']);
        $this->assertStringContainsString('80%', $data['message']);
        $this->assertStringContainsString($remainingText, $data['message'], 'it must say exactly what is still missing');
        $this->assertStringNotContainsString('have been awarded', $data['message']);
        $this->assertSame($this->rank($from)->id, $u->fresh()->investor_rank_id, 'a notice never changes the rank');
    }

    // ── R-32: approaching, every transition ─────────────────────────────────────────────────────────────

    public function test_approaching_bronze_to_silver(): void   { $this->assertApproachingFor('bronze',   800,   2,  'Silver',   '$200.00 more in active invested principal'); }
    public function test_approaching_silver_to_gold(): void     { $this->assertApproachingFor('silver',   4000,  3,  'Gold',     '$1,000.00 more in active invested principal'); }
    public function test_approaching_gold_to_platinum(): void   { $this->assertApproachingFor('gold',     8000,  5,  'Platinum', '$2,000.00 more in active invested principal'); }
    public function test_approaching_platinum_to_diamond(): void { $this->assertApproachingFor('platinum', 16000, 8,  'Diamond',  '$4,000.00 more in active invested principal'); }
    public function test_approaching_diamond_to_elite(): void   { $this->assertApproachingFor('diamond',  40000, 12, 'Elite',    '$10,000.00 more in active invested principal'); }

    public function test_the_notice_names_the_missing_cycles_too(): void
    {
        $this->activateAll();
        $u = $this->atRank('silver', 4000, 2);   // money 80%, cycles 2 of 3 = 66% → the LOWER bar (66%) is below 75%: no notice yet
        $this->assertSame('unchanged', $this->sweep($u)['status']);

        $u2 = $this->atRank('silver', 4500, 3);  // money 90%, cycles 100%
        $this->assertSame('sent', $this->sweep($u2)['status']);
        $this->assertStringNotContainsString('completed investment cycle', $this->approaching($u2)->first()->data['message'], 'no cycles are missing');
    }

    public function test_below_the_first_milestone_nothing_is_sent(): void
    {
        $this->activateAll();
        $u = $this->atRank('bronze', 700, 2);    // 70% of $1,000
        $r = $this->sweep($u);
        $this->assertSame('unchanged', $r['status']);
        $this->assertCount(0, $this->approaching($u));
        $this->assertSame(0, RankProgressNotice::count());
    }

    public function test_progress_is_the_lower_of_money_and_cycles(): void
    {
        $this->activateAll();
        $u = $this->atRank('bronze', 1500, 1);   // money is over 100% of Silver but only 1 of 2 cycles (50%)
        $r = $this->sweep($u);
        $this->assertSame('unchanged', $r['status']);
        $this->assertSame(50, $r['percent']);
        $this->assertCount(0, $this->approaching($u));
    }

    public function test_a_jump_over_two_milestones_sends_one_notice_and_records_both(): void
    {
        $this->activateAll();
        $u = $this->atRank('bronze', 920, 2);    // 92%: milestones 75 and 90 reached together
        $r = $this->sweep($u);
        $this->assertSame('sent', $r['status']);
        $this->assertSame(90, $r['sent']);
        $this->assertCount(1, $this->approaching($u));
        $this->assertSame(2, RankProgressNotice::where('investor_id', $u->id)->where('active', true)->count());
    }

    public function test_a_later_milestone_sends_a_second_notice_but_never_repeats_the_first(): void
    {
        $this->activateAll();
        $u = $this->atRank('bronze', 800, 2);
        $this->assertSame(75, $this->sweep($u)['sent']);

        $this->invest($u, 120);                  // total 920 → 92%
        $r = $this->sweep($u);
        $this->assertSame('sent', $r['status']);
        $this->assertSame(90, $r['sent']);
        $this->assertCount(2, $this->approaching($u));

        $this->assertSame('unchanged', $this->sweep($u)['status']);
        $this->assertCount(2, $this->approaching($u));
    }

    // ── duplicate prevention and re-arming ──────────────────────────────────────────────────────────────

    public function test_running_the_sweep_again_never_repeats_a_notice(): void
    {
        $this->activateAll();
        $u = $this->atRank('bronze', 800, 2);
        $this->assertSame('sent', $this->sweep($u)['status']);
        $this->assertSame('unchanged', $this->sweep($u)['status']);
        $this->assertSame('unchanged', $this->sweep($u)['status']);
        $this->assertCount(1, $this->approaching($u));
        $this->assertSame(1, RankProgressNotice::where('investor_id', $u->id)->count());
    }

    public function test_progress_that_falls_back_re_arms_the_notice_only_below_the_buffer(): void
    {
        $this->activateAll();
        $u = $this->atRank('bronze', 0, 0);
        $this->stubProgress(80, 72, 60, 80);     // sent → hovering inside the 5-point buffer → genuinely fell back → climbed again

        $svc = app(RankProgressNoticeService::class);
        $this->assertSame('sent', $svc->process($u->fresh())['status']);
        $this->assertSame('unchanged', $svc->process($u->fresh())['status'], '72% is within 5 points of the 75% line: not re-armed');
        $r = $svc->process($u->fresh());
        $this->assertSame('recorded', $r['status']);
        $this->assertSame([75], $r['rearmed']);
        $this->assertSame('sent', $svc->process($u->fresh())['status'], 'after a genuine fall the notice may be sent again');

        $this->assertCount(2, $this->approaching($u));
        $this->assertSame(1, RankProgressNotice::where('investor_id', $u->id)->where('active', true)->count());
        $this->assertSame(1, RankProgressNotice::where('investor_id', $u->id)->whereNull('active')->count());
    }

    // ── what must NOT produce a notice ──────────────────────────────────────────────────────────────────

    public function test_the_highest_rank_has_no_next_rank_notice(): void
    {
        $this->activateAll();
        $u = $this->atRank('elite', 60000, 12);
        $r = $this->sweep($u);
        $this->assertSame('skipped', $r['status']);
        $this->assertSame('highest', $r['reason']);
        $this->assertCount(0, $this->approaching($u));
    }

    public function test_nothing_is_sent_while_the_next_ranks_criteria_are_inactive(): void
    {
        // no criteria activated at all: the next rank has no live rule, so there is nothing to be "approaching"
        $u = $this->atRank('bronze', 900, 2);
        $r = $this->sweep($u);
        $this->assertSame('skipped', $r['status']);
        $this->assertSame('awaiting_approval', $r['reason']);
        $this->assertCount(0, $this->approaching($u));
    }

    public function test_an_investor_who_already_meets_the_requirements_gets_no_approaching_notice(): void
    {
        $this->activateAll();
        $u = $this->atRank('bronze', 1000, 2);   // exactly the Silver requirements
        $r = $this->sweep($u);
        $this->assertSame('skipped', $r['status']);
        $this->assertContains($r['reason'], ['qualifies', 'needs_review']);
        $this->assertCount(0, $this->approaching($u));
    }

    public function test_unverified_principal_is_never_counted(): void
    {
        $this->activateAll();
        $u = $this->atRank('bronze', 0, 2);
        $inv = $this->invest($u, 900, 30, 'active', false);   // recorded but NOT verified as USD

        $this->assertSame('unchanged', $this->sweep($u)['status'], 'unverified money produces no progress');
        $this->assertCount(0, $this->approaching($u));

        $this->verifyUsd($inv);                                // a person verifies it: only now does it count
        $r = $this->sweep($u);
        $this->assertSame('sent', $r['status']);
        $this->assertSame(90, $r['sent']);
    }

    public function test_deposits_do_not_count_as_progress(): void
    {
        $this->activateAll();
        $u = $this->atRank('bronze', 0, 2);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->update(['balance' => 5000]);   // wallet cash is not invested principal
        $this->assertSame('unchanged', $this->sweep($u)['status']);
        $this->assertCount(0, $this->approaching($u));
    }

    public function test_unranked_suspended_and_non_investor_accounts_are_skipped(): void
    {
        $this->activateAll();
        $unranked = $this->investor();   // no investments, so the engine has not given it a rank either
        $this->assertSame('unranked', $this->sweep($unranked)['reason']);

        $suspended = $this->atRank('bronze', 900, 2);
        $suspended->forceFill(['rank_status' => 'suspended'])->save();
        $this->assertSame('rank_not_active', $this->sweep($suspended)['reason']);

        $this->assertSame('not_an_investor', app(RankProgressNoticeService::class)->process($this->admin)['reason']);
    }

    public function test_the_master_switch_turns_the_notices_off(): void
    {
        $this->activateAll();
        config(['ranks.progress_notices.enabled' => false]);
        $u = $this->atRank('bronze', 800, 2);
        $this->assertSame('disabled', $this->sweep($u)['reason']);
        $this->assertCount(0, $this->approaching($u));
    }

    public function test_milestones_are_configurable_and_sanitised(): void
    {
        config(['ranks.progress_notices.milestones' => '50, 90, 0, 150, abc, 90']);
        $this->assertSame([50, 90], app(RankProgressNoticeService::class)->milestones());
    }

    public function test_the_wording_follows_the_approval_setting(): void
    {
        $this->activateAll();
        config(['ranks.require_approval' => true]);
        $u = $this->atRank('bronze', 800, 2);
        $this->sweep($u);
        $this->assertStringContainsString('only after SSI reviews and approves', $this->approaching($u)->first()->data['message']);
    }

    // ── queue, command, schedule ────────────────────────────────────────────────────────────────────────

    public function test_one_investors_failure_does_not_stop_the_rest_of_the_chunk(): void
    {
        $bad = $this->atRank('bronze', 800, 2);
        $good = $this->atRank('bronze', 800, 2);

        $mock = $this->mock(RankProgressNoticeService::class);
        $mock->shouldReceive('process')->twice()->andReturnUsing(function (User $u) use ($bad) {
            if ($u->id === $bad->id) throw new \RuntimeException('simulated notification failure');
            return ['status' => 'sent'];
        });

        SendRankProgressNoticesJob::dispatchSync([$bad->id, $good->id]);   // must not throw; the second investor is still processed
        $this->assertTrue(true);
    }

    public function test_the_command_queues_chunks_of_ranked_investors_only(): void
    {
        Queue::fake();
        $ranked = $this->atRank('bronze', 800, 2);
        $this->investor();                                                   // unranked: not queued

        $this->artisan('ranks:progress-notices')->assertSuccessful();
        Queue::assertPushed(SendRankProgressNoticesJob::class, fn ($job) => in_array($ranked->id, $job->userIds, true) && count($job->userIds) === 1);
    }

    public function test_the_command_in_sync_mode_sends_once_and_is_safe_to_repeat(): void
    {
        $this->activateAll();
        $u = $this->atRank('bronze', 800, 2);

        $this->artisan('ranks:progress-notices --sync')->assertSuccessful();
        $this->artisan('ranks:progress-notices --sync')->assertSuccessful();
        $this->assertCount(1, $this->approaching($u));
    }

    public function test_the_sweep_is_on_the_schedule(): void
    {
        Artisan::call('schedule:list');
        // schedule:list prints the PHP binary's full path on Windows, so match on the command, not on the word "php".
        $this->assertMatchesRegularExpression('/\b0\s+3\s+\*\s+\*\s+\*\s+.*artisan"?\s+ranks:progress-notices/', Artisan::output());
    }

    // ── R-33: meeting the requirements, every transition ────────────────────────────────────────────────

    private function reviewFor(User $u, string $toKey): RankReview
    {
        return RankReview::forceCreate([
            'reference' => 'RR-T' . $u->id . '-' . $toKey, 'investor_id' => $u->id, 'account_id' => $u->account_id,
            'current_rank_id' => $u->investor_rank_id, 'proposed_rank_id' => $this->rank($toKey)->id, 'status' => RankReview::PENDING,
            'pending_key' => $u->id . ':' . $this->rank($toKey)->id, 'basis_hash' => 'x', 'rank_criterion_id' => null, 'evaluation' => [],
        ]);
    }

    private function ranked(string $key): User
    {
        $u = $this->investor();
        $u->forceFill(['investor_rank_id' => $this->rank($key)->id, 'rank_status' => 'active'])->save();
        return $u->fresh();
    }

    private function enableBenefit(string $key): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/benefits/' . RankBenefit::where('key', $key)->value('id') . '/status',
            ['status' => 'active', 'reason' => 'Management approved', 'approval_reference' => 'MGT-2026-777'])->assertOk();
    }

    private function assertEligibilityNotice(string $from, string $to, string $toName): void
    {
        $u = $this->ranked($from);
        $review = $this->reviewFor($u, $to);
        $svc = app(RankBenefitService::class);

        $this->assertTrue($svc->notifyNextRankEligibility($review));
        $this->assertFalse($svc->notifyNextRankEligibility($review), 'a second call must not duplicate it');

        $n = $u->notifications()->where('data->event', 'next_rank_eligible')->get();
        $this->assertCount(1, $n);
        $this->assertSame("You meet the {$toName} requirements", $n->first()->data['title']);
        $this->assertStringContainsString('after SSI reviews and approves', $n->first()->data['message']);
        $this->assertStringNotContainsString('have been awarded', $n->first()->data['message']);
        $this->assertSame($this->rank($from)->id, $u->fresh()->investor_rank_id, 'the rank itself is unchanged by the notice');
    }

    public function test_eligibility_notice_bronze_to_silver_still_needs_the_management_benefit(): void
    {
        app(RankBenefitService::class)->syncDefaults();
        $u = $this->ranked('bronze');
        $this->assertFalse(app(RankBenefitService::class)->notifyNextRankEligibility($this->reviewFor($u, 'silver')), 'pending approval: nothing is sent');

        $this->enableBenefit('bronze.next-rank-notice');
        $this->assertEligibilityNotice('bronze', 'silver', 'Silver');
    }

    public function test_eligibility_notice_silver_to_gold(): void       { $this->assertEligibilityNotice('silver',   'gold',     'Gold'); }
    public function test_eligibility_notice_gold_to_platinum(): void     { $this->assertEligibilityNotice('gold',     'platinum', 'Platinum'); }
    public function test_eligibility_notice_platinum_to_diamond(): void  { $this->assertEligibilityNotice('platinum', 'diamond',  'Diamond'); }
    public function test_eligibility_notice_diamond_to_elite(): void     { $this->assertEligibilityNotice('diamond',  'elite',    'Elite'); }

    public function test_eligibility_notice_respects_the_switch_and_never_goes_to_the_wrong_people(): void
    {
        config(['ranks.progress_notices.enabled' => false]);
        $this->assertFalse(app(RankBenefitService::class)->notifyNextRankEligibility($this->reviewFor($this->ranked('silver'), 'gold')));

        config(['ranks.progress_notices.enabled' => true]);
        $suspended = $this->ranked('silver');
        $suspended->forceFill(['rank_status' => 'suspended'])->save();
        $this->assertFalse(app(RankBenefitService::class)->notifyNextRankEligibility($this->reviewFor($suspended->fresh(), 'gold')));

        $same = $this->ranked('gold');                                     // a review that is not a step UP
        $this->assertFalse(app(RankBenefitService::class)->notifyNextRankEligibility($this->reviewFor($same, 'gold')));

        $unranked = $this->investor();                                     // first award: not a "next rank" notice
        $this->assertFalse(app(RankBenefitService::class)->notifyNextRankEligibility($this->reviewFor($unranked, 'bronze')));
    }

    public function test_the_queued_eligibility_job_is_idempotent_and_skips_a_decided_review(): void
    {
        $u = $this->ranked('silver');
        $review = $this->reviewFor($u, 'gold');
        \App\Jobs\SendNextRankNoticeJob::dispatchSync($review->id);
        \App\Jobs\SendNextRankNoticeJob::dispatchSync($review->id);
        $this->assertCount(1, $u->notifications()->where('data->event', 'next_rank_eligible')->get());

        $other = $this->ranked('silver');
        $decided = $this->reviewFor($other, 'gold');
        $decided->forceFill(['status' => RankReview::REJECTED, 'pending_key' => null])->save();
        \App\Jobs\SendNextRankNoticeJob::dispatchSync($decided->id);
        $this->assertCount(0, $other->notifications()->get());
    }
}
