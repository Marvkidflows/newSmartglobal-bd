<?php
// LOCATION: tests/Feature/Rank/DecimalLedgerPrecisionTest.php — Phase B regression tests for the exact-decimal ledger paths.
//
// All data is isolated test data (in-memory SQLite per phpunit.xml). Financial flags are switched ON only inside this test class's config
// (staging behaviour); the shipped defaults stay OFF (asserted by the existing MoneyFlowCharacterizationTest). Nothing touches a real balance.
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php artisan test --filter=DecimalLedgerPrecisionTest

namespace Tests\Feature\Rank;

use App\Models\BalanceAdjustment;
use App\Models\PrincipalMovement;
use App\Services\Ledger\PrincipalService;

class DecimalLedgerPrecisionTest extends FinancialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->financialRulesOn();
    }

    private function back(int $positionId, $amount, string $key)
    {
        return $this->controls()->postJson("/api/financial/positions/{$positionId}/return-to-wallet", [
            'amount' => $amount, 'idempotency_key' => $key, 'reference' => 'REF-' . $key, 'evidence' => 'Verified settlement record for ' . $key,
        ]);
    }

    private function remaining($p): string
    {
        return app(PrincipalService::class)->remainingDecimal($p->fresh());
    }

    public function test_cent_level_partial_redemptions_add_up_to_exactly_the_position(): void
    {
        $u = $this->cash($this->investor(), 0);
        $p = $this->invest($u, 100.10);

        $this->back($p->id, 33.37, 'cents-key-01')->assertCreated();
        $this->back($p->id, 33.37, 'cents-key-02')->assertCreated();
        $this->assertSame('33.36', $this->remaining($p));
        $this->back($p->id, 33.36, 'cents-key-03')->assertCreated();

        $this->assertSame('0.00', $this->remaining($p), 'exactly nothing left, with no float residue');
        $this->assertSame(100.10, $this->balance($u));
        $this->assertSame(3, PrincipalMovement::where('investment_id', $p->id)->where('kind', 'return_to_wallet')->count());
        $this->back($p->id, 0.01, 'cents-key-04')->assertStatus(422);   // one cent more than remains is refused
    }

    public function test_one_cent_over_the_remaining_principal_is_refused_and_the_exact_remainder_is_accepted(): void
    {
        $u = $this->cash($this->investor(), 0);
        $p = $this->invest($u, 250.05);

        $this->back($p->id, 250.06, 'over-key-0001')->assertStatus(422);
        $this->assertSame('250.05', $this->remaining($p));
        $this->assertSame(0, PrincipalMovement::where('investment_id', $p->id)->count(), 'a refused redemption writes nothing');
        $this->assertSame(0.0, $this->balance($u));

        $this->back($p->id, 250.05, 'over-key-0002')->assertCreated();
        $this->assertSame('0.00', $this->remaining($p));
    }

    public function test_float_noise_in_a_request_is_removed_before_it_reaches_the_ledger(): void
    {
        $u = $this->cash($this->investor(), 0);
        $p = $this->invest($u, 100);

        $this->back($p->id, 0.1 + 0.2, 'noise-key-001')->assertCreated();   // JSON carries 0.30000000000000004

        $m = PrincipalMovement::where('investment_id', $p->id)->firstOrFail();
        $this->assertSame('0.30', (string) $m->amount);
        $this->assertSame(0.30, $this->balance($u));
        $this->assertSame('99.70', $this->remaining($p));
        $this->assertSame('0.30', (string) BalanceAdjustment::where('user_id', $u->id)->firstOrFail()->amount);
    }

    public function test_a_retry_of_the_same_redemption_is_idempotent_to_the_cent(): void
    {
        $u = $this->cash($this->investor(), 0);
        $p = $this->invest($u, 500.55);

        $first = $this->back($p->id, 100.01, 'retry-key-001')->assertCreated()->json('id');
        $second = $this->back($p->id, 100.01, 'retry-key-001')->assertCreated()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(100.01, $this->balance($u), 'credited once, not twice');
        $this->assertSame('400.54', $this->remaining($p));
    }

    public function test_correction_limits_are_exact(): void
    {
        $u = $this->cash($this->investor(), 0);
        $p = $this->invest($u, 100.00);
        $ok = fn (string $key, $signed) => $this->controls()->postJson("/api/financial/positions/{$p->id}/correction", [
            'signed_amount' => $signed, 'idempotency_key' => $key, 'approval_reference' => 'FIN-APP-2026-7', 'evidence' => 'Late-recorded redemption, evidence on file',
        ]);

        $ok('corr-key-0001', 100.01)->assertStatus(422);     // more than remains
        $ok('corr-key-0002', 99.99)->assertCreated();
        $this->assertSame('0.01', $this->remaining($p));
        $ok('corr-key-0003', -100.00)->assertStatus(422);    // cannot restore more than was reduced (99.99)
        $ok('corr-key-0004', -99.99)->assertCreated();
        $this->assertSame('100.00', $this->remaining($p));
    }

    public function test_wallet_allocation_checks_the_balance_and_plan_limits_to_the_cent(): void
    {
        $u = $this->cash($this->investor(), 100.00);
        $plan = $this->plan(10, 30, 2.5);

        $this->investFromWallet($u, $plan, 100.01)->assertStatus(422);   // one cent more than the wallet holds
        $this->assertSame(100.00, $this->balance($u));
        $this->investFromWallet($u, $plan, 9.99)->assertStatus(422);     // one cent under the plan minimum
        $this->assertSame(100.00, $this->balance($u));
        $this->assertSame(0, \App\Models\InvestmentAccount::where('user_id', $u->id)->count(), 'nothing is created by a refused allocation');

        $this->investFromWallet($u, $plan, 100.00)->assertSuccessful();  // exactly the balance
        $this->assertSame(0.0, $this->balance($u));
    }

    public function test_allocation_posts_exact_profit_and_total_return_and_debits_exactly_once(): void
    {
        $u = $this->cash($this->investor(), 1000.00);
        $plan = $this->plan(1, 30, 7.5);

        $this->investFromWallet($u, $plan, 6.60)->assertSuccessful();   // 6.60 × 7.5% = 0.495 exactly

        $p = $this->latestPosition($u);
        $this->assertSame('6.60', (string) $p->amount);
        $this->assertSame('0.50', (string) $p->expected_profit);
        $this->assertSame('7.10', (string) $p->total_return);
        $this->assertSame(993.40, $this->balance($u));
        $this->assertSame(1, \App\Models\InvestmentAllocation::where('investment_id', $p->id)->count());
    }

    public function test_a_classified_withdrawal_must_add_up_exactly(): void
    {
        $u = $this->cash($this->investor(), 0);
        $p = $this->invest($u, 300.00);
        $w = $this->withdrawal($u, 100.03);
        $classify = fn (array $components) => $this->controls()->postJson("/api/financial/withdrawals/{$w->id}/classify", ['components' => $components]);

        $classify([['component' => 'principal', 'amount' => 100.02, 'investment_id' => $p->id]])->assertStatus(422);   // one cent short
        $classify([['component' => 'principal', 'amount' => 100.04, 'investment_id' => $p->id]])->assertStatus(422);   // one cent over
        $this->assertSame(0, \App\Models\WithdrawalComponent::where('withdrawal_id', $w->id)->count(), 'a refused classification writes nothing');

        $classify([
            ['component' => 'principal', 'amount' => 0.1, 'investment_id' => $p->id],
            ['component' => 'principal', 'amount' => 0.2, 'investment_id' => $p->id],
            ['component' => 'profit', 'amount' => 99.73, 'verification_note' => 'Profit available under the plan terms (test)'],
        ])->assertSuccessful();   // 0.1 + 0.2 + 99.73 = 100.03 exactly, which floats cannot guarantee
        $this->assertSame(3, \App\Models\WithdrawalComponent::where('withdrawal_id', $w->id)->count());
    }

    public function test_a_principal_component_cannot_exceed_the_position_by_one_cent(): void
    {
        $u = $this->cash($this->investor(), 0);
        $p = $this->invest($u, 100.00);
        $w = $this->withdrawal($u, 100.01);

        $this->controls()->postJson("/api/financial/withdrawals/{$w->id}/classify", ['components' => [
            ['component' => 'principal', 'amount' => 100.01, 'investment_id' => $p->id],
        ]])->assertStatus(422);
    }

    public function test_non_numeric_component_amounts_are_a_clean_422_never_a_server_error(): void
    {
        $u = $this->cash($this->investor(), 0);
        $p = $this->invest($u, 100.00);
        $w = $this->withdrawal($u, 10.00);

        $this->controls()->postJson("/api/financial/withdrawals/{$w->id}/classify", ['components' => [
            ['component' => 'principal', 'amount' => 'ten', 'investment_id' => $p->id],
        ]])->assertStatus(422);
    }

    public function test_decimal_remaining_and_the_float_view_agree_and_the_float_view_keeps_its_type(): void
    {
        $u = $this->cash($this->investor(), 0);
        $p = $this->invest($u, 1000.00);
        $this->back($p->id, 600.00, 'agree-key-001')->assertCreated();

        $svc = app(PrincipalService::class);
        $this->assertSame('400.00', $svc->remainingDecimal($p->fresh()));
        $this->assertSame(400.0, $svc->remaining($p->fresh()), 'remaining() still returns a float for existing callers');
    }
}
