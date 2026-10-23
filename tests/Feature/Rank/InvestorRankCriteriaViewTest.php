<?php
// Investor-facing, READ-ONLY view of rank criteria. Threshold numbers here are fixture data.

namespace Tests\Feature\Rank;

class InvestorRankCriteriaViewTest extends RankTestCase
{
    private const URL = '/api/investor-investment/rank/criteria';

    /** Investor with the given verified total and completed cycles, evaluated by the real rank engine
     *  (fixtures move cycle dates with a raw update, so no event fires after the data is final). */
    private function ready(float $amount, int $cycles): \App\Models\User
    {
        $u = $this->investorWith($amount, $cycles);
        app(\App\Services\Rank\RankEvaluationService::class)->evaluateUser($u->id);
        return $u->fresh();
    }

    private function activate(string $key, float $amount, int $cycles): void
    {
        $this->createCriterion($key, [
            $this->cond('total_invested', '>=', $amount),
            $this->cond('completed_investment_count', '>=', $cycles),
        ]);
    }

    public function test_lists_all_six_ranks_in_order_with_awaiting_status_when_nothing_is_active(): void
    {
        $res = $this->actingAs($this->investor(), 'sanctum')->getJson(self::URL)->assertOk();
        $this->assertSame(['bronze', 'silver', 'gold', 'platinum', 'diamond', 'elite'], array_column($res->json('ranks'), 'key'));
        foreach ($res->json('ranks') as $r) {
            $this->assertSame('awaiting_approval', $r['status']);
            $this->assertSame([], $r['requirements']);
            $this->assertNull($r['min_investment_usd']);
        }
    }

    public function test_active_criteria_show_amount_and_cycles_and_current_rank_is_flagged(): void
    {
        foreach ([['bronze',100,1],['silver',1000,2],['gold',5000,3],['platinum',10000,5],['diamond',20000,8],['elite',50000,12]] as [$k,$a,$c]) {
            $this->activate($k, $a, $c);
        }
        $u = $this->ready(1000, 2);
        $this->assertSame('silver', $this->freshRank($u));

        $res = $this->actingAs($u, 'sanctum')->getJson(self::URL)->assertOk();
        $ranks = collect($res->json('ranks'))->keyBy('key');
        $this->assertSame('silver', $res->json('current_rank_key'));
        $this->assertEquals(50000, $ranks['elite']['min_investment_usd']);
        $this->assertSame(12, $ranks['elite']['min_completed_cycles']);
        $this->assertSame('active', $ranks['gold']['status']);
        $this->assertSame(['silver'], $ranks->where('is_current', true)->keys()->all());
    }

    public function test_draft_criteria_are_not_presented_as_active(): void
    {
        $this->createCriterion('gold', [$this->cond('total_invested', '>=', 5000)], ['is_active' => false]);
        $gold = collect($this->actingAs($this->investor(), 'sanctum')->getJson(self::URL)->json('ranks'))->firstWhere('key', 'gold');
        $this->assertSame('awaiting_approval', $gold['status']);
        $this->assertNull($gold['min_investment_usd']);
        $this->assertSame([], $gold['requirements']);
    }

    public function test_money_rule_blocked_by_currency_signoff_is_pending_not_active(): void
    {
        $this->activate('bronze', 100, 1);
        config(['ranks.currency_confirmed' => false]);
        $b = collect($this->actingAs($this->investor(), 'sanctum')->getJson(self::URL)->json('ranks'))->firstWhere('key', 'bronze');
        $this->assertSame('pending_currency_approval', $b['status']);
        $this->assertNull($b['min_investment_usd']);
    }

    public function test_response_exposes_no_internal_fields(): void
    {
        $this->activate('bronze', 100, 1);
        $json = $this->actingAs($this->investor(), 'sanctum')->getJson(self::URL)->getContent();
        foreach (['created_by', 'updated_by', 'version', 'criterion_id', 'email', 'reason'] as $k) {
            $this->assertStringNotContainsString("\"$k\"", $json);
        }
    }

    public function test_requires_investor_and_is_read_only(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAs($this->admin, 'sanctum')->getJson(self::URL)->assertForbidden();
        $inv = $this->investor();
        $this->actingAs($inv, 'sanctum')->postJson(self::URL, [])->assertStatus(405);
        $this->actingAs($inv, 'sanctum')->putJson(self::URL, [])->assertStatus(405);
        $this->actingAs($inv, 'sanctum')->postJson('/api/admin/ranks/criteria', [])->assertForbidden();
    }

    public function test_viewing_changes_no_rank_or_money(): void
    {
        $this->activate('bronze', 100, 1);
        $u = $this->ready(500, 1);
        $before = [$u->fresh()->balance, $u->fresh()->investor_rank_id, \DB::table('investment_accounts')->count()];
        $this->actingAs($u, 'sanctum')->getJson(self::URL)->assertOk();
        $this->assertSame($before, [$u->fresh()->balance, $u->fresh()->investor_rank_id, \DB::table('investment_accounts')->count()]);
    }

    private function all(): void
    {
        foreach ([['bronze',100,1],['silver',1000,2],['gold',5000,3],['platinum',10000,5],['diamond',20000,8],['elite',50000,12]] as [$k,$a,$c]) {
            $this->activate($k, $a, $c);
        }
    }

    private function progress($u): array
    {
        return $this->actingAs($u, 'sanctum')->getJson(self::URL)->assertOk()->json('progress');
    }

    public function test_progress_for_investor_with_nothing_qualifying(): void
    {
        $this->all();
        $p = $this->progress($this->investor());
        $this->assertSame('bronze', $p['next_rank']['key']);
        $this->assertSame('in_progress', $p['state']);
        $this->assertEquals(100, $p['amount']['remaining']);
        $this->assertSame(1, $p['cycles']['remaining']);
        $this->assertSame(0, $p['percent']);
    }

    public function test_progress_remaining_toward_next_rank(): void
    {
        $this->all();
        $p = $this->progress($this->ready(1500, 1));   // Bronze; Silver needs $1,000 + 2 cycles
        $this->assertSame('silver', $p['next_rank']['key']);
        $this->assertSame('in_progress', $p['state']);
        $this->assertSame(100, $p['amount']['percent']);
        $this->assertSame(1, $p['cycles']['remaining']);
        $this->assertSame(50, $p['cycles']['percent']);
        $this->assertEquals(1500, $p['current_amount_usd']);
    }

    public function test_unverified_currency_amounts_are_not_counted_and_are_reported(): void
    {
        $this->all();
        $u = $this->investor();
        $this->invest($u, 9000, 30, 'active', false);   // never verified
        $p = $this->progress($u);
        $this->assertEquals(0, $p['current_amount_usd']);
        $this->assertSame(1, $p['excluded']['awaiting_verification']);
    }

    public function test_investor_already_qualifying_is_told_so(): void
    {
        $this->all();
        $u = $this->ready(1000, 2);
        \DB::table('users')->where('id', $u->id)->update(['investor_rank_id' => null]);  // not yet evaluated
        $p = $this->progress($u->fresh());
        $this->assertSame('qualifies', $p['state']);
        $this->assertSame(100, $p['percent']);
    }

    public function test_qualifying_but_unverified_account_needs_review(): void
    {
        $this->all();
        $u = $this->ready(1000, 2);
        \DB::table('users')->where('id', $u->id)->update(['investor_rank_id' => null, 'kyc_verified' => false]);
        $this->assertSame('needs_review', $this->progress($u->fresh())['state']);
    }

    public function test_elite_investor_has_no_next_rank(): void
    {
        $this->all();
        $u = $this->ready(50000, 12);
        $this->assertSame('elite', $this->freshRank($u));
        $p = $this->progress($u);
        $this->assertSame('highest', $p['state']);
        $this->assertNull($p['next_rank']);
    }

    public function test_next_rank_without_active_criteria_is_awaiting_approval(): void
    {
        $this->activate('bronze', 100, 1);
        $p = $this->progress($this->ready(500, 1));
        $this->assertSame('silver', $p['next_rank']['key']);
        $this->assertSame('awaiting_approval', $p['state']);
        $this->assertNull($p['amount']);
    }

    public function test_investor_cannot_view_another_investors_progress(): void
    {
        $this->all();
        $rich  = $this->ready(60000, 12);
        $other = $this->investor();
        $res = $this->actingAs($other, 'sanctum')->getJson(self::URL . '?user_id=' . $rich->id . '&investor_id=' . $rich->id)->assertOk();
        $this->assertEquals(0, $res->json('progress.current_amount_usd'));
        $this->assertNull($res->json('current_rank_key'));
    }
}
