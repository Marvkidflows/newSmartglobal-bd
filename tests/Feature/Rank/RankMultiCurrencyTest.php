<?php
// LOCATION: tests/Feature/Rank/RankMultiCurrencyTest.php
//
// Investments in ANY currency: thresholds stay in USD and unchanged; a value is compared only after a person has
// recorded the currency + exchange rate (+ source + date); nothing is guessed; financial tables are never touched.

namespace Tests\Feature\Rank;

use App\Models\FinancialAuditLog;
use App\Models\InvestmentAccount;
use App\Models\UsdValuation;
use App\Models\User;
use App\Services\Rank\CurrencyCatalog;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankInvestmentLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class RankMultiCurrencyTest extends RankTestCase
{
    private const THRESHOLDS = ['bronze' => [100, 1], 'silver' => [1000, 2], 'gold' => [5000, 3], 'platinum' => [10000, 5], 'diamond' => [20000, 8], 'elite' => [50000, 12]];

    protected function fin(): User { return User::factory()->create(['role' => 'financial', 'status' => 'active']); }

    protected function today(): string { return now()->toDateString(); }

    /** A completed, matured investment verified in $cur at $rate (USD needs no rate). */
    protected function completedIn(User $u, string $cur, float $amount, ?float $rate, int $i = 0): InvestmentAccount
    {
        $inv = $this->invest($u, $amount, 30, 'completed', false);
        DB::table('investment_accounts')->where('id', $inv->id)->update([
            'start_date' => now()->subDays(60 + $i)->toDateString(), 'end_date' => now()->subDays(25 + $i)->toDateString(),
        ]);
        $this->verifyUsd($inv->fresh(), $cur, $rate, $cur === 'USD' ? null : 'Central bank mid-rate', $cur === 'USD' ? null : $this->today());
        return $inv->fresh();
    }

    protected function ranked(User $u): ?string
    {
        app(RankEvaluationService::class)->evaluateUser($u->id, 'test');
        return $this->freshRank($u);
    }

    protected function verifyUrl(InvestmentAccount $i): string { return "/api/financial/currency/records/investment/{$i->id}/verify"; }

    protected function body(array $o = []): array
    {
        return array_merge(['currency' => 'EUR', 'rate' => 1.08, 'rate_source' => 'ECB reference rate', 'rate_date' => $this->today(), 'note' => 'Checked against the bank slip'], $o);
    }

    // ── supported currencies ────────────────────────────────────────────────────────

    public function test_catalog_accepts_real_codes_and_rejects_typos(): void
    {
        $c = app(CurrencyCatalog::class);
        foreach (['USD', 'NGN', 'EUR', 'GBP', 'GHS', 'ZAR', 'KES', 'JPY', 'usdt'] as $code) $this->assertTrue($c->isSupported($code), $code);
        foreach (['NRG', 'ABC', 'XXX', 'N', '', 'US$'] as $code) $this->assertFalse($c->isSupported($code), $code);
    }

    public function test_an_unrecognised_currency_cannot_be_recorded(): void
    {
        $inv = $this->invest($this->investor(), 500, 30, 'active', false);
        $this->actingAs($this->fin(), 'sanctum')->postJson($this->verifyUrl($inv), $this->body(['currency' => 'NRG']))
            ->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->assertSame(0, UsdValuation::count());
    }

    public function test_supported_list_endpoint_is_authorised_and_complete(): void
    {
        $this->actingAs($this->investor(), 'sanctum')->getJson('/api/financial/currency/supported')->assertForbidden();
        $codes = collect($this->actingAs($this->fin(), 'sanctum')->getJson('/api/financial/currency/supported')->assertOk()->json('currencies'))->pluck('code');
        $this->assertTrue($codes->contains('NGN') && $codes->contains('USD') && $codes->contains('USDT'));
    }

    // ── thresholds are USD, unchanged, applied to the converted value ───────────────

    public function test_proposed_thresholds_are_unchanged(): void
    {
        $this->artisan('ranks:load-proposed')->assertSuccessful();
        foreach (self::THRESHOLDS as $key => [$amt, $cyc]) {
            $c = \App\Models\RankCriterion::with('conditions')->where('investor_rank_id', $this->rank($key)->id)->sole();
            $this->assertEquals($amt, (float) $c->conditions->firstWhere('field', 'total_invested')->value);
            $this->assertEquals($cyc, (float) $c->conditions->firstWhere('field', 'completed_investment_count')->value);
        }
    }

    public function test_a_huge_raw_amount_in_a_weak_currency_does_not_buy_a_rank(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor();
        // Raw sum 6,000,000 — but only $3,900 once converted: Silver (1,000 + 2), not Gold (5,000 + 3).
        foreach ([0, 1, 2] as $i) $this->completedIn($u, 'NGN', 2000000, 0.00065, $i);

        $this->assertSame('silver', $this->ranked($u));
        $this->assertEquals(3900.0, app(RankInvestmentLedger::class)->forUsers([$u->id])[$u->id]['total_usd']);
    }

    public function test_exact_boundary_after_conversion_qualifies_and_one_cent_short_does_not(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();

        $exact = $this->investor();
        foreach ([[2000, 0], [1000, 1], [1000, 2]] as [$amt, $i]) $this->completedIn($exact, 'GBP', $amt, 1.25, $i);   // = $5,000.00
        $this->assertSame('gold', $this->ranked($exact));

        $short = $this->investor();
        foreach ([[2000, 0], [1000, 1], [999.99, 2]] as [$amt, $i]) $this->completedIn($short, 'GBP', $amt, 1.25, $i);   // = $4,999.99
        $this->assertSame('silver', $this->ranked($short));
    }

    public function test_cycles_are_not_reduced_or_inflated_by_currency(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor();
        // $60,000 across only 11 cycles in three currencies: enough money for Elite, one cycle short → Diamond.
        for ($i = 0; $i < 4; $i++) $this->completedIn($u, 'USD', 5000, null, $i);
        for ($i = 4; $i < 8; $i++) $this->completedIn($u, 'EUR', 5000, 1.10, $i);       // 5,500 each
        for ($i = 8; $i < 11; $i++) $this->completedIn($u, 'GBP', 4000, 1.25, $i);      // 5,000 each
        $this->assertEquals(4 * 5000 + 4 * 5500 + 3 * 5000, app(RankInvestmentLedger::class)->forUsers([$u->id])[$u->id]['total_usd']);
        $this->assertSame('diamond', $this->ranked($u));

        $this->completedIn($u, 'USD', 100, null, 11);                                    // the 12th cycle
        $this->assertSame('elite', $this->ranked($u));
    }

    public function test_an_unverified_currency_counts_as_zero_never_as_usd(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor();
        for ($i = 0; $i < 12; $i++) {
            $inv = $this->invest($u, 100000, 30, 'completed', false);
            DB::table('investment_accounts')->where('id', $inv->id)->update(['start_date' => now()->subDays(60 + $i)->toDateString(), 'end_date' => now()->subDays(25 + $i)->toDateString()]);
        }
        $this->assertNull($this->ranked($u));
        $this->assertCount(12, app(RankInvestmentLedger::class)->forUsers([$u->id])[$u->id]['unverified']);
    }

    public function test_a_non_usd_amount_without_a_recorded_rate_is_refused_and_a_usd_rate_is_not_assumed(): void
    {
        $inv = $this->invest($this->investor(), 500, 30, 'active', false);
        $this->actingAs($this->fin(), 'sanctum')->postJson($this->verifyUrl($inv), ['currency' => 'NGN', 'note' => 'Naira transfer slip'])
            ->assertStatus(422)->assertJsonValidationErrors(['rate', 'rate_source', 'rate_date']);
        $this->assertSame(0, UsdValuation::count());
    }

    // ── rate handling: recorded, immutable, reusable, sanity-checked ────────────────

    public function test_rate_source_date_and_user_are_recorded_and_audited(): void
    {
        $fin = $this->fin();
        $inv = $this->invest($this->investor(), 1000, 30, 'active', false);
        $this->actingAs($fin, 'sanctum')->postJson($this->verifyUrl($inv), $this->body())->assertCreated();

        $v = UsdValuation::sole();
        $this->assertEquals(1080.00, (float) $v->usd_amount);
        $this->assertSame('ECB reference rate', $v->rate_source);
        $this->assertSame($this->today(), $v->rate_date->toDateString());
        $this->assertSame($fin->id, (int) $v->verified_by);
        $this->assertTrue(FinancialAuditLog::where('action', 'currency.verified')->exists());
    }

    public function test_a_rate_far_from_the_last_recorded_one_needs_acknowledgement(): void
    {
        $fin = $this->fin(); $u = $this->investor();
        $a = $this->invest($u, 1000, 30, 'active', false);
        $b = $this->invest($u, 2000, 30, 'active', false);
        $this->actingAs($fin, 'sanctum')->postJson($this->verifyUrl($a), $this->body(['currency' => 'GBP', 'rate' => 1.25]))->assertCreated();

        $inverted = $this->body(['currency' => 'GBP', 'rate' => 0.8]);          // the inverse of 1.25
        $this->postJson($this->verifyUrl($b), $inverted)->assertStatus(422)->assertJsonValidationErrors('rate_warnings');
        $this->assertSame(1, UsdValuation::count());

        $this->postJson($this->verifyUrl($b), $inverted + ['acknowledge_rate_warnings' => true])->assertCreated();
        $log = FinancialAuditLog::where('action', 'currency.verified')->latest('id')->first();
        $this->assertStringContainsString('differs by', json_encode($log->after ?? $log->new_values ?? $log->toArray()));
    }

    public function test_a_stale_rate_date_needs_acknowledgement(): void
    {
        $inv = $this->invest($this->investor(), 1000, 30, 'active', false);
        $old = $this->body(['rate_date' => now()->subDays(30)->toDateString()]);
        $this->actingAs($this->fin(), 'sanctum')->postJson($this->verifyUrl($inv), $old)->assertStatus(422)->assertJsonValidationErrors('rate_warnings');
        $this->postJson($this->verifyUrl($inv), $old + ['acknowledge_rate_warnings' => true])->assertCreated();
    }

    public function test_recent_rates_can_be_listed_for_reuse(): void
    {
        $inv = $this->invest($this->investor(), 1000, 30, 'active', false);
        $this->actingAs($this->fin(), 'sanctum')->postJson($this->verifyUrl($inv), $this->body(['currency' => 'GHS', 'rate' => 0.07]))->assertCreated();
        $r = $this->getJson('/api/financial/currency/rates?currency=GHS')->assertOk()->json('recent');
        $this->assertEquals(0.07, $r[0]['rate']);
        $this->assertSame('ECB reference rate', $r[0]['rate_source']);
        $this->getJson('/api/financial/currency/rates?currency=NRG')->assertStatus(422);
    }

    public function test_a_later_rate_does_not_rewrite_the_history_of_an_earlier_valuation(): void
    {
        $inv = $this->invest($this->investor(), 1000, 30, 'active', false);
        $this->actingAs($this->fin(), 'sanctum')->postJson($this->verifyUrl($inv), $this->body(['rate' => 1.08]))->assertCreated();
        $this->postJson($this->verifyUrl($inv), $this->body(['rate' => 1.10]))->assertCreated();
        $this->assertSame(2, UsdValuation::count());
        $this->assertEquals(1080.00, (float) UsdValuation::orderBy('id')->first()->usd_amount);
        $this->assertEquals(1100.00, app(RankInvestmentLedger::class)->forUsers([$inv->user_id])[$inv->user_id]['total_usd']);   // newest counts once
    }

    // ── bulk verification in any currency ───────────────────────────────────────────

    public function test_bulk_verify_applies_one_recorded_rate_to_many_records_individually(): void
    {
        $u = $this->investor();
        $recs = [$this->invest($u, 100, 30, 'active', false), $this->invest($u, 250.5, 31, 'active', false)];
        $res = $this->actingAs($this->fin(), 'sanctum')->postJson('/api/financial/currency/bulk-verify', [
            'type' => 'investment', 'ids' => collect($recs)->pluck('id')->all(),
            'currency' => 'EUR', 'rate' => 1.1, 'rate_source' => 'ECB reference rate', 'rate_date' => $this->today(), 'note' => 'Reconciled with the EUR bank statement',
        ])->assertOk();

        $this->assertSame(2, $res->json('verified'));
        $this->assertEquals(110.0, (float) UsdValuation::where('valuable_id', $recs[0]->id)->value('usd_amount'));
        $this->assertEquals(275.55, (float) UsdValuation::where('valuable_id', $recs[1]->id)->value('usd_amount'));
        $this->assertSame(2, FinancialAuditLog::where('action', 'currency.verified')->count());
    }

    public function test_bulk_verify_rejects_a_bad_batch_without_changing_anything(): void
    {
        $inv = $this->invest($this->investor(), 100, 30, 'active', false);
        $base = ['type' => 'investment', 'ids' => [$inv->id], 'note' => 'Reconciled with the bank statement'];
        $this->actingAs($this->fin(), 'sanctum')->postJson('/api/financial/currency/bulk-verify', $base + ['currency' => 'EUR'])->assertStatus(422);
        $this->postJson('/api/financial/currency/bulk-verify', $base + ['currency' => 'ZZZ', 'rate' => 1, 'rate_source' => 'x source', 'rate_date' => $this->today()])->assertStatus(422);
        $this->assertSame(0, UsdValuation::count());
        $this->actingAs($this->investor(), 'sanctum')->postJson('/api/financial/currency/bulk-verify', $base + ['currency' => 'USD'])->assertForbidden();
    }

    public function test_bulk_verify_skips_cancelled_and_already_verified_records(): void
    {
        $u = $this->investor();
        $ok = $this->invest($u, 100, 30, 'active', false);
        $cancelled = $this->invest($u, 200, 30, 'cancelled', false);
        $done = $this->invest($u, 300, 30, 'active', true);
        $res = $this->actingAs($this->fin(), 'sanctum')->postJson('/api/financial/currency/bulk-verify', [
            'type' => 'investment', 'ids' => [$ok->id, $cancelled->id, $done->id], 'currency' => 'USD', 'note' => 'Reconciled with the USD bank statement',
        ])->assertOk();
        $this->assertSame(1, $res->json('verified'));
        $this->assertCount(2, $res->json('skipped'));
    }

    // ── per-investor currency breakdown ─────────────────────────────────────────────

    public function test_investor_breakdown_shows_each_currency_with_its_rate_and_usd_value(): void
    {
        $u = $this->investor();
        $this->completedIn($u, 'USD', 500, null, 0);
        $this->completedIn($u, 'EUR', 1000, 1.1, 1);
        $this->completedIn($u, 'EUR', 2000, 1.1, 2);

        $json = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/ranks/investors/{$u->id}")->assertOk()->json('currency');
        $by = collect($json['by_currency'])->keyBy('currency');
        $this->assertSame('USD', $json['ranking_currency']);
        $this->assertEquals(3000.0, $by['EUR']['original_total']);
        $this->assertEquals(3300.0, $by['EUR']['usd_total']);
        $this->assertSame(2, $by['EUR']['records']);
        $this->assertEquals([1.1], $by['EUR']['rates']);
        $this->assertEquals(500.0, $by['USD']['usd_total']);
        $this->assertEquals(3800.0, $json['verified_total_usd']);
    }

    // ── development demo data ───────────────────────────────────────────────────────

    public function test_demo_command_creates_labelled_fixtures_and_reaches_the_documented_ranks(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $this->artisan('ranks:demo-data --evaluate')->assertSuccessful();

        $this->assertSame(7, User::where('email', 'like', '%@demo.invalid')->count());
        $this->assertSame(0, UsdValuation::where('currency', '!=', 'USD')->where('rate_source', 'not like', 'DEMO FIXTURE%')->count());

        $this->artisan('ranks:demo-data')->assertSuccessful();              // idempotent
        $this->assertSame(7, User::where('email', 'like', '%@demo.invalid')->count());
    }

    public function test_demo_command_refuses_in_production_and_creates_nothing(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('ranks:demo-data')->assertFailed();
        $this->assertSame(0, User::where('email', 'like', '%@demo.invalid')->count());
    }

    public function test_demo_command_does_not_activate_criteria_or_open_the_gate_by_itself(): void
    {
        $this->withoutCurrencyPolicy();
        $this->artisan('ranks:demo-data')->assertSuccessful();
        $this->assertSame(0, \App\Models\RankCriterion::count());
        $this->artisan('ranks:demo-data --evaluate')->assertFailed();
        $this->assertFalse(app(\App\Services\Rank\CurrencyPolicyService::class)->isConfirmed());
    }

    // ── classification only ─────────────────────────────────────────────────────────

    public function test_multi_currency_ranking_never_changes_money_or_records(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor(['balance' => 123.45]);
        foreach ([0, 1, 2] as $i) $this->completedIn($u, 'NGN', 2000000, 0.00065, $i);
        $before = ['inv' => DB::table('investment_accounts')->orderBy('id')->get()->toJson(), 'dep' => DB::table('deposits')->count(), 'wd' => DB::table('withdrawals')->count()];

        $this->ranked($u);

        $this->assertEquals(123.45, (float) $u->fresh()->balance);
        $this->assertSame($before, ['inv' => DB::table('investment_accounts')->orderBy('id')->get()->toJson(), 'dep' => DB::table('deposits')->count(), 'wd' => DB::table('withdrawals')->count()]);
    }
}
