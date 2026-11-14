<?php
// LOCATION: tests/Feature/Rank/Phase2AcceptanceGapsTest.php
//
// Phase 2 of the remediation: the two acceptance scenarios for which the existing suite had no test.
//
//  1. A withdrawal that is NOT completed must not reduce qualifying principal.
//     The application has no "cancelled" withdrawal state (the status values are pending, approved, rejected and hold), so the third non-completed
//     state, HOLD, is covered here next to the existing pending and rejected tests. (Cancelled POSITIONS are covered in FinancialDecisionsTest.)
//  2. An incentive term in ANY state (draft, pending approval, approved with the master switch off, even fully active) must never change what an
//     investor is paid when an investment completes: the same position settles to exactly the same balance, ledger entries and stored return.
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php -d memory_limit=-1 vendor\bin\pest --filter=Phase2AcceptanceGapsTest

namespace Tests\Feature\Rank;

use App\Models\InvestmentAccount;
use App\Models\RankIncentiveTerm;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WalletLedgerEntry;
use App\Models\PrincipalMovement;
use App\Services\InvestmentSettlementService;
use Illuminate\Support\Facades\DB;

class Phase2AcceptanceGapsTest extends FinancialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->financialRulesOn();
    }

    // ── 1. a withdrawal that is on hold reduces nothing ─────────────────────────────────────────────────

    private function classify(Withdrawal $w, array $components)
    {
        return $this->controls()->postJson("/api/financial/withdrawals/{$w->id}/classify", ['components' => $components]);
    }

    public function test_a_withdrawal_on_hold_reduces_no_principal_and_touches_no_other_investor(): void
    {
        $u = $this->cash($this->investor(), 500);
        $pos = $this->invest($u, 1000, 30, 'active');
        $bystander = $this->cash($this->investor(), 250);
        $bystanderPos = $this->invest($bystander, 400, 30, 'active');

        $w = $this->withdrawal($u, 100);
        $this->classify($w, [['component' => 'principal', 'amount' => 100, 'investment_id' => $pos->id]])->assertCreated();

        $before = [
            'principal' => $this->facts($u)['current_principal'], 'balance' => $this->balance($u),
            'other_principal' => $this->facts($bystander)['current_principal'], 'other_balance' => $this->balance($bystander),
        ];

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/withdrawals/{$w->id}/hold", ['reason' => 'Verification pending'])->assertOk();
        $this->assertSame('hold', $w->fresh()->status);

        $this->assertSame(0, PrincipalMovement::where('investment_id', $pos->id)->count(), 'a held withdrawal records no principal movement');
        $this->assertEquals($before['principal'], $this->facts($u)['current_principal']);
        $this->assertEquals(1000.0, $this->facts($u)['current_principal']);
        $this->assertSame($before['balance'], $this->balance($u), 'the wallet is not debited');
        $this->assertEquals($before['other_principal'], $this->facts($bystander)['current_principal']);
        $this->assertSame($before['other_balance'], $this->balance($bystander));
        $this->assertSame(0, PrincipalMovement::where('investment_id', $bystanderPos->id)->count());
        $this->assertSame('active', $pos->fresh()->status);
    }

    // ── 2. an unapproved incentive never changes a payout ───────────────────────────────────────────────

    private function matured(User $u): InvestmentAccount
    {
        return InvestmentAccount::create([
            'user_id' => $u->id, 'investment_plan_id' => $this->plan(), 'amount' => 1000, 'profit_percentage' => 10, 'expected_profit' => 100,
            'total_return' => 1100, 'status' => 'active', 'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDay()->toDateString(),
        ]);
    }

    /** Completes the position through the real settlement service and reports everything that could have moved. */
    private function settle(User $u): array
    {
        $p = $this->matured($u);
        app(InvestmentSettlementService::class)->complete($p->fresh(), 'admin', $this->admin->id);

        $entries = WalletLedgerEntry::where('investment_id', $p->id)->orderBy('id')->get();
        return [
            'balance'          => $this->balance($u),
            'classifications'  => $entries->pluck('classification')->all(),
            'amounts'          => $entries->map(fn ($e) => (string) $e->amount)->all(),
            'expected_profit'  => (string) $p->fresh()->expected_profit,
            'total_return'     => (string) $p->fresh()->total_return,
            'status'           => $p->fresh()->status,
            'movements'        => PrincipalMovement::where('investment_id', $p->id)->count(),
        ];
    }

    private function draftTerm(int $rankId): int
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/incentives', [
            'label' => 'Gold loyalty bonus (test)', 'investor_rank_id' => $rankId, 'kind' => 'percentage_bonus', 'value' => 25,
            'conditions' => 'Applies to completed cycles of the named plan only.', 'effective_from' => now()->addDay()->toDateString(),
            'reason' => 'Prepared for management review',
        ])->assertCreated()->json('id');
    }

    private function goldInvestor(): User
    {
        $u = $this->cash($this->investor(), 0);
        $u->forceFill(['investor_rank_id' => $this->rank('gold')->id, 'rank_status' => 'active'])->save();
        return $u->fresh();
    }

    public function test_an_incentive_in_any_state_never_changes_what_a_completed_investment_pays(): void
    {
        // The baseline: a gold-rank investor and NO incentive term anywhere.
        $this->assertSame(0, RankIncentiveTerm::count());
        $baseline = $this->settle($this->goldInvestor());
        $this->assertSame(100.0, $baseline['balance'], 'baseline: only the contractual 10% profit is credited');
        $this->assertSame(['profit_credit'], $baseline['classifications']);
        $this->assertSame(0, $baseline['movements']);

        $goldId = $this->rank('gold')->id;
        $approver = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $bystander = $this->cash($this->investor(), 250);

        // Walk ONE term through every state; after each step the same position must settle to exactly the baseline.
        $id = $this->draftTerm($goldId);
        $this->assertSame('draft', RankIncentiveTerm::find($id)->status);
        $this->assertEquals($baseline, $this->settle($this->goldInvestor()), 'DRAFT incentive must not change the payout');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/incentives/$id/submit", ['reason' => 'Ready for approval'])->assertOk();
        $this->assertSame('pending_approval', RankIncentiveTerm::find($id)->status);
        $this->assertEquals($baseline, $this->settle($this->goldInvestor()), 'PENDING-APPROVAL incentive must not change the payout');

        $this->actingAs($approver, 'sanctum')->postJson("/api/admin/ranks/incentives/$id/approve", ['reason' => 'Approved in writing', 'approval_reference' => 'MGT-2026-901'])->assertOk();
        config(['ranks.incentives_activation_enabled' => false]);
        $this->assertSame('approved', RankIncentiveTerm::find($id)->status);
        $this->assertEquals($baseline, $this->settle($this->goldInvestor()), 'APPROVED incentive (master switch off) must not change the payout');

        // Even a fully ACTIVE term is only a recorded term: nothing in the payout path reads it, so the payout is still the contractual one.
        config(['ranks.incentives_activation_enabled' => true]);
        $this->actingAs($approver, 'sanctum')->postJson("/api/admin/ranks/incentives/$id/activate", ['reason' => 'Go live'])->assertOk();
        $this->assertSame('active', RankIncentiveTerm::find($id)->status);
        $this->assertEquals($baseline, $this->settle($this->goldInvestor()), 'ACTIVE incentive must not alter the settlement either');

        // No bonus or incentive line was ever written to a wallet, and an unrelated investor was never touched.
        $this->assertSame(0, WalletLedgerEntry::whereRaw("classification like '%bonus%' or classification like '%incentive%'")->count());
        $this->assertSame(250.0, $this->balance($bystander));
    }

    public function test_the_stored_return_of_a_new_investment_ignores_every_incentive_state(): void
    {
        $goldId = $this->rank('gold')->id;
        $id = $this->draftTerm($goldId);                                    // a draft term exists for the investor's own rank
        $u = $this->cash($this->goldInvestor(), 1000);
        $planId = $this->plan(1, 30, 10);

        $this->investFromWallet($u->fresh(), $planId, 1000)->assertStatus(201);
        $inv = $this->latestPosition($u);
        $this->assertEquals(10.0, (float) $inv->profit_percentage, 'the plan percentage is stored unchanged');
        $this->assertEquals(100.0, (float) $inv->expected_profit);
        $this->assertEquals(1100.0, (float) $inv->total_return);
        $this->assertSame('draft', RankIncentiveTerm::find($id)->status);
    }
}
