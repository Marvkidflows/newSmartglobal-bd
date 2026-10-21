<?php
// LOCATION: tests/Feature/Rank/RankManualChangeTest.php

namespace Tests\Feature\Rank;

use App\Models\InvestorRankChange;
use App\Models\RankDowngradeReview;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;

class RankManualChangeTest extends RankTestCase
{
    protected function assign(User $u, string $rank, array $extra = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/assign",
            array_merge(['rank_id' => $this->rank($rank)->id, 'reason' => 'Management decision'], $extra));
    }

    protected function downgrade(User $u, string $rank, array $extra = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/downgrade",
            array_merge(['rank_id' => $this->rank($rank)->id, 'reason' => 'No longer qualifies'], $extra));
    }

    public function test_admin_can_assign_then_raise_a_rank_manually_and_it_is_audited_as_manual(): void
    {
        $u = $this->investor();
        $this->assign($u, 'bronze')->assertOk();
        $this->assign($u, 'gold')->assertOk();

        $this->assertSame('gold', $this->freshRank($u));
        $rows = InvestorRankChange::where('investor_id', $u->id)->orderBy('sequence')->get();
        $this->assertSame(['manual_assignment', 'manual_upgrade'], $rows->pluck('change_type')->all());
        $this->assertTrue($rows->every(fn ($r) => !$r->is_automatic && $r->actor_id === $this->admin->id));
        $this->assertSame([1, 2], $rows->pluck('sequence')->all());
        $this->assertSame($rows[0]->new_rank_id, $rows[1]->previous_rank_id);
    }

    public function test_a_reason_is_required_for_every_manual_change(): void
    {
        $u = $this->investor();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/assign",
            ['rank_id' => $this->rank('gold')->id])->assertStatus(422);
        $this->assign($u, 'gold', ['reason' => 'ok'])->assertStatus(422);
        $this->assertNull($this->freshRank($u));
    }

    public function test_assigning_the_same_rank_again_is_rejected_without_a_duplicate_record(): void
    {
        $u = $this->investor();
        $this->assign($u, 'gold')->assertOk();
        $this->assign($u, 'gold')->assertStatus(422);
        $this->assertSame(1, InvestorRankChange::where('investor_id', $u->id)->count());
    }

    public function test_manual_assignment_is_checked_against_active_criteria_and_override_is_off_by_default(): void
    {
        $u = $this->investor(); $this->invest($u, 100);
        $this->createCriterion('gold', [$this->cond('total_invested', '>=', 5000)]);

        $res = $this->assign($u, 'gold', ['override' => true, 'override_justification' => 'CEO approved exception']);
        $res->assertStatus(422)->assertJsonValidationErrors('override');
        $this->assertNull($this->freshRank($u));
    }

    public function test_authorized_override_when_management_enables_it_requires_justification_and_is_recorded(): void
    {
        config(['ranks.allow_manual_override' => true]);
        $u = $this->investor(); $this->invest($u, 100);
        $this->createCriterion('gold', [$this->cond('total_invested', '>=', 5000)]);

        $this->assign($u, 'gold')->assertStatus(422)->assertJsonValidationErrors('override');
        $this->assign($u, 'gold', ['override' => true, 'override_justification' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('override_justification');
        $this->assertNull($this->freshRank($u));

        $this->assign($u, 'gold', ['override' => true, 'override_justification' => 'Approved by Management on 14 Oct'])->assertOk();

        $this->assertSame('gold', $this->freshRank($u));
        $row = InvestorRankChange::where('investor_id', $u->id)->sole();
        $this->assertSame('authorized_override', $row->change_type);
        $this->assertSame($this->admin->id, $row->actor_id);
        $this->assertSame('Approved by Management on 14 Oct', $row->override_justification);
        $this->assertFalse($row->criteria_snapshot['evaluation']['satisfied']);

        // The engine must not immediately raise a downgrade alert for what Admin just approved.
        app(RankEvaluationService::class)->evaluateUser($u->id);
        $this->assertSame(0, RankDowngradeReview::open()->count());
    }

    public function test_downgrade_requires_a_reason_and_is_recorded_with_previous_new_actor_and_time(): void
    {
        $u = $this->investor();
        $this->assign($u, 'platinum')->assertOk();

        $this->downgrade($u, 'silver', ['reason' => 'x'])->assertStatus(422);
        $this->assertSame('platinum', $this->freshRank($u));

        $this->downgrade($u, 'silver', ['reason' => 'Fell below required activity'])->assertOk();

        $this->assertSame('silver', $this->freshRank($u));
        $row = InvestorRankChange::where('investor_id', $u->id)->orderByDesc('sequence')->first();
        $this->assertSame('downgrade', $row->change_type);
        $this->assertSame($this->rank('platinum')->id, $row->previous_rank_id);
        $this->assertSame($this->rank('silver')->id, $row->new_rank_id);
        $this->assertSame($this->admin->id, $row->actor_id);
        $this->assertSame('Fell below required activity', $row->reason);
        $this->assertNotNull($row->created_at);
        $this->assertFalse($row->is_automatic);
    }

    public function test_downgrade_endpoint_refuses_a_non_lower_target_and_assign_refuses_a_lower_one(): void
    {
        $u = $this->investor();
        $this->assign($u, 'gold')->assertOk();
        $this->downgrade($u, 'platinum')->assertStatus(422);
        $this->assign($u, 'silver')->assertStatus(422);
        $this->assertSame('gold', $this->freshRank($u));
    }

    public function test_admin_decides_a_review_by_downgrading_and_the_review_is_closed(): void
    {
        $u = $this->investor(); $this->invest($u, 2000);
        $gold = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)])['criterion']['id'];
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);
        app(RankEvaluationService::class)->evaluateUser($u->id);
        $this->putJson("/api/admin/ranks/criteria/{$gold}", ['name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 9000)]])->assertOk();
        app(RankEvaluationService::class)->evaluateUser($u->id);

        $this->getJson('/api/admin/ranks/reviews')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/admin/ranks/investors/{$u->id}")->assertOk()->assertJsonPath('open_review.status', 'open');

        $this->downgrade($u, 'silver', ['reason' => 'Reviewed: below Gold requirement'])->assertOk();

        $this->assertSame('silver', $this->freshRank($u));
        $this->assertSame('downgraded', RankDowngradeReview::first()->status);
        $this->assertSame(0, RankDowngradeReview::open()->count());
    }

    public function test_admin_can_retain_the_rank_on_review_and_the_decision_is_audited_without_repeat_alerts(): void
    {
        $u = $this->investor(); $this->invest($u, 2000);
        $gold = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)])['criterion']['id'];
        app(RankEvaluationService::class)->evaluateUser($u->id);
        $this->putJson("/api/admin/ranks/criteria/{$gold}", ['name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 9000)]]);
        app(RankEvaluationService::class)->evaluateUser($u->id);
        $review = RankDowngradeReview::sole();

        $this->postJson("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => 'Long-standing client, keep Gold'])->assertOk();

        $this->assertSame('gold', $this->freshRank($u));
        $this->assertSame('retained', $review->fresh()->status);
        $this->assertSame('review_retained', InvestorRankChange::where('investor_id', $u->id)->orderByDesc('sequence')->value('change_type'));

        // Same unchanged shortfall: no new review / alert.
        app(RankEvaluationService::class)->evaluateUser($u->id);
        $this->assertSame(1, RankDowngradeReview::count());
        $this->postJson("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => 'Again please'])->assertStatus(422);
    }

    public function test_non_admins_cannot_assign_or_downgrade_even_by_direct_api_call(): void
    {
        $target = $this->investor();
        $self = $this->investor();
        $financial = User::factory()->create(['role' => 'financial', 'status' => 'active']);

        foreach ([$self, $financial] as $actor) {
            $this->actingAs($actor, 'sanctum');
            $this->postJson("/api/admin/ranks/investors/{$target->id}/assign", ['rank_id' => $this->rank('elite')->id, 'reason' => 'sneaky change'])->assertStatus(403);
            $this->postJson("/api/admin/ranks/investors/{$target->id}/downgrade", ['rank_id' => $this->rank('bronze')->id, 'reason' => 'sneaky change'])->assertStatus(403);
        }
        // an investor cannot rank THEMSELVES
        $this->actingAs($self, 'sanctum')->postJson("/api/admin/ranks/investors/{$self->id}/assign", ['rank_id' => $this->rank('elite')->id, 'reason' => 'self promote'])->assertStatus(403);

        $this->assertNull($this->freshRank($target));
        $this->assertNull($this->freshRank($self));
        $this->assertSame(0, InvestorRankChange::count());
    }

    public function test_rank_cannot_be_applied_to_a_non_investor_account(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$this->admin->id}/assign",
            ['rank_id' => $this->rank('gold')->id, 'reason' => 'wrong account'])->assertStatus(404);
    }

    public function test_investor_detail_shows_history_and_standing_against_active_rules(): void
    {
        $u = $this->investor(); $this->invest($u, 100);
        $this->createCriterion('gold', [$this->cond('total_invested', '>=', 5000)]);
        $this->assign($u, 'bronze')->assertOk();

        $res = $this->getJson("/api/admin/ranks/investors/{$u->id}")->assertOk();
        $res->assertJsonPath('investor.account_id', $u->account_id)
            ->assertJsonPath('history.0.change_type', 'manual_assignment')
            ->assertJsonPath('standing.0.satisfied', false)
            ->assertJsonPath('standing.0.conditions.0.passed', false);
    }
}
