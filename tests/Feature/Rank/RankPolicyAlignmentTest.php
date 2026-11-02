<?php
// LOCATION: tests/Feature/Rank/RankPolicyAlignmentTest.php
//
// Board / Financial Team policy, stated as executable examples: a rank needs BOTH
//   A. current eligible principal  (verified USD in ACTIVE positions only; completed = $0), and
//   B. historical completed cycles (genuine completed investments; they keep counting after the principal has left).
// The highest rank satisfying both is awarded. Ranking is classification-only.

namespace Tests\Feature\Rank;

use App\Models\InvestmentAccount;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankInvestmentLedger;
use Illuminate\Support\Facades\DB;

class RankPolicyAlignmentTest extends RankTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
    }

    protected function ranked(User $u): ?string
    {
        app(RankEvaluationService::class)->evaluateUser($u->id, 'test');
        return $this->freshRank($u);
    }

    protected function facts(User $u): array { return app(RankInvestmentLedger::class)->forUsers([$u->id])[$u->id]; }

    /** An ACTIVE verified position in $currency; $rate is the recorded approved rate (null for USD). */
    protected function activeIn(User $u, string $cur, float $amount, ?float $rate = null): InvestmentAccount
    {
        $inv = $this->invest($u, $amount, 30, 'active', false);
        $this->verifyUsd($inv->fresh(), $cur, $rate, $cur === 'USD' ? null : 'Central bank mid-rate', $cur === 'USD' ? null : now()->toDateString());
        return $inv->fresh();
    }

    public function test_completed_investments_give_cycles_but_no_current_principal(): void
    {
        $u = $this->investor();
        for ($i = 0; $i < 3; $i++) $this->investCompleted($u, 5000);

        $f = $this->facts($u);
        $this->assertSame(3, $f['cycles']);
        $this->assertEquals(0.0, $f['current_principal']);
        $this->assertNull($this->ranked($u), 'three cycles and $15,000 of completed history do not satisfy the principal requirement');
    }

    public function test_sufficient_cycles_but_insufficient_principal_does_not_qualify(): void
    {
        $u = $this->investorWith(999.99, 12);          // 12 cycles, $999.99 current: Silver needs $1,000
        $this->assertSame('bronze', $this->ranked($u), 'only Bronze ($100 + 1 cycle) is satisfied');
    }

    public function test_sufficient_principal_but_insufficient_cycles_does_not_qualify(): void
    {
        $none = $this->investorWith(60000, 0);          // $60,000 active, no completed cycle
        $this->assertNull($this->ranked($none));

        $one = $this->investorWith(60000, 1);
        $this->assertSame('bronze', $this->ranked($one), 'one cycle only satisfies Bronze whatever the principal');
    }

    public function test_the_highest_rank_satisfying_both_requirements_is_awarded(): void
    {
        $this->assertSame('diamond', $this->ranked($this->investorWith(60000, 11)), 'Elite principal but 11 cycles');
        $this->assertSame('elite',   $this->ranked($this->investorWith(50000, 12)));
        $this->assertSame('platinum', $this->ranked($this->investorWith(10000, 5)));
        $this->assertSame('gold',    $this->ranked($this->investorWith(5000, 3)));
        $this->assertSame('silver',  $this->ranked($this->investorWith(1000, 2)));
        $this->assertSame('bronze',  $this->ranked($this->investorWith(100, 1)));
    }

    public function test_gbp_four_thousand_at_one_point_two_five_is_exactly_five_thousand_usd_and_gold(): void
    {
        $u = $this->investor();
        for ($i = 0; $i < 3; $i++) $this->investCompleted($u, 100);
        $this->activeIn($u, 'GBP', 4000, 1.25);

        $this->assertEquals(5000.0, $this->facts($u)['current_principal']);
        $this->assertSame('gold', $this->ranked($u));
    }

    public function test_gbp_3999_99_at_one_point_two_five_is_below_gold_on_principal(): void
    {
        $u = $this->investor();
        for ($i = 0; $i < 3; $i++) $this->investCompleted($u, 100);
        $this->activeIn($u, 'GBP', 3999.99, 1.25);       // exact product 4,999.9875 → posted half-up at 2 dp = 4,999.99

        $this->assertEquals(4999.99, $this->facts($u)['current_principal']);
        $this->assertLessThan(5000.0, $this->facts($u)['current_principal']);
        $this->assertSame('silver', $this->ranked($u), 'three cycles are not enough on their own for Gold');
    }

    public function test_a_redeemed_principal_no_longer_counts_but_its_cycle_does(): void
    {
        $u = $this->investor();
        $done = $this->investCompleted($u, 5000);
        $this->assertSame(1, $this->facts($u)['cycles']);
        $this->assertEquals(0.0, $this->facts($u)['current_principal']);
        $this->assertSame($done->id, InvestmentAccount::where('user_id', $u->id)->value('id'), 'the historical record is preserved');
    }

    public function test_ranking_changes_no_money(): void
    {
        $u = $this->investorWith(5000, 3);
        $before = [User::find($u->id)->balance, DB::table('investment_accounts')->where('user_id', $u->id)->sum('amount'), DB::table('deposits')->count()];
        $this->ranked($u);
        $this->assertEquals($before, [User::find($u->id)->balance, DB::table('investment_accounts')->where('user_id', $u->id)->sum('amount'), DB::table('deposits')->count()]);
    }
}
