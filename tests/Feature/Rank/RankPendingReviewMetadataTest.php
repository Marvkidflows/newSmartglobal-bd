<?php
// LOCATION: tests/Feature/Rank/RankPendingReviewMetadataTest.php
//
// Regression: when the criteria for the SAME proposed rank change while a review is pending, the pending review must keep
// its identity/status and raise no second alert, but its metadata (evaluation snapshot, rank_criterion_id, basis_hash,
// evaluation_run_id) must follow the rules/run it was last evaluated under. Approval-time revalidation is unchanged.

namespace Tests\Feature\Rank;

use App\Models\RankCriterion;
use App\Models\RankReview;
use App\Services\Rank\RankEvaluationService;
use Illuminate\Support\Facades\DB;

class RankPendingReviewMetadataTest extends RankTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ranks.require_approval' => true]);     // the real default: the engine only raises pending reviews
    }

    protected function evaluate($user, $run = null): void
    {
        app(RankEvaluationService::class)->evaluateUser($user->id, $run ? 'run' : 'test', $run);
    }

    protected function editBronze(int $id, float $minAmount): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/ranks/criteria/{$id}", [
            'name' => 'Bronze criteria', 'logic' => 'all',
            'conditions' => [$this->cond('total_invested', '>=', $minAmount), $this->cond('completed_investment_count', '>=', 1)],
        ])->assertOk();
    }

    public function test_changed_criteria_for_the_same_rank_refresh_the_pending_review_without_a_second_review_or_alert(): void
    {
        $this->createCriterion('bronze', [$this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1)]);
        $u = $this->investorWith(150, 1);                          // qualifies for Bronze before AND after the edit
        $this->evaluate($u);

        $review   = RankReview::pending()->where('investor_id', $u->id)->sole();
        $original = $review->only(['id', 'reference', 'pending_key', 'basis_hash', 'created_at']);
        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'rank_review_pending')->count());

        // The rules for the SAME proposed rank change ($100 → $120) while the review is still pending.
        $bronzeId = (int) RankCriterion::where('investor_rank_id', $this->rank('bronze')->id)->value('id');
        $this->editBronze($bronzeId, 120);
        $this->evaluate($u);

        $after = RankReview::findOrFail($original['id']);
        $this->assertSame('pending', $after->status);                                   // still pending …
        $this->assertSame($original['reference'], $after->reference);                   // … same identity …
        $this->assertSame($original['pending_key'], $after->pending_key);
        $this->assertTrue($original['created_at']->equalTo($after->created_at));
        $this->assertSame(1, RankReview::where('investor_id', $u->id)->count());        // … no duplicate review …
        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'rank_review_pending')->count());   // … no new alert

        $liveId = (int) RankCriterion::where('is_active', true)->where('investor_rank_id', $this->rank('bronze')->id)->value('id');
        $this->assertSame($liveId, (int) $after->rank_criterion_id);                    // points at the criterion actually evaluated
        $this->assertNotSame($original['basis_hash'], $after->basis_hash);              // and at the NEW rules
        $threshold = collect($after->evaluation['evaluation']['conditions'])->firstWhere('field', 'total_invested')['expected'];
        $this->assertEquals(120, $threshold);                                           // snapshot shows the new rule

        $this->assertNull($this->freshRank($u));                                        // evaluation never awards a rank
    }

    public function test_evaluation_run_id_follows_a_new_run_and_is_kept_when_there_is_no_run(): void
    {
        $this->createCriterion('bronze', [$this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1)]);
        $u = $this->investorWith(150, 1);
        $this->evaluate($u);
        $id = (int) RankReview::pending()->where('investor_id', $u->id)->value('id');

        DB::table('rank_reviews')->where('id', $id)->update(['evaluation_run_id' => 4242]);
        $this->evaluate($u);                                                            // no run supplied
        $this->assertSame(4242, (int) RankReview::find($id)->evaluation_run_id);        // previous run id preserved

        $run = app(RankEvaluationService::class)->startRun('manual', null, $this->admin);
        $this->evaluate($u, $run);                                                      // a new run evaluates the investor
        $this->assertSame($run->id, (int) RankReview::find($id)->evaluation_run_id);    // now cites that run

        $this->evaluate($u);                                                            // and a later run-less evaluation keeps it
        $this->assertSame($run->id, (int) RankReview::find($id)->evaluation_run_id);
        $this->assertSame(1, RankReview::where('investor_id', $u->id)->count());
    }

    public function test_a_rejection_made_after_the_criteria_changed_is_not_re_raised(): void
    {
        $this->createCriterion('bronze', [$this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1)]);
        $u = $this->investorWith(150, 1);
        $this->evaluate($u);
        $review = RankReview::pending()->where('investor_id', $u->id)->sole();

        $this->editBronze((int) RankCriterion::where('investor_rank_id', $this->rank('bronze')->id)->value('id'), 120);
        $this->evaluate($u);                                                            // pending review now reflects the new rules

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/approvals/{$review->id}/reject", ['reason' => 'Source of funds not confirmed'])->assertOk();
        $this->evaluate($u); $this->evaluate($u);

        // The rejection stands under the rules it was made under. (With a stale basis_hash a new review was raised here.)
        $this->assertSame(0, RankReview::pending()->where('investor_id', $u->id)->count());
        $this->assertSame(1, RankReview::where('investor_id', $u->id)->count());
        $this->assertSame('rejected', RankReview::find($review->id)->status);
        $this->assertNull($this->freshRank($u));
    }

    public function test_approval_still_revalidates_against_current_data_and_never_touches_money(): void
    {
        $this->createCriterion('bronze', [$this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1)]);
        $u = $this->investorWith(150, 1);
        $this->evaluate($u);
        $review = RankReview::pending()->where('investor_id', $u->id)->sole();

        $this->editBronze((int) RankCriterion::where('investor_rank_id', $this->rank('bronze')->id)->value('id'), 120);
        $this->evaluate($u);                                                            // metadata refreshed; still pending
        $this->assertSame('pending', RankReview::find($review->id)->status);

        // The source record becomes invalid WITHOUT a model event, so the review stays pending and only the
        // approval-time check (under the row lock) can stop the award.
        DB::table('investment_accounts')->where('user_id', $u->id)->update(['status' => 'cancelled']);
        $money = fn () => [DB::table('users')->where('id', $u->id)->value('balance'), DB::table('investment_accounts')->count(), DB::table('deposits')->count()];
        $before = $money();

        $res = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/approvals/{$review->id}/approve");
        $res->assertStatus(422);
        $this->assertStringContainsString('no longer meets', json_encode($res->json()));

        $this->assertNull($this->freshRank($u));
        $this->assertSame('pending', RankReview::find($review->id)->status);            // refused, not decided
        $this->assertSame($before, $money());                                           // no financial side effects
    }
}