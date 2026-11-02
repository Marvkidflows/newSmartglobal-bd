<?php
// LOCATION: tests/Feature/Rank/RankLedgerVerificationTest.php
//
// The qualifying-total and cycle rules of the SSI framework §2: only VERIFIED, eligible investments count, only cycles confirmed as
// completed by the financial system count, each record and cycle counts once, and nothing unverified is ever treated as USD.
// Also pins the six official thresholds and their boundaries, and the stored currency-conversion evidence.
//
// STATUS: NOT EXECUTED by its author (no PHP in the authoring environment). Run: php artisan test --filter=RankLedgerVerificationTest

namespace Tests\Feature\Rank;

use App\Models\InvestmentAccount;
use App\Models\UsdValuation;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankInvestmentLedger;
use Illuminate\Support\Facades\DB;

class RankLedgerVerificationTest extends RankTestCase
{
    private const OFFICIAL = ['bronze' => [100, 1], 'silver' => [1000, 2], 'gold' => [5000, 3], 'platinum' => [10000, 5], 'diamond' => [20000, 8], 'elite' => [50000, 12]];
    private const ORDER = ['bronze', 'silver', 'gold', 'platinum', 'diamond', 'elite'];

    private function facts(User $u): array
    {
        return app(RankInvestmentLedger::class)->forUsers([$u->id])[$u->id];
    }

    private function matured(InvestmentAccount $i): InvestmentAccount
    {
        DB::table('investment_accounts')->where('id', $i->id)->update(['start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(10)->toDateString()]);
        return $i->fresh();
    }

    /**
     * An investor with exactly $amount of CURRENT eligible principal (one active verified position) and $cycles verified completed
     * cycles (separate matured, completed, verified $1 positions whose principal is no longer active). Completed positions add cycles,
     * never current principal (Board §1).
     */
    private function build(User $u, float $amount, int $cycles): void
    {
        for ($i = 0; $i < $cycles; $i++) {
            $this->matured($this->invest($u, 1.00, 30, 'completed'));
        }
        if ($amount > 0) $this->invest($u, $amount, 30, 'active');
    }

    private function evaluate(User $u): ?string
    {
        app(RankEvaluationService::class)->evaluateUser($u->id, 'test');
        return $this->freshRank($u);
    }

    // ── What counts and what does not ───────────────────────────────────────────────────────────────────

    public function test_a_completed_matured_verified_investment_counts_one_cycle_and_its_history_but_no_current_principal(): void
    {
        $u = $this->investor();
        $this->matured($this->invest($u, 1500, 30, 'completed'));

        $f = $this->facts($u);
        $this->assertEquals(1500.0, $f['total_usd'], 'historical total (reporting only)');
        $this->assertEquals(0.0, $f['current_principal'], 'a completed position is no longer invested principal');
        $this->assertSame(1, $f['cycles']);
        $this->assertSame([], $f['cycles_awaiting_verification']);
    }

    public function test_an_investment_with_no_valuation_adds_no_amount_and_no_cycle(): void
    {
        $u = $this->investor();
        $inv = $this->matured($this->invest($u, 9000, 30, 'completed', false));

        $f = $this->facts($u);
        $this->assertEquals(0.0, $f['total_usd'], 'an unverified amount is never treated as USD');
        $this->assertSame(0, $f['cycles'], 'a cycle behind an unverified record does not count');
        $this->assertSame([$inv->id], $f['unverified']);
        $this->assertSame([$inv->id], $f['cycles_awaiting_verification']);
    }

    public function test_verifying_the_record_makes_both_the_amount_and_the_cycle_count_exactly_once(): void
    {
        $u = $this->investor();
        $inv = $this->matured($this->invest($u, 700, 30, 'completed', false));
        $this->verifyUsd($inv);

        $f = $this->facts($u);
        $this->assertEquals(700.0, $f['total_usd']);
        $this->assertSame(1, $f['cycles']);
        $this->assertSame([], $f['cycles_awaiting_verification']);

        $this->verifyUsd($inv->fresh());                      // a second verification of the same record must not double count
        $f = $this->facts($u);
        $this->assertEquals(700.0, $f['total_usd']);
        $this->assertSame(1, $f['cycles']);
    }

    public function test_a_stale_valuation_is_excluded_until_reverified(): void
    {
        $u = $this->investor();
        $inv = $this->matured($this->invest($u, 800, 30, 'completed'));           // verified at 800
        DB::table('investment_accounts')->where('id', $inv->id)->update(['amount' => 8000]);   // amount changed AFTER verification

        $f = $this->facts($u);
        $this->assertEquals(0.0, $f['total_usd']);
        $this->assertSame(0, $f['cycles']);
        $this->assertSame([$inv->id], $f['stale']);
        $this->assertSame([$inv->id], $f['cycles_awaiting_verification']);

        $this->verifyUsd($inv->fresh());
        $this->assertEquals(8000.0, $this->facts($u)['total_usd']);
    }

    public function test_a_possible_duplicate_counts_once_unless_financial_confirms_it_is_distinct(): void
    {
        $u = $this->investor();
        $first = $this->matured($this->invest($u, 600, 30, 'completed'));
        $dup = InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $first->investment_plan_id, 'amount' => 600,
            'profit_percentage' => 0, 'expected_profit' => 0, 'status' => 'completed', 'start_date' => $first->start_date, 'end_date' => $first->end_date]);

        $f = $this->facts($u);
        $this->assertEquals(600.0, $f['total_usd'], 'the duplicate adds nothing');
        $this->assertSame(1, $f['cycles'], 'and is not a second cycle');
        $this->assertSame([$dup->id], $f['duplicates']);

        // Financial confirms the second record is genuinely separate → now it counts, once.
        app(\App\Services\Rank\UsdValuationService::class)->record('investment', $dup->id, 'USD', null, null, null, 'Confirmed as a separate investment', $this->admin, true);
        $f = $this->facts($u);
        $this->assertEquals(1200.0, $f['total_usd']);
        $this->assertSame(2, $f['cycles']);
    }

    public function test_pending_deposits_and_cancelled_investments_add_nothing_and_a_running_investment_is_never_a_cycle(): void
    {
        $u = $this->investor();
        $this->invest($u, 1000, 30, 'active');                    // running: its verified amount is recorded, but it is not a cycle
        $this->invest($u, 4000, 30, 'cancelled', false);          // cancelled: nothing at all
        // A pending deposit is a pending transaction: an investment can only be made from wallet funds, so it never becomes one.
        \App\Models\Deposit::create(['user_id' => $u->id, 'amount' => 9000, 'payment_method' => 'bank_transfer', 'status' => 'pending']);

        $ledger = app(RankInvestmentLedger::class);
        $f = $ledger->forUsers([$u->id])[$u->id];
        $this->assertSame(0, $f['cycles']);
        $this->assertEquals(1000.0, $f['total_usd'], 'only the verified, non-cancelled investment is recorded');
        $this->assertEquals(1000.0, $f['current_principal'], 'the running position is current principal');
        $this->assertEquals(0.0, $ledger->depositsForUsers([$u->id])[$u->id]['total_usd'], 'a pending deposit is not counted anywhere');
    }

    public function test_an_incomplete_cycle_does_not_count_even_when_marked_completed_early(): void
    {
        $u = $this->investor();
        $this->invest($u, 500, 30, 'completed');                                            // term has not elapsed
        $noEnd = $this->invest($u, 500, 30, 'completed');
        DB::table('investment_accounts')->where('id', $noEnd->id)->update(['end_date' => null]);   // no end date at all

        $this->assertSame(0, $this->facts($u)['cycles']);
    }

    public function test_reading_the_ledger_writes_nothing_and_leaves_balances_alone(): void
    {
        $u = $this->investor();
        $this->matured($this->invest($u, 1000, 30, 'completed'));
        $before = [DB::table('investment_accounts')->orderBy('id')->get()->toJson(), (float) $u->fresh()->balance, DB::table('usd_valuations')->count(), DB::table('withdrawals')->count()];

        $this->facts($u); $this->evaluate($u);

        $this->assertSame($before, [DB::table('investment_accounts')->orderBy('id')->get()->toJson(), (float) $u->fresh()->balance, DB::table('usd_valuations')->count(), DB::table('withdrawals')->count()]);
    }

    // ── Currency evidence is preserved, never invented ──────────────────────────────────────────────────

    public function test_the_conversion_evidence_is_stored_and_the_original_record_is_untouched(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 300000, 30, 'active', false);
        $this->verifyUsd($inv, 'NGN', 0.00067, 'Central bank mid-rate', now()->toDateString());

        $v = UsdValuation::where('valuable_type', 'investment')->where('valuable_id', $inv->id)->latest('id')->firstOrFail();
        $this->assertSame('NGN', $v->currency);
        $this->assertEquals(300000.00, $v->original_amount);
        $this->assertEquals(0.00067, (float) $v->fx_rate);
        $this->assertEquals(201.00, $v->usd_amount);
        $this->assertSame('Central bank mid-rate', $v->rate_source);
        $this->assertSame(now()->toDateString(), $v->rate_date->toDateString());
        $this->assertNotNull($v->created_at, 'conversion timestamp');
        $this->assertNotEmpty($v->verified_by_name);
        $this->assertEquals(300000.00, $inv->fresh()->amount, 'the investment record keeps its original amount');
        $this->assertEquals(201.00, $this->facts($u)['total_usd']);
        $this->assertEquals(201.00, $this->facts($u)['current_principal'], 'current principal is the verified USD equivalent, never the NGN figure');
    }

    public function test_a_non_usd_valuation_without_a_rate_or_its_source_is_refused_and_nothing_is_assumed(): void
    {
        $inv = $this->invest($this->investor(), 500, 30, 'active', false);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            app(\App\Services\Rank\UsdValuationService::class)->record('investment', $inv->id, 'NGN', null, null, null, 'No rate supplied', $this->admin, true);
        } finally {
            $this->assertSame(0, UsdValuation::count());
        }
    }

    // ── The six official thresholds and their boundaries ────────────────────────────────────────────────

    public function test_each_official_threshold_grants_exactly_its_rank_and_one_cent_or_one_cycle_short_does_not(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();

        foreach (self::OFFICIAL as $key => [$amount, $cycles]) {
            $below = array_search($key, self::ORDER, true) > 0 ? self::ORDER[array_search($key, self::ORDER, true) - 1] : null;

            $exact = $this->investor(); $this->build($exact, $amount, $cycles);
            $this->assertSame($key, $this->evaluate($exact), "$key: exactly \$$amount and $cycles cycles");

            $cent = $this->investor(); $this->build($cent, $amount - 0.01, $cycles);
            $this->assertNotSame($key, $this->evaluate($cent), "$key: one cent short must not qualify");

            $cycle = $this->investor(); $this->build($cycle, $amount, $cycles - 1);
            $this->assertNotSame($key, $this->evaluate($cycle), "$key: one cycle short must not qualify");

            $this->assertTrue(in_array($this->freshRank($cycle), [$below, null, ...array_slice(self::ORDER, 0, array_search($key, self::ORDER, true))], true), "$key: falls back to a LOWER rank only");
        }
    }

    public function test_the_highest_rank_satisfying_both_requirements_is_assigned(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor();
        $this->build($u, 25000, 9);       // satisfies Diamond (20,000 / 8) but not Elite (50,000 / 12)
        $this->assertSame('diamond', $this->evaluate($u));

        $rich = $this->investor();
        $this->build($rich, 1000000, 2);  // plenty of money, only 2 cycles → Silver, not a high rank
        $this->assertSame('silver', $this->evaluate($rich));
    }

    public function test_one_unverified_cycle_is_the_difference_between_elite_and_diamond(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor();
        $this->invest($u, 60000, 30, 'active');                                      // verified current principal
        for ($i = 0; $i < 11; $i++) $this->matured($this->invest($u, 1, 30, 'completed'));
        $twelfth = $this->matured($this->invest($u, 1, 30, 'completed', false));     // the 12th cycle is NOT verified

        $this->assertSame('diamond', $this->evaluate($u), '11 verified cycles satisfy Diamond (8) but not Elite (12)');

        $this->verifyUsd($twelfth);
        $this->assertSame('elite', $this->evaluate($u));
    }

    public function test_redeemed_completed_principal_no_longer_counts_toward_the_monetary_threshold_but_its_cycle_does(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor();
        $this->matured($this->invest($u, 5000, 30, 'completed'));                    // a verified completed cycle: principal no longer invested
        $this->assertNull($this->evaluate($u), '$5,000 of completed principal is not current principal: no rank');

        $this->invest($u, 100, 30, 'active');                                        // $100 current principal + 1 verified cycle
        $this->assertSame('bronze', $this->evaluate($u), 'the cycle history survives; the monetary threshold uses what is invested now');
    }
}
