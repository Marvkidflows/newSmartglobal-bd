<?php
// LOCATION: tests/Feature/Rank/FxControlsTest.php
//
// Financial Team §4–§5: no assumed rates, immutable conversion snapshots, auditable corrections, the reconciliation queue for historical
// records, cryptocurrency disabled for rank eligibility, stale/unsupported/missing-rate handling, half-up posting, and no live FX provider.
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php artisan test --filter=FxControlsTest

namespace Tests\Feature\Rank;

use App\Models\Deposit;
use App\Models\FxApprovedSource;
use App\Models\FxConversionSnapshot;
use App\Models\FxReconciliationItem;
use App\Models\RankBenefitAudit;
use App\Models\UsdValuation;
use App\Services\Rank\FxControlService;
use App\Services\Rank\UsdValuationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;

class FxControlsTest extends FinancialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->financialRulesOn();
    }

    private function legacyApprovedDeposit($u, float $amount = 500)
    {
        // A historical approved deposit with NO currency metadata, and an investment funded from it that Financial had already valued.
        $d = $this->deposit($u, $amount, null, 'bank_transfer', 'approved');
        $p = $this->invest($u, $amount, 30, 'active');
        DB::table('investment_accounts')->where('id', $p->id)->update(['source_deposit_id' => $d->id]);
        return [$d, $p];
    }

    private function resolve(FxReconciliationItem $i, array $body)
    {
        return $this->controls()->postJson("/api/financial/reconciliation/{$i->id}/resolve", $body);
    }

    // ── cryptocurrency (§4E) ────────────────────────────────────────────────────────────────────────────

    public function test_crypto_deposits_are_not_valued_for_rank_eligibility_and_are_queued_for_review(): void
    {
        $u = $this->cash($this->investor(), 0);
        $d = $this->deposit($u, 500, null, 'usdt');

        $this->approveDeposit($d)->assertOk();

        $this->assertSame(500.0, $this->balance($u), 'the deposit itself is processed as before');
        $this->assertSame(0, UsdValuation::where('valuable_type', 'deposit')->where('valuable_id', $d->id)->count(), 'never treated as verified principal');
        $this->assertTrue(FxReconciliationItem::where('subject_type', 'deposit')->where('subject_id', $d->id)->where('reason_code', 'crypto_unapproved')->exists());
    }

    public function test_staff_cannot_value_a_crypto_deposit_while_the_policy_is_unapproved(): void
    {
        $u = $this->cash($this->investor(), 0);
        $d = $this->deposit($u, 500, null, 'bitcoin', 'approved');

        $refused = false;
        try { app(UsdValuationService::class)->record('deposit', $d->id, 'USD', null, null, null, 'attest', $this->fin, true); }
        catch (ValidationException) { $refused = true; }

        $this->assertTrue($refused);
        $this->assertSame(0, UsdValuation::count());
        $this->assertTrue(FxReconciliationItem::where('reason_code', 'crypto_unapproved')->where('subject_id', $d->id)->exists());
    }

    public function test_a_crypto_item_cannot_be_resolved_by_adjustment_and_crypto_cannot_enter_the_rate_register(): void
    {
        $u = $this->investor();
        $d = $this->deposit($u, 100, null, 'usdt', 'approved');
        $item = app(FxControlService::class)->openItem('deposit', $d->id, 'crypto_unapproved', 'test');

        $this->resolve($item, ['outcome' => 'adjusted', 'evidence' => 'Attempt to convert a crypto deposit', 'correction' => ['currency' => 'USD']])->assertStatus(422);
        $this->controls()->postJson('/api/financial/fx-sources', ['currency' => 'USDT', 'primary_source' => 'Some exchange', 'rate_type' => 'reference', 'approval_reference' => 'FIN-APP-1', 'effective_from' => '2026-10-27'])->assertStatus(422);
        $this->assertSame(0, FxApprovedSource::count());
    }

    // ── historical records: reconciliation queue and controlled correction (§5) ─────────────────────────

    public function test_the_scan_flags_historical_deposits_and_their_investments_leave_eligibility_until_resolved(): void
    {
        $u = $this->cash($this->investor(), 0);
        [$d, $p] = $this->legacyApprovedDeposit($u, 500);
        $this->assertEquals(500.0, $this->facts($u)['current_principal'], 'before the scan the investment (already valued) counts');

        $r = $this->controls()->postJson('/api/financial/reconciliation/scan')->assertOk()->json('opened');
        $this->assertGreaterThanOrEqual(1, $r['missing_currency']);
        $this->assertTrue(FxReconciliationItem::where('subject_type', 'deposit')->where('subject_id', $d->id)->where('reason_code', 'missing_currency')->where('status', 'open')->exists());

        $f = $this->facts($u);
        $this->assertEquals(0.0, $f['current_principal'], 'flagged: not treated as verified rank-eligible principal');
        $this->assertContains($p->id, $f['blocked']);

        $again = $this->controls()->postJson('/api/financial/reconciliation/scan')->assertOk()->json('opened');
        $this->assertSame(0, array_sum($again), 'the scan is idempotent');
    }

    public function test_an_unresolved_item_stays_excluded_and_a_verified_correction_restores_eligibility_with_a_linked_snapshot(): void
    {
        $u = $this->cash($this->investor(), 0);
        [$d, $p] = $this->legacyApprovedDeposit($u, 500);
        $this->controls()->postJson('/api/financial/reconciliation/scan')->assertOk();
        $item = FxReconciliationItem::where('subject_type', 'deposit')->where('subject_id', $d->id)->firstOrFail();

        $this->resolve($item, ['outcome' => 'unresolved', 'evidence' => 'Bank statement for this deposit could not be located'])->assertOk()->assertJsonPath('status', 'unresolved');
        $this->assertEquals(0.0, $this->facts($u)['current_principal'], 'insufficient evidence: remains excluded');

        $this->resolve($item->fresh(), ['outcome' => 'adjusted', 'evidence' => 'Statement found: paid in USD on the stated date', 'correction' => ['currency' => 'USD']])
            ->assertOk()->assertJsonPath('status', 'resolved');

        $this->assertEquals(500.0, $this->facts($u)['current_principal']);
        $it = $item->fresh();
        $this->assertSame($this->fin->id, (int) $it->resolved_by);
        $this->assertNotNull($it->resolved_at);
        $snap = FxConversionSnapshot::findOrFail($it->snapshot_id);
        $this->assertSame($it->id, (int) $snap->reconciliation_item_id);
        $this->assertStringContainsString('Statement found', $snap->adjustment_reason);
        $this->assertTrue(RankBenefitAudit::where('subject_type', 'reconciliation')->where('subject_id', $it->id)->exists());
    }

    public function test_the_original_deposit_row_is_never_rewritten_by_reconciliation(): void
    {
        $u = $this->investor();
        [$d] = $this->legacyApprovedDeposit($u, 500);
        $before = DB::table('deposits')->where('id', $d->id)->first();

        $this->controls()->postJson('/api/financial/reconciliation/scan')->assertOk();
        $item = FxReconciliationItem::where('subject_id', $d->id)->firstOrFail();
        $this->resolve($item, ['outcome' => 'adjusted', 'evidence' => 'Statement found: paid in USD on the stated date', 'correction' => ['currency' => 'USD']])->assertOk();

        $this->assertEquals($before, DB::table('deposits')->where('id', $d->id)->first(), 'the historical record is preserved as it was');
    }

    public function test_resolution_needs_evidence_and_an_authorised_officer(): void
    {
        $u = $this->investor();
        [$d] = $this->legacyApprovedDeposit($u, 500);
        $item = app(FxControlService::class)->openItem('deposit', $d->id, 'missing_currency', 'test');

        $this->resolve($item, ['outcome' => 'unresolved', 'evidence' => 'short'])->assertStatus(422);
        $this->actingAs($u, 'sanctum')->postJson("/api/financial/reconciliation/{$item->id}/resolve", ['outcome' => 'unresolved', 'evidence' => 'An investor trying to resolve their own record'])->assertStatus(403);
        $this->assertSame('open', $item->fresh()->status);
    }

    // ── immutable snapshots (§4B) ───────────────────────────────────────────────────────────────────────

    public function test_a_verified_conversion_is_snapshotted_from_the_recorded_values_only(): void
    {
        $u = $this->investor();
        $d = $this->deposit($u, 201, null, 'bank_transfer', 'approved');
        DB::table('deposits')->where('id', $d->id)->update(['paid_amount' => 300000, 'paid_currency' => 'NGN', 'fx_rate' => 0.00067, 'rate_source' => 'Approved NGN source',
            'rate_date' => '2026-10-26', 'approved_amount' => 201.00, 'payment_verified_at' => now(), 'payment_verified_by' => $this->fin->id, 'external_reference' => 'REF-NGN-1']);

        $s = app(FxControlService::class)->snapshotFromDeposit($d->fresh(), $this->fin);

        $this->assertSame('NGN', $s->original_currency);
        $this->assertEquals(300000.0, (float) $s->original_amount);
        $this->assertSame('NGN/USD', $s->pair);
        $this->assertEquals(0.00067, (float) $s->rate);
        $this->assertSame('Approved NGN source', $s->rate_source);
        $this->assertSame('2026-10-26', $s->rate_timestamp->toDateString());
        $this->assertNotNull($s->converted_at);
        $this->assertSame('reference_rate_manual', $s->conversion_method);
        $this->assertEquals(201.0, (float) $s->internal_usd);
        $this->assertEquals(201.00, (float) $s->posted_usd);
        $this->assertSame('half_up_2dp', $s->rounding_method);
        $this->assertSame('REF-NGN-1', $s->supporting_reference);
        $this->assertSame('verified', $s->status);

        $this->assertNull(app(FxControlService::class)->snapshotFromDeposit($d->fresh(), $this->fin), 'idempotent: one snapshot per verified deposit');
        $this->assertSame(1, FxConversionSnapshot::count());
    }

    public function test_no_snapshot_and_no_rate_is_ever_invented_when_the_recorded_rate_is_missing(): void
    {
        $u = $this->investor();
        $d = $this->deposit($u, 201, null, 'bank_transfer', 'approved');
        DB::table('deposits')->where('id', $d->id)->update(['paid_amount' => 300000, 'paid_currency' => 'NGN', 'payment_verified_at' => now(), 'payment_verified_by' => $this->fin->id]);

        $this->assertNull(app(FxControlService::class)->snapshotFromDeposit($d->fresh(), $this->fin));
        $this->assertSame(0, FxConversionSnapshot::count());
    }

    public function test_a_usd_deposit_snapshot_is_usd_native_at_rate_one(): void
    {
        $u = $this->investor();
        $d = $this->deposit($u, 250, null, 'bank_transfer', 'approved');
        DB::table('deposits')->where('id', $d->id)->update(['paid_amount' => 250, 'paid_currency' => 'USD', 'approved_amount' => 250, 'payment_verified_at' => now(), 'payment_verified_by' => $this->fin->id]);

        $s = app(FxControlService::class)->snapshotFromDeposit($d->fresh(), $this->fin);
        $this->assertSame('usd_native', $s->conversion_method);
        $this->assertEquals(1.0, (float) $s->rate);
        $this->assertEquals(250.00, (float) $s->posted_usd);
    }

    public function test_snapshots_cannot_be_edited_or_deleted(): void
    {
        $u = $this->investor();
        $d = $this->deposit($u, 250, null, 'bank_transfer', 'approved');
        DB::table('deposits')->where('id', $d->id)->update(['paid_amount' => 250, 'paid_currency' => 'USD', 'approved_amount' => 250, 'payment_verified_at' => now(), 'payment_verified_by' => $this->fin->id]);
        $s = app(FxControlService::class)->snapshotFromDeposit($d->fresh(), $this->fin);

        try { $s->update(['rate' => 2]); $this->fail('snapshot was editable'); } catch (LogicException) { $this->assertTrue(true); }
        try { $s->delete(); $this->fail('snapshot was deletable'); } catch (LogicException) { $this->assertTrue(true); }
    }

    public function test_a_correction_is_a_new_superseding_snapshot_that_keeps_the_original_rate_and_reason(): void
    {
        $u = $this->investor();
        $p = $this->invest($u, 300000, 30, 'active', false);
        $fx = app(FxControlService::class);
        $i1 = $fx->openItem('investment', $p->id, 'no_valuation', 'first');

        $this->resolve($i1, ['outcome' => 'adjusted', 'evidence' => 'Valued at the approved reference rate of the day', 'correction' => ['currency' => 'NGN', 'rate' => 0.00067, 'rate_source' => 'Approved NGN source', 'rate_date' => '2026-10-26']])->assertOk();
        $this->assertEquals(201.0, $this->facts($u)['current_principal']);

        $i2 = $fx->openItem('investment', $p->id, 'inconsistent_amount', 'second');   // a later review finds the rate was wrong
        $this->assertEquals(0.0, $this->facts($u)['current_principal'], 'an open item holds the position out of eligibility again');

        $this->resolve($i2, ['outcome' => 'adjusted', 'evidence' => 'Settlement record shows a different rate', 'correction' => ['currency' => 'NGN', 'rate' => 0.0007, 'rate_source' => 'Settlement record', 'rate_date' => '2026-10-26']])->assertOk();

        $snaps = FxConversionSnapshot::where('subject_type', 'investment')->where('subject_id', $p->id)->orderBy('id')->get();
        $this->assertCount(2, $snaps);
        $this->assertEquals(0.00067, (float) $snaps[0]->rate, 'the original snapshot is untouched');
        $this->assertSame($snaps[0]->id, (int) $snaps[1]->supersedes_id);
        $this->assertEquals(0.00067, (float) $snaps[1]->previous_rate, 'original rate retained beside the replacement');
        $this->assertStringContainsString('different rate', $snaps[1]->adjustment_reason);
        $this->assertSame(2, UsdValuation::where('valuable_type', 'investment')->where('valuable_id', $p->id)->count(), 'valuations are append-only');
        $this->assertEquals(210.0, $this->facts($u)['current_principal'], 'the latest verified valuation is the one in force');
    }

    // ── rounding, unsupported and missing rates, staleness ──────────────────────────────────────────────

    public function test_usd_is_posted_with_exact_decimal_half_up_rounding(): void
    {
        $u = $this->investor();
        $p = $this->invest($u, 100.5, 30, 'active', false);

        $v = app(UsdValuationService::class)->record('investment', $p->id, 'NGN', 0.01, 'Test source', '2026-10-01', 'rounding check', $this->fin, true, true);

        $this->assertEquals(1.01, (float) $v->usd_amount, '100.5 × 0.01 = 1.005 posts as 1.01 (half-up), not 1.00');
    }

    public function test_an_unsupported_currency_is_refused_and_nothing_is_converted(): void
    {
        $u = $this->investor();
        $p = $this->invest($u, 100, 30, 'active', false);
        $refused = false;
        try { app(UsdValuationService::class)->record('investment', $p->id, 'QQQ', 1, 'Some source', '2026-10-01', 'n', $this->fin, true, true); }
        catch (ValidationException) { $refused = true; }

        $this->assertTrue($refused);
        $this->assertSame(0, UsdValuation::count());
        $this->assertEquals(0.0, $this->facts($u)['current_principal']);
    }

    public function test_a_missing_rate_is_never_defaulted_to_one(): void
    {
        $u = $this->investor();
        $p = $this->invest($u, 100, 30, 'active', false);
        $refused = false;
        try { app(UsdValuationService::class)->record('investment', $p->id, 'NGN', null, 'Some source', '2026-10-01', 'n', $this->fin, true, true); }
        catch (ValidationException) { $refused = true; }

        $this->assertTrue($refused);
        $this->assertSame(0, UsdValuation::count());
        $this->assertEquals(0.0, $this->facts($u)['current_principal'], 'unverified stays excluded');
    }

    public function test_staleness_is_only_assessed_where_a_maximum_age_has_been_approved(): void
    {
        $fx = app(FxControlService::class);
        $this->assertNull($fx->isStale('NGN', now()->subDays(3)), 'no approved source: nothing is assumed');

        FxApprovedSource::create(['currency' => 'NGN', 'reference_currency' => 'USD', 'direction' => 'to_reference', 'primary_source' => 'Approved NGN source', 'rate_type' => 'reference',
            'max_age_hours' => null, 'approval_reference' => 'FIN-APP-1', 'approved_by' => $this->fin->id, 'approved_by_name' => 'Fin', 'effective_from' => now()->subDay()->toDateString(), 'created_at' => now()]);
        $this->assertNull($fx->isStale('NGN', now()->subDays(3)), 'a source with no approved maximum age cannot be assessed');

        FxApprovedSource::create(['currency' => 'GHS', 'reference_currency' => 'USD', 'direction' => 'to_reference', 'primary_source' => 'Approved GHS source', 'rate_type' => 'reference',
            'max_age_hours' => 24, 'approval_reference' => 'FIN-APP-2', 'approved_by' => $this->fin->id, 'approved_by_name' => 'Fin', 'effective_from' => now()->subDay()->toDateString(), 'created_at' => now()]);
        $this->assertTrue($fx->isStale('GHS', now()->subDays(3)));
        $this->assertFalse($fx->isStale('GHS', now()->subHours(2)));
    }

    // ── approved rate register (§4A) ────────────────────────────────────────────────────────────────────

    public function test_no_provider_is_approved_until_the_register_has_a_documented_entry(): void
    {
        $r = $this->controls()->getJson('/api/financial/fx-sources')->assertOk()->json();
        $this->assertSame([], $r['data']);
        $this->assertStringContainsString('No exchange-rate provider is treated as approved', $r['note']);

        $this->controls()->postJson('/api/financial/fx-sources', ['currency' => 'NGN', 'primary_source' => 'Approved NGN source', 'rate_type' => 'reference', 'approval_reference' => 'x', 'effective_from' => '2026-10-27'])->assertStatus(422);
        $this->controls()->postJson('/api/financial/fx-sources', ['currency' => 'NGN', 'primary_source' => 'Approved NGN source', 'rate_type' => 'reference', 'max_age_hours' => 24,
            'approval_reference' => 'FIN-APP-2026-5', 'effective_from' => '2026-10-27'])->assertCreated();
        $this->assertSame(1, FxApprovedSource::count());
        $this->assertTrue(RankBenefitAudit::where('subject_type', 'reconciliation')->where('action', 'fx_source_registered')->exists());

        $this->actingAs($this->investor(), 'sanctum')->postJson('/api/financial/fx-sources', ['currency' => 'GHS', 'primary_source' => 'X source', 'rate_type' => 'reference', 'approval_reference' => 'FIN-APP-9', 'effective_from' => '2026-10-27'])->assertStatus(403);
    }

    public function test_no_live_exchange_rate_provider_is_called_anywhere(): void
    {
        Http::fake();
        $u = $this->cash($this->investor(), 0);
        $p = $this->invest($u, 300000, 30, 'active', false);
        $this->assertFalse((bool) config('financial.live_fx_enabled'));

        $this->controls()->postJson('/api/financial/reconciliation/scan')->assertOk();
        app(UsdValuationService::class)->record('investment', $p->id, 'NGN', 0.00067, 'Approved NGN source', '2026-10-26', 'staff entered', $this->fin, true, true);
        app(\App\Services\Rank\RankEvaluationService::class)->evaluateUser($u->id, 'test');

        Http::assertNothingSent();
    }
}
