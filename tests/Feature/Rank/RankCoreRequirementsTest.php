<?php
// LOCATION: tests/Feature/Rank/RankCoreRequirementsTest.php
//
// The MANDATORY core requirements of the official SSI Investor Rank & Badge Management Technical Requirements:
//   Bronze $100+ & 1 cycle · Silver $1,000+ & 2 · Gold $5,000+ & 3 · Platinum $10,000+ & 5 · Diamond $20,000+ & 8 · Elite $50,000+ & 12
// Both the amount AND the completed-cycle requirement are mandatory for every rank. These tests run with the guard
// ON (the real default). Figures are typed literally so a wrong config cannot hide behind itself.
//
// NOT covered here (needs Management): whether the upper end of each band ($999, $4,999 …) is a hard ceiling.
// The engine grants the HIGHEST rank whose minimums are met; see RANK_MODULE.md.

namespace Tests\Feature\Rank;

use App\Models\InvestorRankChange;
use App\Models\RankCriterion;
use App\Services\Rank\RankEvaluationService;
use Illuminate\Support\Facades\DB;

class RankCoreRequirementsTest extends RankTestCase
{
    /** rank => [min USD, min completed cycles, next-lower rank key or null] */
    private const CORE = [
        'bronze'   => [100,   1,  null],
        'silver'   => [1000,  2,  'bronze'],
        'gold'     => [5000,  3,  'silver'],
        'platinum' => [10000, 5,  'gold'],
        'diamond'  => [20000, 8,  'platinum'],
        'elite'    => [50000, 12, 'diamond'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ranks.enforce_core_requirements' => true]);   // the REAL default
    }

    private function activateSpecCore(): void
    {
        foreach (self::CORE as $key => [$amount, $cycles]) {
            $this->createCriterion($key, [
                $this->cond('total_invested', '>=', $amount),
                $this->cond('completed_investment_count', '>=', $cycles),
            ]);
        }
    }

    /** Test fixture: an ACTIVE rule written straight into the tables, as if it already existed in the database. */
    private function storeActiveRule(string $rankKey, array $conditions): void
    {
        $c = RankCriterion::create([
            'investor_rank_id' => $this->rank($rankKey)->id, 'name' => ucfirst($rankKey) . ' (stored)',
            'logic' => 'all', 'is_active' => true, 'version' => 1, 'activated_at' => now(),
        ]);
        foreach ($conditions as $i => [$field, $op, $value]) {
            $c->conditions()->create(['group_no' => 1, 'field' => $field, 'operator' => $op, 'value' => $value, 'position' => $i]);
        }
    }

    private function evaluate($u): void
    {
        app(RankEvaluationService::class)->evaluateUser($u->id, 'test');
    }

    // ── the six thresholds: accepted, and both halves are enforced ─────────────

    public function test_the_six_specification_rules_can_be_activated(): void
    {
        $this->activateSpecCore();
        $this->assertSame(6, RankCriterion::where('is_active', true)->count());
    }

    public function test_each_rank_is_awarded_only_when_amount_and_cycles_are_both_met(): void
    {
        $this->activateSpecCore();

        foreach (self::CORE as $key => [$amount, $cycles, $lower]) {
            // exactly on both minimums → this rank
            $ok = $this->investorWith($amount, $cycles);
            $this->evaluate($ok);
            $this->assertSame($key, $this->freshRank($ok), "$key: \${$amount} and {$cycles} completed cycle(s) must qualify");

            // one cent short of the amount, cycles met → NOT this rank (at most the rank below)
            $short = $this->investorWith($amount - 0.01, $cycles);
            $this->evaluate($short);
            $this->assertNotSame($key, $this->freshRank($short), "$key: \${$amount} minus one cent must not qualify");

            // amount met, one cycle short → NOT this rank (at most the rank below)
            $fewCycles = $this->investorWith($amount, $cycles - 1);
            $this->evaluate($fewCycles);
            $this->assertNotSame($key, $this->freshRank($fewCycles), "$key: one completed cycle short must not qualify");
        }
    }

    public function test_bronze_needs_both_100_dollars_and_one_completed_cycle(): void
    {
        $this->activateSpecCore();

        $cycleOnlyButTooLittle = $this->investorWith(99.99, 1);   // cycle met, amount short
        $amountOnlyNoCycle     = $this->investorWith(100, 0);     // amount met, active investment only, 0 completed cycles
        $both                  = $this->investorWith(100, 1);

        foreach ([$cycleOnlyButTooLittle, $amountOnlyNoCycle, $both] as $u) $this->evaluate($u);

        $this->assertNull($this->freshRank($cycleOnlyButTooLittle));
        $this->assertNull($this->freshRank($amountOnlyNoCycle));
        $this->assertSame('bronze', $this->freshRank($both));
    }

    public function test_a_large_amount_never_replaces_the_cycle_requirement_and_cycles_never_replace_the_amount(): void
    {
        $this->activateSpecCore();

        $richNoCycles = $this->investorWith(1000000, 0);
        $manyCyclesSmall = $this->investorWith(99, 12);

        $this->evaluate($richNoCycles);
        $this->evaluate($manyCyclesSmall);

        $this->assertNull($this->freshRank($richNoCycles));
        $this->assertNull($this->freshRank($manyCyclesSmall));
    }

    // ── a rule missing either mandatory half is refused ────────────────────────

    public function test_rules_missing_a_mandatory_half_or_with_wrong_minimums_cannot_be_activated(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $bad = [
            'bronze cycles >= 0 only (the live defect)'   => ['bronze', [$this->cond('completed_investment_count', '>=', 0)]],
            'bronze amount and cycles >= 0'               => ['bronze', [$this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 0)]],
            'bronze amount only'                          => ['bronze', [$this->cond('total_invested', '>=', 100)]],
            'bronze cycles only'                          => ['bronze', [$this->cond('completed_investment_count', '>=', 1)]],
            'gold with 6 cycles instead of 3'             => ['gold', [$this->cond('total_invested', '>=', 5000), $this->cond('completed_investment_count', '>=', 6)]],
            'gold with 2 cycles'                          => ['gold', [$this->cond('total_invested', '>=', 5000), $this->cond('completed_investment_count', '>=', 2)]],
            'diamond with 15 cycles instead of 8'         => ['diamond', [$this->cond('total_invested', '>=', 20000), $this->cond('completed_investment_count', '>=', 15)]],
            'platinum referrals only'                     => ['platinum', [$this->cond('referral_count', '>=', 7)]],
            'elite referrals only'                        => ['elite', [$this->cond('referral_count', '>=', 23)]],
            'silver amount too low'                       => ['silver', [$this->cond('total_invested', '>=', 500), $this->cond('completed_investment_count', '>=', 2)]],
            'elite amount too high'                       => ['elite', [$this->cond('total_invested', '>=', 60000), $this->cond('completed_investment_count', '>=', 12)]],
            'extra non-core condition added'              => ['bronze', [$this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1), $this->cond('referral_count', '>=', 1)]],
        ];

        foreach ($bad as $label => [$rank, $conds]) {
            $this->postJson('/api/admin/ranks/criteria', $this->payload($rank, $conds))
                ->assertStatus(422)->assertJsonValidationErrors('conflicts');   // refused: $label
        }
        $this->assertSame(0, RankCriterion::count(), 'nothing may be stored from a refused rule');

        // "any one of" cannot replace "both"
        $any = $this->postJson('/api/admin/ranks/criteria', $this->payload('bronze', [
            $this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1),
        ], ['logic' => 'any']));
        $any->assertStatus(422);
        $this->assertSame(0, RankCriterion::count());
    }

    public function test_a_non_conforming_draft_can_be_saved_but_never_activated(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $draft = $this->postJson('/api/admin/ranks/criteria',
            $this->payload('platinum', [$this->cond('referral_count', '>=', 7)], ['is_active' => false]));
        $draft->assertCreated();

        $this->postJson('/api/admin/ranks/criteria/' . $draft->json('criterion.id') . '/activate')->assertStatus(422);
        $this->assertFalse(RankCriterion::find($draft->json('criterion.id'))->is_active);
    }

    public function test_the_specified_amount_band_is_accepted_but_a_different_band_is_not(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/admin/ranks/criteria', $this->payload('bronze', [
            $this->cond('total_invested', 'between', [100, 1999]), $this->cond('completed_investment_count', '>=', 1),
        ]))->assertStatus(422);

        $this->postJson('/api/admin/ranks/criteria', $this->payload('bronze', [
            $this->cond('total_invested', 'between', [100, 999]), $this->cond('completed_investment_count', '>=', 1),
        ]))->assertCreated();
    }

    // ── a rule that is ALREADY stored wrongly (as on the live dashboard) can never award a rank ──

    public function test_already_stored_non_conforming_active_rules_are_ignored_by_the_engine(): void
    {
        // Reproduce the live dashboard: rows already stored in the database, written without going through validation.
        $this->storeActiveRule('bronze',   [['completed_investment_count', '>=', 0]]);
        $this->storeActiveRule('silver',   [['total_invested', '>=', 1000], ['completed_investment_count', '>=', 2]]);
        $this->storeActiveRule('gold',     [['total_invested', '>=', 5000], ['completed_investment_count', '>=', 6]]);
        $this->storeActiveRule('platinum', [['referral_count', '>=', 7]]);
        $this->storeActiveRule('diamond',  [['total_invested', '>=', 20000], ['completed_investment_count', '>=', 15]]);
        $this->storeActiveRule('elite',    [['referral_count', '>=', 23]]);

        $engine = app(RankEvaluationService::class);
        $this->assertSame(['silver'], $engine->activeCriteria()->map(fn ($c) => $c->rank->key)->all(), 'only the rule that matches the specification is usable');

        $referrals = $this->investor();
        DB::table('users')->where('id', $referrals->id)->update(['referral_count' => 500]);
        $nothing = $this->investor();                                   // no investments at all
        $rich = $this->investorWith(60000, 12);                         // qualifies for Silver only under the surviving rule

        foreach ([$referrals, $nothing, $rich] as $u) $this->evaluate($u);

        $this->assertNull($this->freshRank($referrals), 'referrals alone must never award a rank');
        $this->assertNull($this->freshRank($nothing), 'an investor with nothing must never be ranked');
        $this->assertSame('silver', $this->freshRank($rich), 'only the conforming rule can award');
    }

    // ── approval workflow, audit, badge ────────────────────────────────────────

    public function test_with_approval_on_a_qualifying_investor_gets_a_pending_review_then_a_persistent_badge(): void
    {
        config(['ranks.require_approval' => true]);
        $this->activateSpecCore();
        $u = $this->investorWith(100, 1);

        $this->evaluate($u);
        $this->assertNull($this->freshRank($u), 'nothing is awarded before approval');
        $review = \App\Models\RankReview::pending()->where('investor_id', $u->id)->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/approvals/{$review->id}/approve", ['note' => 'Core requirement verified'])->assertOk();

        $this->assertSame('bronze', $this->freshRank($u));
        $this->assertSame(1, InvestorRankChange::where('investor_id', $u->id)->count(), 'one audit record');

        // persistence: a brand-new request still shows the badge
        $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/investor-investment/rank')->assertJsonPath('rank.key', 'bronze');
        $this->evaluate($u);
        $this->assertSame('bronze', $this->freshRank($u->fresh()), 'repeat evaluation keeps the rank');
        $this->assertSame(1, InvestorRankChange::where('investor_id', $u->id)->count());
    }

    // ── currency gate and finances are unaffected ──────────────────────────────

    public function test_the_currency_policy_gate_still_blocks_core_rules_until_confirmed(): void
    {
        $this->withoutCurrencyPolicy();
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/admin/ranks/criteria', $this->payload('bronze', [
            $this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1),
        ]))->assertStatus(422);
        $this->assertSame(0, RankCriterion::where('is_active', true)->count());

        // a perfectly conforming rule saved as a draft is inert while the policy is unconfirmed
        $d = $this->postJson('/api/admin/ranks/criteria', $this->payload('bronze', [
            $this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1),
        ], ['is_active' => false]));
        $d->assertCreated();
        $this->postJson('/api/admin/ranks/criteria/' . $d->json('criterion.id') . '/activate')->assertStatus(422);
    }

    public function test_core_rule_evaluation_never_changes_balances_investments_or_deposits(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(5000, 3);
        $snap = fn () => [
            DB::table('users')->where('id', $u->id)->value('balance'),
            DB::table('investment_accounts')->orderBy('id')->get()->toJson(),
            DB::table('deposits')->count(),
        ];
        $before = $snap();
        $this->evaluate($u);
        $this->evaluate($u);
        $this->assertSame($before, $snap());
        $this->assertSame('gold', $this->freshRank($u));
    }

    // ── additional criteria stay out of the core requirement ───────────────────

    public function test_additional_criteria_stay_disabled_and_do_not_affect_core_eligibility(): void
    {
        $this->assertSame(0, \App\Models\RankAdditionalCriterion::where('enabled', true)->count(), 'unapproved additional criteria must not be active');

        $this->activateSpecCore();
        $u = $this->investorWith(100, 1);   // no referrals, no deposits recorded
        $this->evaluate($u);
        $this->assertSame('bronze', $this->freshRank($u), 'a disabled additional criterion never blocks or grants');
    }
}
