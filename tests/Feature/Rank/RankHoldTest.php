<?php
// LOCATION: tests/Feature/Rank/RankHoldTest.php
// A manual downgrade must not be silently undone by the next automatic evaluation.

namespace Tests\Feature\Rank;

use App\Models\InvestorRankChange;
use App\Models\InvestorRankHold;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;

class RankHoldTest extends RankTestCase
{
    protected User $inv;
    protected int $goldId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv = $this->investor();
        $this->invest($this->inv, 2000);
        $this->goldId = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)])['criterion']['id'];
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 100)]);
        $this->engine()->evaluateUser($this->inv->id);
        $this->assertSame('gold', $this->freshRank($this->inv));
    }

    protected function engine(): RankEvaluationService
    {
        return app(RankEvaluationService::class);
    }

    protected function downgradeToSilver(array $extra = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$this->inv->id}/downgrade",
            array_merge(['rank_id' => $this->rank('silver')->id, 'reason' => 'Account review outcome'], $extra));
    }

    protected function allowOverride(): void
    {
        config(['ranks.allow_manual_override' => true]);
    }

    public function test_downgrading_an_investor_who_still_qualifies_is_refused_unless_policy_allows_it(): void
    {
        $this->downgradeToSilver()->assertStatus(422)->assertJsonValidationErrors('override');
        $this->assertSame('gold', $this->freshRank($this->inv));
        $this->assertSame(0, InvestorRankHold::count());
    }

    public function test_an_approved_exception_downgrade_is_not_undone_by_repeated_evaluation(): void
    {
        $this->allowOverride();
        $this->downgradeToSilver(['override' => true, 'override_justification' => 'Management-approved exception'])->assertOk();
        $this->assertSame('silver', $this->freshRank($this->inv));

        $hold = InvestorRankHold::active()->sole();
        $this->assertSame([$this->rank('gold')->id], array_column($hold->blocked, 'rank_id'));

        for ($i = 0; $i < 5; $i++) $this->engine()->evaluateUser($this->inv->id);

        $this->assertSame('silver', $this->freshRank($this->inv));
        $types = InvestorRankChange::where('investor_id', $this->inv->id)->orderBy('sequence')->pluck('change_type')->all();
        $this->assertSame(['automatic_upgrade', 'authorized_override'], $types);   // no silent re-upgrade row
        $row = InvestorRankChange::where('change_type', 'authorized_override')->sole();
        $this->assertSame($this->rank('gold')->id, $row->criteria_snapshot['held_ranks'][0]['rank_id']);
    }

    public function test_hold_is_released_when_the_investor_genuinely_stops_qualifying_and_later_qualifies_again(): void
    {
        $this->allowOverride();
        $this->downgradeToSilver(['override' => true, 'override_justification' => 'Management-approved exception'])->assertOk();

        // Eligibility legitimately changes: their only investment is cancelled → no longer meets Gold.
        \App\Models\InvestmentAccount::where('user_id', $this->inv->id)->first()->update(['status' => 'cancelled']);
        $this->engine()->evaluateUser($this->inv->id);
        $this->assertSame('silver', $this->freshRank($this->inv));
        $this->assertSame(0, InvestorRankHold::active()->count());                 // released

        // …and when they genuinely qualify again, the upgrade is legitimate.
        $this->invest($this->inv, 5000);
        $this->engine()->evaluateUser($this->inv->id);
        $this->assertSame('gold', $this->freshRank($this->inv));
    }

    public function test_changing_the_rules_conditions_releases_the_hold_but_renaming_does_not(): void
    {
        $this->allowOverride();
        $this->downgradeToSilver(['override' => true, 'override_justification' => 'Management-approved exception'])->assertOk();

        // Rename only: same conditions → hold stays.
        $this->putJson("/api/admin/ranks/criteria/{$this->goldId}", [
            'name' => 'Gold (renamed)', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 1000)],
        ])->assertOk();
        $this->engine()->evaluateUser($this->inv->id);
        $this->assertSame('silver', $this->freshRank($this->inv));
        $this->assertSame(1, InvestorRankHold::active()->count());

        // Real change to the rule's conditions → explicit criteria change, legitimate re-evaluation.
        $this->putJson("/api/admin/ranks/criteria/{$this->goldId}", [
            'name' => 'Gold (renamed)', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 1500)],
        ])->assertOk();
        $this->engine()->evaluateUser($this->inv->id);
        $this->assertSame('gold', $this->freshRank($this->inv));
        $this->assertSame(0, InvestorRankHold::active()->count());
    }

    public function test_a_manual_assignment_by_admin_clears_the_hold(): void
    {
        $this->allowOverride();
        $this->downgradeToSilver(['override' => true, 'override_justification' => 'Management-approved exception'])->assertOk();

        $this->postJson("/api/admin/ranks/investors/{$this->inv->id}/assign",
            ['rank_id' => $this->rank('gold')->id, 'reason' => 'Reinstated after review'])->assertOk();

        $this->assertSame('gold', $this->freshRank($this->inv));
        $this->assertSame(0, InvestorRankHold::active()->count());
    }

    public function test_an_ordinary_downgrade_of_a_non_qualifying_investor_needs_no_hold_and_is_not_undone(): void
    {
        // Raise the bar above what the investor has → they no longer qualify for Gold.
        $this->putJson("/api/admin/ranks/criteria/{$this->goldId}", [
            'name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 9000)],
        ])->assertOk();

        $this->downgradeToSilver()->assertOk();                                     // no override needed
        $this->assertSame(0, InvestorRankHold::count());

        for ($i = 0; $i < 3; $i++) $this->engine()->evaluateUser($this->inv->id);
        $this->assertSame('silver', $this->freshRank($this->inv));
        $this->assertSame('downgrade', InvestorRankChange::where('investor_id', $this->inv->id)->orderByDesc('sequence')->value('change_type'));
    }

    public function test_investor_detail_reports_the_active_hold(): void
    {
        $this->allowOverride();
        $this->downgradeToSilver(['override' => true, 'override_justification' => 'Management-approved exception'])->assertOk();

        $this->getJson("/api/admin/ranks/investors/{$this->inv->id}")->assertOk()
            ->assertJsonPath('hold.ranks.0.key', 'gold');
    }
}
