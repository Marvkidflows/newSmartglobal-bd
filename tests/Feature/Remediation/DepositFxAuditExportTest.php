<?php
// LOCATION: tests/Feature/Remediation/DepositFxAuditExportTest.php   (NEW — 18 Nov, priorities 2-4)
//  A. Deposit/FX: an unverified deposit cannot be approved; the credited `amount` becomes the staff-approved USD figure; a later change to
//     `amount` blocks approval again; a non-USD payment without a rate is refused.
//  B. Audit trail while the ledger is OFF: profit credit writes an append-only audit row and NO BalanceAdjustment (wallet history not double counted).
//  C. Finance transactions export: role-gated, no e-mail / notes / reasons, audited, formula-safe, changes nothing.
// WRITTEN, NOT EXECUTED (no vendor/). Run: php artisan test --filter=DepositFxAuditExportTest

namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\FinancialAuditLog;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\DepositPaymentService;
use App\Services\InvestmentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DepositFxAuditExportTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $x = []): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'email' => Str::lower(Str::random(8)) . '@ssi-test.test', 'email_verified_at' => now()] + $x);
    }

    private function deposit(User $inv, $amount = 100): Deposit
    {
        return Deposit::create(['user_id' => $inv->id, 'amount' => $amount, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-' . Str::random(6), 'status' => 'pending']);
    }

    private function planId(): int
    {
        return DB::table('investment_plans')->insertGetId(['name' => 'P' . uniqid(), 'min_amount' => 1, 'duration_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── A. deposit / FX ───────────────────────────────────────────────────────────
    public function test_an_unverified_deposit_has_approval_blockers(): void
    {
        $d = $this->deposit($this->user('investor'));
        $this->assertNotEmpty(app(DepositPaymentService::class)->approvalBlockers($d));
    }

    public function test_recording_a_verified_eur_payment_sets_the_credited_amount_to_the_approved_usd_figure(): void
    {
        $staff = $this->user('financial');
        $d = $this->deposit($this->user('investor'), 100);
        $svc = app(DepositPaymentService::class);
        $out = $svc->record($d, $staff, ['paid_amount' => 100, 'paid_currency' => 'EUR', 'rate' => '1.10', 'rate_source' => 'Bank statement', 'rate_date' => now()->toDateString(),
            'payment_date' => now()->toDateString(), 'note' => 'Checked against the bank statement'], false, true);
        $this->assertEquals(110.00, (float) $out->amount, 'amount (what approval credits) must be the approved USD value, not the requested figure');
        $this->assertEquals(100.00, (float) $out->paid_amount);
        $this->assertSame('EUR', $out->paid_currency);
        $this->assertSame([], $svc->approvalBlockers($out));
    }

    public function test_changing_the_amount_after_verification_blocks_approval_again(): void
    {
        $staff = $this->user('financial');
        $d = $this->deposit($this->user('investor'), 100);
        $svc = app(DepositPaymentService::class);
        $out = $svc->record($d, $staff, ['paid_amount' => 100, 'paid_currency' => 'USD', 'note' => 'Received in USD, checked'], true);
        $this->assertSame([], $svc->approvalBlockers($out));
        $out->update(['amount' => 9999]);
        $this->assertNotEmpty($svc->approvalBlockers($out->fresh()));
    }

    public function test_a_non_usd_payment_without_a_rate_is_refused_and_nothing_is_stored(): void
    {
        $d = $this->deposit($this->user('investor'), 100);
        try {
            app(DepositPaymentService::class)->record($d, $this->user('financial'), ['paid_amount' => 100, 'paid_currency' => 'EUR', 'note' => 'No rate supplied here']);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rate', $e->errors());
        }
        $this->assertNull($d->fresh()->payment_verified_at);
        $this->assertEquals(100, (float) $d->fresh()->amount);
    }

    // ── B. audit trail with the ledger off ────────────────────────────────────────
    public function test_profit_credit_writes_an_audit_row_and_no_balance_adjustment(): void
    {
        $inv = $this->user('investor', ['balance' => 10]);
        $i = InvestmentAccount::create(['user_id' => $inv->id, 'investment_plan_id' => $this->planId(), 'amount' => 100, 'profit_percentage' => 10, 'expected_profit' => 10, 'total_return' => 110,
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDay()->toDateString(), 'remaining_days' => 0, 'status' => 'active']);

        $r = app(InvestmentSettlementService::class)->complete($i->id, 'schedule');

        $this->assertTrue($r['completed']);
        $this->assertEquals(20, $inv->fresh()->balance);                                  // profit only; principal is not credited by settlement
        $log = FinancialAuditLog::where('action', 'investment.profit_credited')->where('entity_id', $i->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('system', $log->department);
        $this->assertSame(0, BalanceAdjustment::where('user_id', $inv->id)->count(), 'no BalanceAdjustment, so the wallet history is not double counted');
        // second call: refused and no second audit row
        app(InvestmentSettlementService::class)->complete($i->id, 'schedule');
        $this->assertSame(1, FinancialAuditLog::where('action', 'investment.profit_credited')->where('entity_id', $i->id)->count());
        $this->assertEquals(20, $inv->fresh()->balance);
    }

    // ── C. Finance transactions export ────────────────────────────────────────────
    private function fetch(User $u, string $q = '') { $this->app['auth']->forgetGuards(); return $this->actingAs($u, 'sanctum')->get('/api/financial/transactions-export' . $q); }

    public function test_export_is_refused_for_investors_and_anonymous(): void
    {
        $this->fetch($this->user('investor'))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/financial/transactions-export')->assertUnauthorized();
    }

    public function test_export_omits_sensitive_columns_is_formula_safe_audited_and_read_only(): void
    {
        $inv = $this->user('investor', ['name' => '=HYPERLINK("x")', 'email' => 'secret.person@ssi-test.test', 'balance' => 50]);
        Deposit::create(['user_id' => $inv->id, 'amount' => 25, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-EXP1', 'status' => 'approved', 'admin_notes' => 'INTERNAL NOTE']);
        Withdrawal::create(['user_id' => $inv->id, 'amount' => 5, 'method' => 'bitcoin', 'account_details' => json_encode(['wallet_address' => 'bc1SECRETADDRESS']), 'status' => 'pending']);
        BalanceAdjustment::create(['user_id' => $inv->id, 'admin_id' => $this->user('admin')->id, 'type' => 'add', 'amount' => 1, 'balance_before' => 0, 'balance_after' => 1, 'reason' => 'INTERNAL REASON']);

        $csv = $this->fetch($this->user('financial'))->assertOk()->streamedContent();

        foreach (['secret.person@ssi-test.test', 'INTERNAL NOTE', 'INTERNAL REASON', 'bc1SECRETADDRESS'] as $leak) $this->assertStringNotContainsString($leak, $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);                         // neutralised
        $this->assertStringContainsString('DEP-EXP1', $csv);
        $this->assertSame(1, FinancialAuditLog::where('action', 'transactions.exported')->count());
        $this->assertEquals(50, $inv->fresh()->balance);
    }
}
