<?php
// LOCATION: tests/Feature/Rank/RankCurrencyTest.php
//
// USD ranking currency, verified valuations, reinvestments / cycles, duplicate records, cancelled investments,
// and early Admin completion. Rank thresholds are in USD; a stored number is NEVER assumed to be USD.

namespace Tests\Feature\Rank;

use App\Jobs\EvaluateInvestorRankJob;
use App\Models\Deposit;
use App\Models\FinancialAuditLog;
use App\Models\InvestmentAccount;
use App\Models\UsdValuation;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankFieldRegistry;
use App\Services\Rank\RankInvestmentLedger;
use App\Services\Rank\UsdValuationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

class RankCurrencyTest extends RankTestCase
{
    // ── helpers ─────────────────────────────────────────────────────────────────────

    protected function engine(): RankEvaluationService { return app(RankEvaluationService::class); }

    protected function facts(User $u): array { return app(RankInvestmentLedger::class)->forUsers([$u->id])[$u->id]; }

    protected function field(User $u, string $key)
    {
        return app(RankFieldRegistry::class)->resolveMany([$u->id], [$key])[$u->id][$key];
    }

    protected function plan(int $days = 30): int
    {
        return DB::table('investment_plans')->insertGetId([
            'name' => 'Plan ' . uniqid(), 'min_amount' => 1, 'duration_days' => $days,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** An investment created WITHOUT a valuation (as every existing record is today). */
    protected function raw(User $u, int $planId, float $amount, string $status = 'completed'): InvestmentAccount
    {
        return InvestmentAccount::create([
            'user_id' => $u->id, 'investment_plan_id' => $planId, 'amount' => $amount, 'profit_percentage' => 0,
            'expected_profit' => 0, 'status' => $status, 'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    /** Move an investment so both its recorded and plan-scheduled end dates are in the past. */
    protected function age(InvestmentAccount $i): InvestmentAccount
    {
        DB::table('investment_accounts')->where('id', $i->id)->update([
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(10)->toDateString(),
        ]);
        return $i->fresh();
    }

    protected function financial(): User
    {
        return User::factory()->create(['role' => 'financial', 'status' => 'active']);
    }

    protected function verifyUrl(InvestmentAccount $i, string $type = 'investment'): string
    {
        return "/api/financial/currency/records/{$type}/{$i->id}/verify";
    }

    protected function yesterday(): string { return now()->subDay()->toDateString(); }

    // ── USD is the ranking currency; nothing is assumed ─────────────────────────────

    public function test_usd_is_the_fixed_ranking_currency(): void
    {
        $this->assertSame('USD', config('ranks.ranking_currency'));
        $fields = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks/criteria/schema')->json('fields'))->keyBy('key');
        $this->assertSame('USD', $fields['total_invested']['unit']);
    }

    public function test_a_stored_amount_is_never_assumed_to_be_usd(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 5000, 30, 'active', false);          // the amount is on record, but nobody verified its currency

        $this->assertEquals(0.0, $this->field($u, 'total_invested'));
        $this->assertContains($inv->id, $this->facts($u)['unverified']);
    }

    public function test_a_verified_usd_amount_counts_in_full(): void
    {
        $u = $this->investor();
        $this->invest($u, 1234.56);                                     // fixture verifies it as USD

        $this->assertEquals(1234.56, $this->field($u, 'total_invested'));
        $this->assertSame([], $this->facts($u)['unverified']);
    }

    public function test_a_non_usd_amount_is_converted_with_the_recorded_rate_and_the_original_is_preserved(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 1_000_000, 30, 'active', false);
        $this->verifyUsd($inv, 'NGN', 0.00065, 'Central bank mid-rate', $this->yesterday());

        $v = UsdValuation::where('valuable_id', $inv->id)->sole();
        $this->assertSame('NGN', $v->currency);
        $this->assertEquals(1_000_000.00, (float) $v->original_amount);   // original amount preserved
        $this->assertEquals(0.00065, (float) $v->fx_rate);                // recorded rate preserved
        $this->assertEquals(650.00, (float) $v->usd_amount);              // computed server-side
        $this->assertSame('Central bank mid-rate', $v->rate_source);

        $this->assertEquals(1_000_000.00, (float) $inv->fresh()->amount); // the investment itself is untouched
        $this->assertEquals(650.00, $this->field($u, 'total_invested')); // ranking uses the USD value
    }

    public function test_a_non_usd_amount_without_a_recorded_rate_is_refused_and_stays_flagged(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 1000, 30, 'active', false);

        $this->actingAs($this->financial(), 'sanctum')->postJson($this->verifyUrl($inv), ['currency' => 'NGN', 'note' => 'Checked the bank slip'])
            ->assertStatus(422)->assertJsonValidationErrors(['rate', 'rate_source', 'rate_date']);

        $this->assertSame(0, UsdValuation::count());
        $this->assertContains($inv->id, $this->facts($u)['unverified']);
        $this->assertEquals(0.0, $this->field($u, 'total_invested'));
    }

    public function test_the_rate_must_be_positive_attributed_dated_and_not_in_the_future(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 1000, 30, 'active', false);
        $this->actingAs($this->financial(), 'sanctum');

        $base = ['currency' => 'EUR', 'rate' => 1.08, 'rate_source' => 'ECB reference rate', 'rate_date' => $this->yesterday(), 'note' => 'Verified against bank slip'];

        $this->postJson($this->verifyUrl($inv), array_merge($base, ['rate' => 0]))->assertStatus(422)->assertJsonValidationErrors('rate');
        $this->postJson($this->verifyUrl($inv), array_merge($base, ['rate' => -2]))->assertStatus(422)->assertJsonValidationErrors('rate');
        $this->postJson($this->verifyUrl($inv), array_merge($base, ['rate_source' => '']))->assertStatus(422)->assertJsonValidationErrors('rate_source');
        $this->postJson($this->verifyUrl($inv), array_merge($base, ['rate_date' => now()->addDay()->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('rate_date');
        $this->postJson($this->verifyUrl($inv), array_merge($base, ['currency' => 'N']))->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->postJson($this->verifyUrl($inv), array_merge($base, ['note' => 'ok']))->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertSame(0, UsdValuation::count());

        $this->postJson($this->verifyUrl($inv), $base)->assertCreated();
        $this->assertEquals(1080.00, $this->field($u, 'total_invested'));
    }

    public function test_a_stablecoin_or_other_non_usd_code_is_not_treated_as_usd_without_a_rate(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 500, 30, 'active', false);

        $this->actingAs($this->financial(), 'sanctum')->postJson($this->verifyUrl($inv), ['currency' => 'USDT', 'note' => 'Paid in USDT'])
            ->assertStatus(422)->assertJsonValidationErrors('rate');
        $this->assertEquals(0.0, $this->field($u, 'total_invested'));
    }

    public function test_the_client_cannot_supply_the_usd_figure(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 250, 30, 'active', false);

        $this->actingAs($this->financial(), 'sanctum')
            ->postJson($this->verifyUrl($inv), ['currency' => 'USD', 'usd_amount' => 999999, 'note' => 'Verified as USD'])->assertCreated();

        $this->assertEquals(250.00, (float) UsdValuation::where('valuable_id', $inv->id)->sole()->usd_amount);
    }

    public function test_a_valuation_goes_stale_when_the_amount_is_corrected_and_the_record_returns_to_review(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 600);
        $this->assertEquals(600.0, $this->field($u, 'total_invested'));

        $inv->update(['amount' => 700]);                                // e.g. the audited Financial correction

        $this->assertContains($inv->id, $this->facts($u)['stale']);
        $this->assertEquals(0.0, $this->field($u, 'total_invested'));   // not silently trusted at the old or new value

        $this->verifyUsd($inv->fresh());                                // re-verified at the corrected amount
        $this->assertSame([], $this->facts($u)['stale']);
        $this->assertEquals(700.0, $this->field($u, 'total_invested'));
    }

    public function test_re_verifying_never_double_counts_an_investment(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 500, 30, 'active', false);
        $this->verifyUsd($inv, 'NGN', 2.0, 'first rate', $this->yesterday());   // 1000 USD
        $this->verifyUsd($inv);                                                  // corrected: it was USD → 500

        $this->assertSame(2, UsdValuation::where('valuable_id', $inv->id)->count());
        $this->assertEquals(500.0, $this->field($u, 'total_invested'));          // newest only, counted once
        $this->assertSame(1, $this->facts($u)['count']);
    }

    public function test_valuations_are_append_only(): void
    {
        $inv = $this->invest($this->investor(), 100);
        $v = UsdValuation::where('valuable_id', $inv->id)->sole();

        foreach ([fn () => $v->update(['usd_amount' => 1]), fn () => $v->delete()] as $attempt) {
            try { $attempt(); $this->fail('A valuation was modified.'); } catch (\LogicException $e) { $this->assertTrue(true); }
        }
        $this->assertEquals(100.0, (float) $v->fresh()->usd_amount);
    }

    public function test_every_verification_is_written_to_the_financial_audit_log(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 400, 30, 'active', false);
        $fin = $this->financial();

        $this->actingAs($fin, 'sanctum')->postJson($this->verifyUrl($inv), ['currency' => 'USD', 'note' => 'Confirmed against bank statement'])->assertCreated();

        $log = FinancialAuditLog::where('action', 'currency.verified')->sole();
        $this->assertSame($fin->id, $log->actor_id);
        $this->assertSame('investment', $log->entity_type);
        $this->assertSame($inv->id, (int) $log->entity_id);
        $this->assertSame($u->id, (int) $log->investor_id);
        $this->assertEquals(400.0, $log->new_value['usd_amount']);
        $this->assertSame('Confirmed against bank statement', $log->reason);
    }

    // ── Deposits ────────────────────────────────────────────────────────────────────

    public function test_only_approved_deposits_are_valued_and_unverified_ones_add_nothing(): void
    {
        $u = $this->investor();
        $ok  = Deposit::create(['user_id' => $u->id, 'amount' => 300, 'payment_method' => 'usdt', 'status' => 'approved']);
        $new = Deposit::create(['user_id' => $u->id, 'amount' => 50,  'payment_method' => 'bank_transfer', 'status' => 'approved']);
        $pending = Deposit::create(['user_id' => $u->id, 'amount' => 900, 'payment_method' => 'bitcoin', 'status' => 'pending']);
        $this->actingAs($this->financial(), 'sanctum');

        $this->postJson("/api/financial/currency/records/deposit/{$ok->id}/verify", ['currency' => 'USD', 'note' => 'Verified as USD'])->assertCreated();
        $this->postJson("/api/financial/currency/records/deposit/{$pending->id}/verify", ['currency' => 'USD', 'note' => 'Verified as USD'])->assertStatus(422);

        $this->assertEquals(300.0, $this->field($u, 'total_approved_deposits'));
        $this->assertSame([$new->id], app(RankInvestmentLedger::class)->depositsForUsers([$u->id])[$u->id]['unverified']);
    }

    // ── Money criteria use ONLY verified values ─────────────────────────────────────

    public function test_unverified_money_never_grants_a_rank_even_when_the_gate_is_open(): void
    {
        $this->createCriterion('bronze', [$this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1)]);
        $u = $this->investor();
        $inv = $this->age($this->invest($u, 5000, 30, 'completed', false));   // a genuinely completed cycle, currency unverified

        $this->engine()->evaluateUser($u->id);
        $this->assertNull($this->freshRank($u));

        $this->verifyUsd($inv);
        $this->engine()->evaluateUser($u->id);
        $this->assertSame('bronze', $this->freshRank($u));
    }

    public function test_criteria_that_do_not_measure_money_are_not_blocked_by_missing_valuations(): void
    {
        $this->createCriterion('silver', [$this->cond('completed_investment_count', '>=', 2)]);
        $u = $this->investor();
        $this->age($this->invest($u, 10, 30, 'completed', false));
        $this->age($this->invest($u, 10, 30, 'completed', false));

        $this->engine()->evaluateUser($u->id);
        $this->assertSame('silver', $this->freshRank($u));
    }

    public function test_verifying_a_record_re_evaluates_that_investor(): void
    {
        // Build the fixture BEFORE faking the queue: creating the investment itself queues an
        // 'investment_changed' evaluation, and EvaluateInvestorRankJob is unique per investor, so a job
        // already queued by the fixture would (correctly) swallow the one this test is about.
        $u = $this->investor();
        $inv = $this->invest($u, 500, 30, 'active', false);

        Queue::fake();

        $this->actingAs($this->financial(), 'sanctum')->postJson($this->verifyUrl($inv), ['currency' => 'USD', 'note' => 'Verified as USD'])->assertCreated();

        Queue::assertPushed(EvaluateInvestorRankJob::class, fn ($j) => $j->userId === $u->id && $j->trigger === 'currency_verified');
    }

    public function test_the_criteria_validator_warns_how_many_records_are_left_out_of_the_totals(): void
    {
        $this->invest($this->investor(), 500, 30, 'active', false);

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/criteria/validate',
            $this->payload('bronze', [$this->cond('total_invested', '>=', 100)], ['is_active' => false]))->assertOk();

        $this->assertStringContainsString('verified USD', implode(' ', $res->json('warnings')));
    }

    // ── Authorization of the verification endpoints ─────────────────────────────────

    public function test_only_financial_and_admin_can_verify_and_review_currency(): void
    {
        $u = $this->investor();
        $inv = $this->invest($u, 500, 30, 'active', false);
        $body = ['currency' => 'USD', 'note' => 'Verified as USD'];

        $this->postJson($this->verifyUrl($inv), $body)->assertStatus(401);                       // guest
        $this->actingAs($u, 'sanctum');
        $this->getJson('/api/financial/currency/summary')->assertStatus(403);
        $this->getJson('/api/financial/currency/records')->assertStatus(403);
        $this->postJson($this->verifyUrl($inv), $body)->assertStatus(403);
        $this->postJson('/api/financial/currency/bulk-usd', ['type' => 'investment', 'ids' => [$inv->id], 'note' => 'Bulk USD attestation'])->assertStatus(403);
        $this->assertSame(0, UsdValuation::count());

        foreach ([$this->financial(), $this->admin] as $staff) {
            $this->actingAs($staff, 'sanctum')->getJson('/api/financial/currency/summary')->assertOk();
        }
        $this->actingAs($this->admin, 'sanctum')->postJson($this->verifyUrl($inv), $body)->assertCreated();
    }

    // ── The review queue ────────────────────────────────────────────────────────────

    public function test_review_queue_flags_unknown_stale_and_duplicate_records(): void
    {
        $u = $this->investor();
        $a = $this->invest($u, 100);                                         // verified — not in the queue
        $b = $this->invest($u, 200, 30, 'active', false);                    // unknown currency
        $c = $this->invest($u, 300); $c->update(['amount' => 350]);          // stale
        $p = $this->plan();
        $d = $this->raw($u, $p, 400, 'active'); $e = $this->raw($u, $p, 400, 'active');   // E looks like a double-submit of D
        $this->actingAs($this->financial(), 'sanctum');

        $ids = fn (string $issue) => collect($this->getJson("/api/financial/currency/records?issue={$issue}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$b->id, $d->id, $e->id])->sort()->values()->all(), $ids('unverified'));
        $this->assertSame([$c->id], $ids('stale'));
        $this->assertSame([$e->id], $ids('duplicates'));
        $this->assertSame(collect([$b->id, $c->id, $d->id, $e->id])->sort()->values()->all(), $ids('needs_review'));
        $this->assertContains($a->id, $ids('all'));
        $this->assertNotContains($a->id, $ids('needs_review'));

        $row = collect($this->getJson('/api/financial/currency/records?issue=duplicates')->json('data'))->first();
        $this->assertTrue($row['duplicate_suspect']);
        $this->assertSame('unverified', $row['valuation_status']);
    }

    public function test_the_dashboard_reports_review_counts_and_which_records_exclude_an_investor(): void
    {
        $u = $this->investor();
        $bad = $this->invest($u, 200, 30, 'active', false);
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/admin/ranks')->assertOk()
            ->assertJsonPath('currency_review.investments.unverified', 1)->assertJsonPath('currency_review.needs_review', 1);
        $this->getJson("/api/admin/ranks/investors/{$u->id}")->assertOk()
            ->assertJsonPath('currency.investments_unverified.0', $bad->id)->assertJsonPath('currency.verified_total_usd', 0);
    }

    public function test_bulk_usd_attestation_is_explicit_attributed_and_skips_what_it_must(): void
    {
        $u = $this->investor();
        $verified = $this->invest($u, 100);
        $todo = $this->invest($u, 200, 30, 'active', false);
        $cancelled = $this->invest($u, 300, 30, 'cancelled');
        $this->actingAs($this->financial(), 'sanctum');

        $this->postJson('/api/financial/currency/bulk-usd', ['type' => 'investment', 'ids' => [$todo->id], 'note' => 'short'])->assertStatus(422);

        $res = $this->postJson('/api/financial/currency/bulk-usd', [
            'type' => 'investment', 'ids' => [$verified->id, $todo->id, $cancelled->id, 987654], 'note' => 'Financial team confirms these are USD',
        ])->assertOk();

        $this->assertSame(1, $res->json('verified'));
        $this->assertCount(3, $res->json('skipped'));
        $this->assertEquals(300.0, $this->field($u, 'total_invested'));          // 100 + 200; cancelled never counts
        $this->assertSame('Financial team confirms these are USD', UsdValuation::where('valuable_id', $todo->id)->sole()->note);
    }

    // ── Reinvestments and completed cycles ──────────────────────────────────────────

    public function test_each_separate_genuine_record_is_its_own_cycle_whatever_its_funding_source(): void
    {
        $u = $this->investor();
        $this->age($this->invest($u, 500, 30, 'completed'));        // an original investment
        $this->assertSame(1, $this->facts($u)['cycles']);

        $re = $this->invest($u, 500, 30, 'active');                 // a NEW record afterwards (reinvestment / wallet-funded alike)
        $this->assertSame(1, $this->facts($u)['cycles']);           // not a cycle until it has genuinely completed
        $re->update(['status' => 'completed']); $this->age($re);
        $this->assertSame(2, $this->facts($u)['cycles']);           // its own record, its own cycle
    }

    public function test_editing_extending_or_updating_an_investment_never_adds_a_cycle(): void
    {
        $u = $this->investor();
        $inv = $this->age($this->invest($u, 500, 30, 'completed'));
        $this->assertSame(1, $this->facts($u)['cycles']);

        $inv->update(['remaining_days' => 5]);
        $inv->touch();
        $inv->update(['amount' => 500]);                            // saved again, unchanged
        $this->assertSame(1, $this->facts($u)['cycles']);
        $this->assertSame(1, InvestmentAccount::where('user_id', $u->id)->count());

        // extending moves the SAME record's end date: it stops counting until the extended date, it never doubles
        $inv->update(['end_date' => now()->addDays(5)->toDateString()]);
        $this->assertSame(0, $this->facts($u)['cycles']);
        $inv->update(['end_date' => now()->subDays(10)->toDateString()]);
        $this->assertSame(1, $this->facts($u)['cycles']);
        $this->assertSame(1, InvestmentAccount::where('user_id', $u->id)->count());
    }

    public function test_one_investment_is_counted_once_across_every_rank_field(): void
    {
        $u = $this->investor();
        $this->age($this->invest($u, 750, 30, 'completed'));

        $this->assertSame(1, $this->field($u, 'investment_count'));
        $this->assertSame(1, $this->field($u, 'completed_investment_count'));
        $this->assertEquals(750.0, $this->field($u, 'total_invested'));
        $this->assertEquals(0.0, $this->field($u, 'active_investment_amount'));
    }

    // ── Duplicate records ───────────────────────────────────────────────────────────

    protected function doubleSubmit(User $u, float $amount = 500): array
    {
        $plan = $this->plan();
        $a = $this->age($this->raw($u, $plan, $amount, 'completed'));
        $b = $this->age($this->raw($u, $plan, $amount, 'completed'));        // same investor, plan, amount, start date, same moment
        $this->verifyUsd($a);
        return [$a, $b];
    }

    public function test_a_double_submitted_investment_is_excluded_from_amount_cycles_and_count_and_flagged(): void
    {
        $u = $this->investor();
        [$a, $b] = $this->doubleSubmit($u);

        $f = $this->facts($u);
        $this->assertSame([$b->id], $f['duplicates']);
        $this->assertSame(1, $f['count']);
        $this->assertSame(1, $f['cycles']);
        $this->assertEquals(500.0, $f['total_usd']);
        $this->assertContains($b->id, app(UsdValuationService::class)->duplicateSuspectIds());
    }

    public function test_a_duplicate_looking_record_cannot_be_verified_unless_financial_confirms_it_is_separate(): void
    {
        $u = $this->investor();
        [$a, $b] = $this->doubleSubmit($u);
        $svc = app(UsdValuationService::class);

        try {
            $svc->record('investment', $b->id, 'USD', null, null, null, 'Verified as USD', $this->admin, false);
            $this->fail('A duplicate-looking record was verified without confirmation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('duplicate', $e->errors());
        }
        $this->assertSame(1, $this->facts($u)['cycles']);

        $svc->record('investment', $b->id, 'USD', null, null, null, 'Two separate $500 investments, confirmed with the investor', $this->admin, true);

        $f = $this->facts($u);
        $this->assertSame([], $f['duplicates']);
        $this->assertSame(2, $f['cycles']);
        $this->assertEquals(1000.0, $f['total_usd']);
    }

    public function test_identical_records_created_far_apart_are_not_duplicates(): void
    {
        $u = $this->investor();
        [$a, $b] = $this->doubleSubmit($u);
        DB::table('investment_accounts')->where('id', $b->id)->update(['created_at' => now()->addMinutes(10)]);
        $this->verifyUsd($b->fresh());

        $f = $this->facts($u);
        $this->assertSame([], $f['duplicates']);
        $this->assertSame(2, $f['cycles']);
    }

    public function test_records_with_a_different_plan_or_amount_are_not_duplicates(): void
    {
        $u = $this->investor();
        $this->age($this->invest($u, 500, 30, 'completed'));          // each helper call uses its own plan
        $this->age($this->invest($u, 500, 30, 'completed'));
        $plan = $this->plan();
        $this->age($this->raw($u, $plan, 500)); $this->age($this->raw($u, $plan, 501));   // same plan, different amount

        $f = $this->facts($u);
        $this->assertSame([], $f['duplicates']);
        $this->assertSame(4, $f['count']);
    }

    // ── Cancelled and early-completed investments ───────────────────────────────────

    public function test_cancelled_investments_are_excluded_from_amount_cycles_and_count(): void
    {
        $u = $this->investor();
        $plan = $this->plan();
        $this->age($this->raw($u, $plan, 800, 'cancelled'));
        $this->age($this->invest($u, 100, 30, 'completed'));

        $f = $this->facts($u);
        $this->assertSame(1, $f['count']);
        $this->assertSame(1, $f['cycles']);
        $this->assertEquals(100.0, $f['total_usd']);
    }

    public function test_admin_early_completion_does_not_count_until_the_scheduled_end_date_and_ranking_never_touches_money(): void
    {
        $u = $this->investor(['balance' => 100]);
        $inv = $this->invest($u, 1000, 30, 'active');
        $inv->update(['expected_profit' => 50]);

        // The EXISTING completion/payout logic runs unchanged (profit credited by it, not by the rank module).
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/investments/{$inv->id}/complete")->assertOk();
        $this->assertSame('completed', $inv->fresh()->status);
        $this->assertEquals(150.0, (float) $u->fresh()->balance);

        $this->assertSame(0, $this->facts($u)['cycles']);                     // completed early → not a cycle yet

        // An Admin shortening the countdown does not bring the SCHEDULED end forward.
        DB::table('investment_accounts')->where('id', $inv->id)->update(['end_date' => $this->yesterday()]);
        $this->assertSame(0, $this->facts($u)['cycles']);

        // Once the scheduled end has genuinely passed it counts.
        $this->age($inv);
        $this->assertSame(1, $this->facts($u)['cycles']);

        // Rank evaluation leaves balances alone.
        $this->createCriterion('bronze', [$this->cond('completed_investment_count', '>=', 1)]);
        $this->engine()->evaluateUser($u->id);
        $this->assertSame('bronze', $this->freshRank($u));
        $this->assertEquals(150.0, (float) $u->fresh()->balance);
    }

    public function test_a_completed_investment_needs_a_positive_amount_to_be_a_cycle(): void
    {
        $u = $this->investor();
        $this->age($this->invest($u, 0.00, 30, 'completed'));
        $this->age($this->invest($u, 25.00, 30, 'completed'));

        $this->assertSame(1, $this->facts($u)['cycles']);
    }

    // ── Nothing here writes to money tables ─────────────────────────────────────────

    public function test_verifying_currency_changes_no_balance_investment_or_deposit(): void
    {
        $u = $this->investor(['balance' => 321.00]);
        $inv = $this->invest($u, 1000, 30, 'active', false);
        $dep = Deposit::create(['user_id' => $u->id, 'amount' => 400, 'payment_method' => 'bank_transfer', 'status' => 'approved']);
        $invBefore = DB::table('investment_accounts')->where('id', $inv->id)->first();
        $depBefore = DB::table('deposits')->where('id', $dep->id)->first();
        $counts = fn () => [DB::table('withdrawals')->count(), DB::table('deposits')->count(), DB::table('investment_accounts')->count()];
        $before = $counts();

        $this->verifyUsd($inv, 'EUR', 1.10, 'ECB', $this->yesterday());
        app(UsdValuationService::class)->record('deposit', $dep->id, 'USD', null, null, null, 'Verified as USD', $this->admin);

        $this->assertEquals(321.00, (float) $u->fresh()->balance);
        $this->assertEquals($invBefore, DB::table('investment_accounts')->where('id', $inv->id)->first());
        $this->assertEquals($depBefore, DB::table('deposits')->where('id', $dep->id)->first());
        $this->assertSame($before, $counts());
    }
}
