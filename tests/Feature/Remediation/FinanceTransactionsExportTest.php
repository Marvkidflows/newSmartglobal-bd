<?php
// LOCATION: tests/Feature/Remediation/FinanceTransactionsExportTest.php   (NEW — 19 Nov)
// Finance transactions export (filters, cap/truncation, roles, formula injection, sensitive-field exclusion) PLUS focused deposit/FX tests
// (tolerance, acknowledgement, FX change, duplicate approval, non-USD inputs).
// WRITTEN, NOT EXECUTED. Run: php artisan test --filter=FinanceTransactionsExportTest

namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\FinancialAuditLog;
use App\Models\Withdrawal;
use App\Services\DepositPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Rank\FinancialTestCase;

class FinanceTransactionsExportTest extends FinancialTestCase
{
    use RefreshDatabase;

    private function export(string $q = '', $as = null)
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($as ?? $this->fin, 'sanctum')->withHeaders(['Accept' => 'application/json,text/csv'])->get('/api/financial/transactions-export' . $q);
    }

    private function rows(string $csv): array
    {
        return array_map('str_getcsv', array_values(array_filter(explode("\n", trim($csv)))));
    }

    // ── export ───────────────────────────────────────────────────────────────────
    public function test_roles(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/financial/transactions-export')->assertUnauthorized();
        $this->export('', $this->investor())->assertForbidden();
        $this->export('', $this->user('marvflow'))->assertForbidden();
        $this->export()->assertOk();
        $this->export('', $this->admin)->assertOk();
    }

    private function user(string $role)
    {
        return \App\Models\User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    public function test_type_and_date_filters(): void
    {
        $u = $this->investor();
        $old = $this->deposit($u, 10);
        $old->forceFill(['created_at' => now()->subDays(30)])->save();
        $new = $this->deposit($u, 20);
        $this->withdrawal($u, 5);
        $csv = $this->export('?type=deposit&date_from=' . now()->subDays(2)->toDateString())->assertOk()->streamedContent();
        $r = $this->rows($csv);
        $this->assertCount(2, $r);                                  // header + 1 deposit
        $this->assertSame('deposit', $r[1][0]);
        $this->assertSame($new->transaction_reference, $r[1][1]);
        $this->assertStringNotContainsString($old->transaction_reference, $csv);
        $this->export('?date_from=2026-02-01&date_to=2026-01-01')->assertStatus(422);
        $this->export('?type=bogus')->assertStatus(422);
    }

    public function test_export_cap_truncates_flags_and_audits(): void
    {
        config(['financial.transactions_export_cap' => 2]);
        $u = $this->investor();
        for ($n = 0; $n < 4; $n++) $this->deposit($u, 10 + $n);
        $res = $this->export('?type=deposit')->assertOk();
        $this->assertCount(3, $this->rows($res->streamedContent()));   // header + cap
        $this->assertSame('true', $res->headers->get('X-Export-Truncated'));
        $this->assertTrue(FinancialAuditLog::where('action', 'transactions.exported')->latest('id')->first()->new_value['truncated']);
    }

    public function test_under_the_cap_is_not_flagged(): void
    {
        config(['financial.transactions_export_cap' => 10]);
        $this->deposit($this->investor(), 10);
        $this->assertNull($this->export('?type=deposit')->headers->get('X-Export-Truncated'));
    }

    public function test_formula_injection_is_neutralised_and_sensitive_fields_excluded(): void
    {
        $u = $this->investor(['name' => '=cmd|calc', 'email' => 'leak.me@ssi-test.test']);
        Deposit::create(['user_id' => $u->id, 'amount' => 25, 'payment_method' => 'bank_transfer', 'transaction_reference' => '+SUM(1)', 'status' => 'approved', 'admin_notes' => 'INTERNAL NOTE']);
        Withdrawal::create(['user_id' => $u->id, 'amount' => 5, 'method' => 'bitcoin', 'account_details' => json_encode(['wallet_address' => 'bc1LEAKADDR']), 'status' => 'pending']);
        BalanceAdjustment::create(['user_id' => $u->id, 'admin_id' => $this->admin->id, 'type' => 'add', 'amount' => 1, 'balance_before' => 0, 'balance_after' => 1, 'reason' => 'INTERNAL REASON']);
        $csv = $this->export()->assertOk()->streamedContent();
        foreach ([",=cmd|calc", ',+SUM(1)'] as $raw) $this->assertStringNotContainsString($raw, $csv);
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringContainsString("'+SUM(1)", $csv);
        foreach (['leak.me@ssi-test.test', 'INTERNAL NOTE', 'INTERNAL REASON', 'bc1LEAKADDR'] as $leak) $this->assertStringNotContainsString($leak, $csv);
        $this->assertSame(['type', 'reference', 'investor_id', 'investor_name', 'direction', 'amount', 'currency', 'status', 'fee', 'net', 'created_at', 'processed_at'], $this->rows($csv)[0]);
    }

    public function test_export_is_read_only(): void
    {
        $u = $this->cash($this->investor(), 12);
        $this->deposit($u, 5);
        $before = [DB::table('deposits')->get()->toJson(), DB::table('users')->get()->toJson()];
        $this->export()->assertOk()->streamedContent();
        $this->assertSame($before, [DB::table('deposits')->get()->toJson(), DB::table('users')->get()->toJson()]);
    }

    // ── deposit / FX ─────────────────────────────────────────────────────────────
    private function record(Deposit $d, array $x, bool $ackRate = true, bool $ackDiff = false): Deposit
    {
        return app(DepositPaymentService::class)->record($d, $this->fin, $x + ['payment_date' => now()->toDateString(), 'note' => 'Checked against statement'], true, $ackRate, $ackDiff);
    }

    private function eur(array $x = []): array
    {
        return $x + ['paid_amount' => 100, 'paid_currency' => 'EUR', 'rate' => '1.10', 'rate_source' => 'Bank statement', 'rate_date' => now()->toDateString()];
    }

    public function test_approved_amount_within_tolerance_needs_no_acknowledgement(): void
    {
        $d = $this->record($this->deposit($this->investor(), 100), $this->eur(['approved_amount' => 110.50]));   // 0.45% off 110.00
        $this->assertEquals(110.50, (float) $d->amount);
        $this->assertFalse((bool) $d->amount_difference_acknowledged);
    }

    public function test_approved_amount_beyond_tolerance_is_refused_without_acknowledgement_and_stores_nothing(): void
    {
        $d = $this->deposit($this->investor(), 100);
        try { $this->record($d, $this->eur(['approved_amount' => 125])); $this->fail('expected refusal'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('amount_difference', $e->errors()); }
        $this->assertEquals(100, (float) $d->fresh()->amount);
        $this->assertNull($d->fresh()->approved_amount);
    }

    public function test_acknowledged_difference_is_stored(): void
    {
        $d = $this->record($this->deposit($this->investor(), 100), $this->eur(['approved_amount' => 125]), true, true);
        $this->assertEquals(125, (float) $d->amount);
        $this->assertTrue((bool) $d->amount_difference_acknowledged);
    }

    public function test_tolerance_follows_configuration(): void
    {
        config(['ranks.approved_amount_tolerance_percent' => 50]);
        $d = $this->record($this->deposit($this->investor(), 100), $this->eur(['approved_amount' => 125]));
        $this->assertFalse((bool) $d->amount_difference_acknowledged);
    }

    public function test_fx_change_after_verification_blocks_approval_and_nothing_is_credited(): void
    {
        $u = $this->cash($this->investor(), 0);
        $d = $this->record($this->deposit($u, 100), $this->eur());
        $d->update(['amount' => 500]);                                    // rate/amount tampered after verification
        $this->approveDeposit($d->fresh())->assertStatus(422);
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertSame(0.0, $this->balance($u));
    }

    public function test_non_usd_requires_rate_source_and_date(): void
    {
        foreach (['rate', 'rate_source', 'rate_date'] as $drop) {
            $x = $this->eur(); unset($x[$drop]);
            $d = $this->deposit($this->investor(), 100);
            try { $this->record($d, $x); $this->fail("missing {$drop} accepted"); } catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
            $this->assertNull($d->fresh()->approved_amount);
        }
    }

    public function test_duplicate_approval_credits_once(): void
    {
        $u = $this->cash($this->investor(), 0);
        $d = $this->deposit($u, 100);
        $this->approveDeposit($d)->assertOk();
        $this->approveDeposit($d)->assertStatus(422);
        $this->assertSame(100.0, $this->balance($u));
        $this->assertSame(1, BalanceAdjustment::where('user_id', $u->id)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badCaps')]
    public function test_invalid_cap_configuration_falls_back_to_the_default_and_never_unlimited(mixed $bad, int $expected): void
    {
        config(['financial.transactions_export_cap' => $bad]);
        $u = $this->investor();
        for ($n = 0; $n < 3; $n++) $this->deposit($u, 10 + $n);
        $res = $this->export('?type=deposit')->assertOk();
        $this->assertCount(min(3, $expected) + 1, $this->rows($res->streamedContent()));
        $this->assertSame($expected, FinancialAuditLog::where('action', 'transactions.exported')->latest('id')->first()->new_value['cap_per_source']);
    }

    public static function badCaps(): array
    {
        return [[0, 5000], [-5, 5000], ['abc', 5000], [null, 5000], [999999999, 50000], ['1e12', 50000]];
    }

    public function test_truncation_keeps_the_newest_rows_deterministically(): void
    {
        config(['financial.transactions_export_cap' => 2]);
        $u = $this->investor();
        $refs = [];
        for ($n = 0; $n < 4; $n++) $refs[] = $this->deposit($u, 10 + $n)->transaction_reference;
        $csv = $this->export('?type=deposit')->streamedContent();
        $this->assertStringContainsString($refs[3], $csv);
        $this->assertStringContainsString($refs[2], $csv);
        $this->assertStringNotContainsString($refs[0], $csv);
        $this->assertSame($csv, $this->export('?type=deposit')->streamedContent());   // same input, same output
    }

    public function test_exactly_cap_rows_is_not_reported_as_truncated(): void
    {
        config(['financial.transactions_export_cap' => 3]);
        $u = $this->investor();
        for ($n = 0; $n < 3; $n++) $this->deposit($u, 10 + $n);
        $this->assertNull($this->export('?type=deposit')->headers->get('X-Export-Truncated'));
        $this->assertFalse(FinancialAuditLog::where('action', 'transactions.exported')->latest('id')->first()->new_value['truncated']);
    }

    public function test_cors_exposes_the_truncation_header_to_browsers(): void
    {
        $this->assertContains('X-Export-Truncated', config('cors.exposed_headers'));
        config(['financial.transactions_export_cap' => 1]);
        $u = $this->investor(); $this->deposit($u, 1); $this->deposit($u, 2);
        $res = $this->export('?type=deposit');
        $res->assertOk();
        $this->assertSame('true', $res->headers->get('X-Export-Truncated'));
    }
}
