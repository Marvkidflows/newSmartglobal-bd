<?php
// LOCATION: tests/Feature/Rank/FinancialDecisionsTest.php
//
// The Financial Team's decisions of 27 Oct 2026, with the flags ON (staging behaviour): deposit approval without automatic investment,
// wallet allocation without double counting, principal withdrawals and redemptions at position level, reinvestment without duplicate
// principal or cycle recognition, flags, and authorization. All data is isolated test data; nothing touches a real balance.
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php artisan test --filter=FinancialDecisionsTest

namespace Tests\Feature\Rank;

use App\Jobs\EvaluateInvestorRankJob;
use App\Models\BalanceAdjustment;
use App\Models\InvestmentAccount;
use App\Models\InvestmentAllocation;
use App\Models\InvestmentFlag;
use App\Models\PrincipalMovement;
use App\Models\RankBenefitAudit;
use App\Models\WalletLedgerEntry;
use App\Models\Withdrawal;
use App\Services\InvestmentSettlementService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class FinancialDecisionsTest extends FinancialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->financialRulesOn();
    }

    private function movements(?InvestmentAccount $p = null)
    {
        return PrincipalMovement::when($p, fn ($q) => $q->where('investment_id', $p->id));
    }

    private function classify(Withdrawal $w, array $components)
    {
        return $this->controls()->postJson("/api/financial/withdrawals/{$w->id}/classify", ['components' => $components]);
    }

    private function completeViaSettlement(InvestmentAccount $p): void
    {
        DB::table('investment_accounts')->where('id', $p->id)->update(['start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDay()->toDateString()]);
        app(InvestmentSettlementService::class)->complete($p->fresh(), 'admin', $this->admin->id);
    }

    // ── 1. Deposit approval and wallet treatment ────────────────────────────────────────────────────────

    public function test_deposit_approval_records_cleared_wallet_cash_and_creates_no_investment(): void
    {
        $u = $this->cash($this->investor(), 0);
        $d = $this->deposit($u, 800, $this->plan());

        $this->approveDeposit($d)->assertOk();

        $this->assertSame(800.0, $this->balance($u));
        $this->assertSame(0, InvestmentAccount::where('user_id', $u->id)->count(), 'approval must not create an active investment');
        $e = WalletLedgerEntry::where('deposit_id', $d->id)->where('classification', 'cleared_wallet_funds')->firstOrFail();
        $this->assertSame('credit', $e->direction);
        $this->assertEquals(800.00, $e->amount);
        $this->assertEquals(800.00, $e->balance_after);
    }

    public function test_a_deposit_without_a_plan_is_treated_the_same_way(): void
    {
        $u = $this->cash($this->investor(), 0);
        $this->approveDeposit($this->deposit($u, 300))->assertOk();

        $this->assertSame(300.0, $this->balance($u));
        $this->assertSame(0, InvestmentAccount::where('user_id', $u->id)->count());
        $this->assertSame(1, WalletLedgerEntry::where('classification', 'cleared_wallet_funds')->where('user_id', $u->id)->count());
    }

    public function test_wallet_cash_is_never_counted_as_invested_principal(): void
    {
        $u = $this->cash($this->investor(), 5000);
        $f = $this->facts($u);

        $this->assertEquals(0.0, $f['current_principal']);
        $this->assertSame(0, $f['count']);
    }

    // ── wallet allocation ───────────────────────────────────────────────────────────────────────────────

    public function test_allocation_debits_the_wallet_creates_one_position_and_links_the_ledger_legs(): void
    {
        $u = $this->cash($this->investor(), 1000);
        $plan = $this->plan(1, 30, 10);

        $this->investFromWallet($u, $plan, 600)->assertSuccessful();

        $this->assertSame(400.0, $this->balance($u));
        $p = $this->latestPosition($u);
        $this->assertSame('active', $p->status);
        $this->assertEquals(600.00, $p->amount);

        $alloc = InvestmentAllocation::where('investment_id', $p->id)->firstOrFail();
        $this->assertEquals(10.0, $alloc->plan_terms['profit_percentage']);
        $this->assertSame(30, $alloc->plan_terms['duration_days']);

        $legs = WalletLedgerEntry::where('allocation_id', $alloc->id)->get();
        $this->assertCount(2, $legs);
        $this->assertSame(1, $legs->pluck('entry_group')->unique()->count(), 'the wallet debit and the principal allocation are linked by one group');
        $this->assertEqualsCanonicalizing(['wallet_allocation_debit', 'allocated_investment_principal'], $legs->pluck('classification')->all());
        $this->assertSame(1000.0, round($this->balance($u) + (float) $p->amount, 2), 'cash + active principal still equals what was deposited');
    }

    public function test_the_same_cash_cannot_be_allocated_twice(): void
    {
        $u = $this->cash($this->investor(), 1000);
        $plan = $this->plan();

        $this->investFromWallet($u, $plan, 1000)->assertSuccessful();
        $this->investFromWallet($u, $plan, 1000)->assertStatus(422);

        $this->assertSame(1, InvestmentAccount::where('user_id', $u->id)->count());
        $this->assertSame(0.0, $this->balance($u));
    }

    public function test_an_allocation_with_insufficient_cash_writes_nothing(): void
    {
        $u = $this->cash($this->investor(), 100);
        $this->investFromWallet($u, $this->plan(), 500)->assertStatus(422);

        $this->assertSame(100.0, $this->balance($u));
        $this->assertSame(0, InvestmentAccount::where('user_id', $u->id)->count());
        $this->assertSame(0, InvestmentAllocation::count());
        $this->assertSame(0, WalletLedgerEntry::where('classification', 'wallet_allocation_debit')->count());
    }

    public function test_an_allocation_below_the_plan_minimum_leaves_the_money_where_it_was(): void
    {
        $u = $this->cash($this->investor(), 1000);
        $this->investFromWallet($u, $this->plan(100), 50)->assertStatus(422);

        $this->assertSame(1000.0, $this->balance($u));
        $this->assertSame(0, InvestmentAccount::where('user_id', $u->id)->count());
    }

    public function test_a_newly_allocated_position_counts_only_after_verification_and_only_once(): void
    {
        $u = $this->cash($this->investor(), 800);
        $this->investFromWallet($u, $this->plan(), 500)->assertSuccessful();
        $p = $this->latestPosition($u);

        $this->assertEquals(0.0, $this->facts($u)['current_principal'], 'unverified: not counted yet');
        $this->verifyUsd($p);
        $this->assertEquals(500.0, $this->facts($u)['current_principal']);
        $this->verifyUsd($p->fresh());                                     // a second verification must not double count
        $this->assertEquals(500.0, $this->facts($u)['current_principal']);
    }

    // ── 2. Principal withdrawals ────────────────────────────────────────────────────────────────────────

    public function test_a_pending_withdrawal_reduces_no_principal_even_when_classified(): void
    {
        $u = $this->cash($this->investor(), 500);
        $pos = $this->invest($u, 1000, 30, 'active');
        $w = $this->withdrawal($u, 100);

        $this->classify($w, [['component' => 'principal', 'amount' => 100, 'investment_id' => $pos->id]])->assertCreated();

        $this->assertEquals(1000.0, $this->facts($u)['current_principal']);
        $this->assertSame(0, $this->movements($pos)->count());
    }

    public function test_an_approved_principal_withdrawal_reduces_the_position_by_the_principal_component_only(): void
    {
        $u = $this->cash($this->investor(), 500);
        $pos = $this->invest($u, 1000, 30, 'active');
        $w = $this->withdrawal($u, 300);                                    // 200 principal + 100 profit

        $this->classify($w, [
            ['component' => 'principal', 'amount' => 200, 'investment_id' => $pos->id],
            ['component' => 'profit', 'amount' => 100, 'verification_note' => 'Profit credited at the last settlement and available for withdrawal'],
        ])->assertCreated();
        $this->approveWithdrawal($w)->assertSuccessful();

        $m = $this->movements($pos)->firstOrFail();
        $this->assertSame('withdrawal_principal', $m->kind);
        $this->assertEquals(200.00, $m->amount, 'reduced by the PRINCIPAL component, not by the 300 total');
        $this->assertEquals(800.0, $this->facts($u)['current_principal']);
        $this->assertSame(200.0, $this->balance($u));                       // the existing wallet debit (500 - 300) is unchanged
    }

    public function test_a_profit_only_withdrawal_reduces_no_principal(): void
    {
        $u = $this->cash($this->investor(), 500);
        $pos = $this->invest($u, 1000, 30, 'active');
        $w = $this->withdrawal($u, 100);

        $this->classify($w, [['component' => 'profit', 'amount' => 100, 'verification_note' => 'Verified profit available for withdrawal under the plan terms']])->assertCreated();
        $this->approveWithdrawal($w)->assertSuccessful();

        $this->assertSame(0, $this->movements($pos)->count());
        $this->assertEquals(1000.0, $this->facts($u)['current_principal']);
    }

    public function test_a_rejected_withdrawal_reduces_no_principal_and_cannot_be_approved_afterwards(): void
    {
        $u = $this->cash($this->investor(), 500);
        $pos = $this->invest($u, 1000, 30, 'active');
        $w = $this->withdrawal($u, 100);
        $this->classify($w, [['component' => 'principal', 'amount' => 100, 'investment_id' => $pos->id]])->assertCreated();

        $w->forceFill(['status' => 'rejected'])->save();
        $this->assertFalse($this->approveWithdrawal($w->fresh())->isSuccessful());

        $this->assertSame(0, $this->movements($pos)->count());
        $this->assertEquals(1000.0, $this->facts($u)['current_principal']);
        $this->assertSame(500.0, $this->balance($u));
    }

    public function test_the_same_withdrawal_cannot_deduct_principal_twice(): void
    {
        $u = $this->cash($this->investor(), 500);
        $pos = $this->invest($u, 1000, 30, 'active');
        $w = $this->withdrawal($u, 200);
        $this->classify($w, [['component' => 'principal', 'amount' => 200, 'investment_id' => $pos->id]])->assertCreated();

        $this->approveWithdrawal($w)->assertSuccessful();
        $this->assertFalse($this->approveWithdrawal($w->fresh())->isSuccessful(), 'a second approval must not run');

        $this->assertSame(1, $this->movements($pos)->count());
        $this->assertEquals(800.0, $this->facts($u)['current_principal']);
    }

    public function test_a_separate_redemption_for_the_same_withdrawal_cannot_double_deduct(): void
    {
        $u = $this->cash($this->investor(), 500);
        $pos = $this->invest($u, 1000, 30, 'active');
        $w = $this->withdrawal($u, 200);
        $this->classify($w, [['component' => 'principal', 'amount' => 200, 'investment_id' => $pos->id]])->assertCreated();

        // A principal adjustment that names the same withdrawal and position is refused: the reduction is already carried by the withdrawal.
        $this->controls()->postJson("/api/financial/positions/{$pos->id}/external-payout", [
            'amount' => 200, 'idempotency_key' => 'dup-key-0001', 'reference' => 'PAY-1', 'evidence' => 'Same principal paid through the withdrawal', 'withdrawal_id' => $w->id,
        ])->assertStatus(422);
        $this->assertSame(0, $this->movements($pos)->count());
    }

    public function test_a_withdrawal_that_was_already_redeemed_separately_aborts_its_approval_atomically(): void
    {
        $u = $this->cash($this->investor(), 500);
        $pos = $this->invest($u, 1000, 30, 'active');
        $w = $this->withdrawal($u, 200);

        // Redemption recorded FIRST (names the withdrawal), classification afterwards: the approval must then refuse and change nothing.
        $this->controls()->postJson("/api/financial/positions/{$pos->id}/external-payout", [
            'amount' => 200, 'idempotency_key' => 'dup-key-0002', 'reference' => 'PAY-2', 'evidence' => 'Principal paid externally against this request', 'withdrawal_id' => $w->id,
        ])->assertStatus(201);
        $this->classify($w, [['component' => 'principal', 'amount' => 200, 'investment_id' => $pos->id]])->assertCreated();

        $this->approveWithdrawal($w)->assertStatus(422);

        $this->assertSame('pending', $w->fresh()->status);
        $this->assertSame(500.0, $this->balance($u), 'the wallet was not debited');
        $this->assertSame(1, $this->movements($pos)->count(), 'only the external payout was recorded');
    }

    public function test_classification_rules(): void
    {
        $u = $this->cash($this->investor(), 900);
        $pos = $this->invest($u, 1000, 30, 'active');
        $w = $this->withdrawal($u, 300);

        $this->classify($w, [['component' => 'principal', 'amount' => 100, 'investment_id' => $pos->id]])->assertStatus(422);                          // does not add up
        $this->classify($w, [['component' => 'principal', 'amount' => 300]])->assertStatus(422);                                                        // account-level: no position named
        $this->classify($w, [['component' => 'principal', 'amount' => 300, 'investment_id' => 999999]])->assertStatus(422);                             // not this investor's position
        $this->classify($w, [['component' => 'profit', 'amount' => 300]])->assertStatus(422);                                                           // profit needs its verification
        $big = $this->withdrawal($u, 1500);
        $this->classify($big, [['component' => 'principal', 'amount' => 1500, 'investment_id' => $pos->id]])->assertStatus(422);                        // more than remains

        $this->classify($w, [['component' => 'principal', 'amount' => 300, 'investment_id' => $pos->id]])->assertCreated();
        $this->classify($w, [['component' => 'principal', 'amount' => 300, 'investment_id' => $pos->id]])->assertStatus(422);                           // already classified
        $this->assertFalse((bool) config('financial.account_level_redemption_fallback'), 'the oldest-first fallback is not approved and not implemented');
    }

    // ── principal release, return to wallet, external payout ────────────────────────────────────────────

    public function test_cycle_completion_alone_credits_only_profit_and_releases_no_principal(): void
    {
        $u = $this->cash($this->investor(), 0);
        $p = InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $this->plan(), 'amount' => 1000, 'profit_percentage' => 10, 'expected_profit' => 100,
            'total_return' => 1100, 'status' => 'active', 'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDay()->toDateString()]);

        $this->completeViaSettlement($p);

        $this->assertSame(100.0, $this->balance($u), 'profit only: no principal credit and no payout on completion');
        $this->assertSame(0, $this->movements($p)->count());
        $this->assertSame(1, WalletLedgerEntry::where('classification', 'profit_credit')->where('investment_id', $p->id)->count());
    }

    public function test_principal_can_only_be_released_for_a_completed_position_and_only_once(): void
    {
        $u = $this->cash($this->investor(), 0);
        $active = $this->invest($u, 500, 30, 'active');
        $this->controls()->postJson("/api/financial/positions/{$active->id}/release", ['note' => 'Trying to release a running position'])->assertStatus(422);

        $done = $this->cycle($u, 700);
        $first = $this->controls()->postJson("/api/financial/positions/{$done->id}/release", ['note' => 'Cycle completed; principal eligible for settlement'])->assertCreated()->json('id');
        $again = $this->controls()->postJson("/api/financial/positions/{$done->id}/release", ['note' => 'Cycle completed; principal eligible for settlement'])->assertCreated()->json('id');
        $this->assertSame($first, $again, 'idempotent');
        $this->assertSame(0, $this->movements($done)->whereIn('kind', ['return_to_wallet', 'external_payout'])->count(), 'release credits nothing');
    }

    public function test_principal_returned_to_the_wallet_is_a_linked_idempotent_transaction_that_reduces_the_position(): void
    {
        $u = $this->cash($this->investor(), 0);
        $done = $this->cycle($u, 1000);
        $this->controls()->postJson("/api/financial/positions/{$done->id}/release", ['note' => 'Cycle completed; principal eligible for settlement'])->assertCreated();
        $payload = ['amount' => 600, 'idempotency_key' => 'return-key-001', 'reference' => 'RET-1', 'evidence' => 'Ledger entry verified against the released principal'];

        $m = $this->controls()->postJson("/api/financial/positions/{$done->id}/return-to-wallet", $payload)->assertCreated()->json();
        $this->controls()->postJson("/api/financial/positions/{$done->id}/return-to-wallet", $payload)->assertCreated();   // a retry

        $this->assertSame(600.0, $this->balance($u), 'credited once, not twice');
        $this->assertSame(1, $this->movements($done)->where('kind', 'return_to_wallet')->count());
        $legs = WalletLedgerEntry::where('principal_movement_id', $m['id'])->get();
        $this->assertEqualsCanonicalizing(['principal_returned_to_wallet', 'allocated_investment_principal'], $legs->pluck('classification')->all());
        $this->assertSame(1, $legs->pluck('entry_group')->unique()->count());
        $this->assertSame(1, BalanceAdjustment::where('user_id', $u->id)->where('type', 'add')->count());
        $this->assertSame(400.0, app(\App\Services\Ledger\PrincipalService::class)->remaining($done->fresh()));
    }

    public function test_a_completed_position_needs_its_principal_released_before_it_can_be_returned(): void
    {
        $u = $this->cash($this->investor(), 0);
        $done = $this->cycle($u, 1000);
        $this->controls()->postJson("/api/financial/positions/{$done->id}/return-to-wallet",
            ['amount' => 100, 'idempotency_key' => 'return-key-002', 'reference' => 'RET-2', 'evidence' => 'Attempt before release'])->assertStatus(422);
        $this->assertSame(0.0, $this->balance($u));
    }

    public function test_a_redemption_cannot_exceed_the_remaining_principal_or_use_a_cancelled_position(): void
    {
        $u = $this->cash($this->investor(), 0);
        $pos = $this->invest($u, 400, 30, 'active');
        $this->controls()->postJson("/api/financial/positions/{$pos->id}/return-to-wallet",
            ['amount' => 401, 'idempotency_key' => 'return-key-003', 'reference' => 'RET-3', 'evidence' => 'More than the position holds'])->assertStatus(422);

        $dead = $this->invest($u, 400, 30, 'cancelled', false);
        $this->controls()->postJson("/api/financial/positions/{$dead->id}/return-to-wallet",
            ['amount' => 100, 'idempotency_key' => 'return-key-004', 'reference' => 'RET-4', 'evidence' => 'A cancelled position has no principal'])->assertStatus(422);
        $this->assertSame(0.0, $this->balance($u));
    }

    public function test_an_external_payout_reduces_the_position_without_moving_the_wallet(): void
    {
        $u = $this->cash($this->investor(), 50);
        $pos = $this->invest($u, 1000, 30, 'active');

        $this->controls()->postJson("/api/financial/positions/{$pos->id}/external-payout",
            ['amount' => 250, 'idempotency_key' => 'payout-key-001', 'reference' => 'BANK-77', 'evidence' => 'Bank transfer confirmed against the statement'])->assertCreated();

        $this->assertSame(50.0, $this->balance($u), 'no wallet movement');
        $this->assertEquals(750.0, $this->facts($u)['current_principal']);
        $this->assertSame(1, WalletLedgerEntry::where('classification', 'principal_paid_externally')->where('investment_id', $pos->id)->count());

        $this->controls()->postJson("/api/financial/positions/{$pos->id}/external-payout",
            ['amount' => 100, 'idempotency_key' => 'payout-key-002', 'reference' => 'X', 'evidence' => 'short'])->assertStatus(422);   // reference + evidence required
    }

    public function test_a_full_redemption_removes_the_principal_from_current_eligibility(): void
    {
        $u = $this->cash($this->investor(), 0);
        $pos = $this->invest($u, 1000, 30, 'active');
        $this->assertEquals(1000.0, $this->facts($u)['current_principal']);

        $this->controls()->postJson("/api/financial/positions/{$pos->id}/external-payout",
            ['amount' => 1000, 'idempotency_key' => 'payout-key-003', 'reference' => 'BANK-78', 'evidence' => 'Full principal paid out and verified'])->assertCreated();

        $this->assertEquals(0.0, $this->facts($u)['current_principal']);
    }

    public function test_a_correction_needs_an_approval_reference_and_evidence_and_is_bounded(): void
    {
        $u = $this->cash($this->investor(), 0);
        $pos = $this->invest($u, 1000, 30, 'active');

        $this->controls()->postJson("/api/financial/positions/{$pos->id}/correction", ['signed_amount' => 100, 'idempotency_key' => 'corr-key-0001', 'approval_reference' => 'x', 'evidence' => 'short'])->assertStatus(422);
        $this->controls()->postJson("/api/financial/positions/{$pos->id}/correction", ['signed_amount' => 100, 'idempotency_key' => 'corr-key-0002', 'approval_reference' => 'FIN-APP-2026-9', 'evidence' => 'Redemption recorded late; Financial officer approved'])->assertCreated();
        $this->assertEquals(900.0, $this->facts($u)['current_principal']);

        $this->controls()->postJson("/api/financial/positions/{$pos->id}/correction", ['signed_amount' => -500, 'idempotency_key' => 'corr-key-0003', 'approval_reference' => 'FIN-APP-2026-9', 'evidence' => 'Attempts to restore more than was reduced'])->assertStatus(422);
        $this->controls()->postJson("/api/financial/positions/{$pos->id}/correction", ['signed_amount' => -100, 'idempotency_key' => 'corr-key-0004', 'approval_reference' => 'FIN-APP-2026-9', 'evidence' => 'Reversal of the late redemption entry'])->assertCreated();
        $this->assertEquals(1000.0, $this->facts($u)['current_principal']);
        $this->assertTrue(RankBenefitAudit::where('subject_type', 'principal')->where('action', 'principal_correction')->exists());
    }

    // ── 3. Reinvestment and completed cycles ────────────────────────────────────────────────────────────

    public function test_reinvestment_never_duplicates_principal_or_creates_a_cycle(): void
    {
        $u = $this->cash($this->investor(), 0);
        $done = $this->cycle($u, 1000);                                                  // verified completed cycle; principal no longer active
        $this->assertSame(1, $this->facts($u)['cycles']);
        $this->assertEquals(0.0, $this->facts($u)['current_principal']);

        $this->controls()->postJson("/api/financial/positions/{$done->id}/release", ['note' => 'Cycle completed; principal eligible for settlement'])->assertCreated();
        $this->controls()->postJson("/api/financial/positions/{$done->id}/return-to-wallet",
            ['amount' => 1000, 'idempotency_key' => 'return-key-010', 'reference' => 'RET-10', 'evidence' => 'Returned principal verified in the ledger'])->assertCreated();
        $this->assertSame(1000.0, $this->balance($u));
        $this->assertEquals(0.0, $this->facts($u)['current_principal'], 'principal sitting in the wallet does not count');

        $this->investFromWallet($u->fresh(), $this->plan(), 1000)->assertSuccessful();             // reinvest: a NEW, distinct position
        $new = $this->latestPosition($u);
        $this->assertNotSame($done->id, $new->id);
        $this->assertEquals(0.0, $this->facts($u)['current_principal'], 'counts only after verification');

        $this->verifyUsd($new);
        $f = $this->facts($u);
        $this->assertEquals(1000.0, $f['current_principal'], 'the same 1000 is counted once, in the new position only');
        $this->assertSame(1, $f['cycles'], 'reinvestment does not create another completed cycle');
        $this->assertSame(0.0, $this->balance($u));
    }

    public function test_each_completed_cycle_has_a_unique_reference_linked_to_its_position(): void
    {
        $u = $this->investor();
        $a = $this->cycle($u, 100);
        $b = $this->cycle($u, 200);
        $f = $this->facts($u, true);

        $this->assertSame(2, $f['cycles']);
        $this->assertCount(2, array_unique($f['cycle_references']));
        $this->assertContains(sprintf('CYC-%06d', $a->id), $f['cycle_references']);
        $this->assertContains(sprintf('CYC-%06d', $b->id), $f['cycle_references']);
        $row = collect($f['breakdown'])->firstWhere('investment_id', $a->id);
        $this->assertSame(sprintf('CYC-%06d', $a->id), $row['cycle_reference']);
    }

    public function test_a_completed_cycle_keeps_counting_after_its_principal_has_left(): void
    {
        $u = $this->investor();
        $done = $this->cycle($u, 500);
        $this->controls()->postJson("/api/financial/positions/{$done->id}/release", ['note' => 'Cycle completed; principal eligible for settlement'])->assertCreated();
        $this->controls()->postJson("/api/financial/positions/{$done->id}/external-payout",
            ['amount' => 500, 'idempotency_key' => 'payout-key-020', 'reference' => 'BANK-90', 'evidence' => 'Principal paid out and verified'])->assertCreated();

        $f = $this->facts($u);
        $this->assertSame(1, $f['cycles']);
        $this->assertEquals(0.0, $f['current_principal']);
    }

    // ── flags ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_disputed_position_is_excluded_from_principal_and_cycles_until_cleared_and_the_investor_is_reassessed(): void
    {
        $u = $this->investor();
        $active = $this->invest($u, 1000, 30, 'active');
        $this->cycle($u, 100);
        $this->assertEquals(1000.0, $this->facts($u)['current_principal']);
        $this->assertSame(1, $this->facts($u)['cycles']);

        Bus::fake();
        $this->controls()->postJson("/api/financial/positions/{$active->id}/flag", ['flag_type' => 'disputed', 'reason' => 'Investor disputes the allocation'])->assertCreated();
        Bus::assertDispatched(EvaluateInvestorRankJob::class, fn ($job) => $job->userId === $u->id);

        $f = $this->facts($u);
        $this->assertEquals(0.0, $f['current_principal']);
        $this->assertContains($active->id, $f['blocked']);
        $this->assertSame(1, $f['cycles'], 'only the flagged position is excluded');

        $this->controls()->postJson("/api/financial/positions/{$active->id}/unflag", ['reason' => 'Dispute resolved in the investor favour'])->assertSuccessful();
        $this->assertEquals(1000.0, $this->facts($u)['current_principal']);
        $this->assertSame(2, InvestmentFlag::where('investment_id', $active->id)->count(), 'history kept: set + cleared');
        $this->assertTrue(RankBenefitAudit::where('subject_type', 'flag')->where('action', 'flag_set')->exists());
    }

    public function test_a_flag_needs_a_reason_and_an_existing_flag_to_clear(): void
    {
        $u = $this->investor();
        $p = $this->invest($u, 100, 30, 'active');
        $this->controls()->postJson("/api/financial/positions/{$p->id}/flag", ['flag_type' => 'reversed', 'reason' => 'no'])->assertStatus(422);
        $this->controls()->postJson("/api/financial/positions/{$p->id}/unflag", ['reason' => 'Nothing to clear here'])->assertStatus(422);
        $this->assertSame(0, InvestmentFlag::count());
    }

    // ── authorization ───────────────────────────────────────────────────────────────────────────────────

    public function test_an_investor_and_a_guest_cannot_use_the_financial_controls(): void
    {
        $u = $this->investor();
        $p = $this->invest($u, 300, 30, 'active');
        $w = $this->withdrawal($u, 10);

        foreach ([['flag', ['flag_type' => 'disputed', 'reason' => 'trying to flag']], ['external-payout', ['amount' => 1, 'idempotency_key' => 'k-key-0001', 'reference' => 'ref', 'evidence' => 'self service attempt']]] as [$action, $body]) {
            $this->actingAs($u, 'sanctum')->postJson("/api/financial/positions/{$p->id}/$action", $body)->assertStatus(403);
        }
        $this->actingAs($u, 'sanctum')->postJson("/api/financial/withdrawals/{$w->id}/classify", ['components' => [['component' => 'profit', 'amount' => 10, 'verification_note' => 'self classification']]])->assertStatus(403);
        $this->actingAs($u, 'sanctum')->getJson("/api/financial/ranks/investors/{$u->id}/positions")->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/financial/positions/{$p->id}/flag", ['flag_type' => 'disputed', 'reason' => 'guest attempt'])->assertStatus(401);

        $this->assertSame(0, PrincipalMovement::count());
        $this->assertSame(0, InvestmentFlag::count());
    }

    public function test_the_position_view_shows_remaining_principal_status_flags_movements_and_unclassified_withdrawals(): void
    {
        $u = $this->cash($this->investor(), 100);
        $pos = $this->invest($u, 1000, 30, 'active');
        $this->controls()->postJson("/api/financial/positions/{$pos->id}/external-payout",
            ['amount' => 250, 'idempotency_key' => 'payout-key-030', 'reference' => 'BANK-31', 'evidence' => 'Bank transfer confirmed against the statement'])->assertCreated();
        $this->withdrawal($u, 50);

        $r = $this->controls()->getJson("/api/financial/ranks/investors/{$u->id}/positions")->assertOk()->json();

        $this->assertEquals(750.0, $r['summary']['current_eligible_principal']);
        $this->assertEquals(750.0, $r['positions'][0]['remaining_principal']);
        $this->assertSame('active', $r['positions'][0]['derived_status']);
        $this->assertSame('external_payout', $r['positions'][0]['movements'][0]['kind']);
        $this->assertFalse($r['pending_withdrawals'][0]['classified']);
        $this->assertNotEmpty($r['summary']['calculated_at']);
    }
}
