<?php
// LOCATION: tests/Feature/Task5/WithdrawalFinancialSecurityTest.php
// Financial security review of InvestorWithdrawalController::store() / AdminWithdrawalController approve+reject (single-database behaviour).
// WRITTEN, NOT EXECUTED (no PHP runtime). Real concurrency is covered by tests/MySql/MySqlWithdrawalRoundsTest.php (MySQL only).

namespace Tests\Feature\Task5;

use App\Models\BalanceAdjustment;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Models\Withdrawal;
use App\Models\WithdrawalFeeEvent;
use App\Models\WithdrawalFeePolicy;

class WithdrawalFinancialSecurityTest extends Task5TestCase
{
    private const BASE = '/api/investor-investment/investor/withdrawals';

    protected function setUp(): void
    {
        parent::setUp();
        config(['financial.ledger_enabled' => true]);   // the wallet ledger is OFF by default (FINANCIAL_LEDGER_ENABLED); these tests assert its rows
    }

    private function body(array $o = []): array
    {
        return $o + ['amount' => 100, 'method' => 'usdt', 'wallet_address' => '0xabc', 'withdrawal_pin' => '1234'];
    }

    private function submit(User $inv, array $o = [], array $headers = [])
    {
        return $this->fin($inv)->postJson(self::BASE, $this->body($o), $headers);
    }

    private function money(string $v): string { return number_format((float) $v, 2, '.', ''); }

    public function test_an_investor_cannot_withdraw_more_than_the_balance_and_nothing_is_created(): void
    {
        $inv = $this->withPin($this->investor(), '1234', '50.00');
        $this->submit($inv, ['amount' => 50.01])->assertStatus(422);
        $this->submit($inv, ['amount' => 1000])->assertStatus(422);
        $this->assertSame(0, Withdrawal::count());
        $this->assertSame('50.00', $this->money($inv->fresh()->balance));
        $this->submit($inv, ['amount' => 50])->assertCreated();   // exactly the balance is allowed
    }

    public function test_invalid_amounts_are_refused(): void
    {
        $inv = $this->withPin($this->investor(), '1234', '500.00');
        foreach ([0, -10, 9.99, 'abc', '1e2x', '10.001'] as $bad) {
            $this->submit($inv, ['amount' => $bad])->assertStatus(422);
        }
        $this->assertSame(0, Withdrawal::count());
    }

    public function test_submission_never_debits_and_the_ledger_records_a_pending_entry_that_moves_no_money(): void
    {
        $inv = $this->withPin($this->investor(), '1234', '500.00');
        $id = $this->submit($inv)->assertCreated()->json('withdrawal.id');
        $this->assertSame('500.00', $this->money($inv->fresh()->balance));
        $this->assertSame(0, BalanceAdjustment::where('user_id', $inv->id)->count());
        $e = WalletLedgerEntry::where('entry_key', "withdrawal:{$id}:pending")->firstOrFail();
        $this->assertSame('info', $e->direction);
    }

    public function test_a_second_pending_withdrawal_is_refused_and_a_repeated_idempotency_key_returns_the_first(): void
    {
        $inv = $this->withPin($this->investor(), '1234', '1000.00');
        $h = ['Idempotency-Key' => 'abc-0001-key'];
        $first = $this->submit($inv, [], $h)->assertCreated();
        $again = $this->submit($inv, [], $h)->assertOk();
        $this->assertTrue($again->json('duplicate'));
        $this->assertSame($first->json('withdrawal.id'), $again->json('withdrawal.id'));
        $this->submit($inv, [], ['Idempotency-Key' => 'different-key-02'])->assertStatus(422);   // one pending at a time
        $this->assertSame(1, Withdrawal::count());
    }

    public function test_the_withdrawal_always_belongs_to_the_authenticated_investor_whatever_the_request_says(): void
    {
        $a = $this->withPin($this->investor(), '1234', '500.00');
        $b = $this->withPin($this->investor(), '1234', '500.00');
        $id = $this->submit($a, ['user_id' => $b->id, 'status' => 'approved', 'fee_amount' => '0', 'gross_amount' => '1', 'net_amount' => '1', 'processed_by' => 1])->assertCreated()->json('withdrawal.id');
        $w = Withdrawal::findOrFail($id);
        $this->assertSame($a->id, $w->user_id);
        $this->assertSame('pending', $w->status);
        $this->assertSame('100.00', $this->money($w->gross_amount));
        $this->assertNull($w->processed_by);
    }

    public function test_with_no_fee_policy_behaviour_is_the_original_gross_equals_net_and_approval_debits_the_amount_once(): void
    {
        $this->assertSame(0, WithdrawalFeePolicy::where('is_active', true)->where('policy_approved', true)->count(), 'nothing approved by default');
        $inv = $this->withPin($this->investor(), '1234', '300.00');
        $id = $this->submit($inv)->assertCreated()->json('withdrawal.id');
        $w = Withdrawal::findOrFail($id);
        $this->assertSame(['100.00', '0.00', '100.00', 'none'], [$this->money($w->gross_amount), $this->money($w->fee_amount), $this->money($w->net_amount), $w->fee_status]);

        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$id}/approve")->assertOk();
        $this->assertSame('200.00', $this->money($inv->fresh()->balance));
        $row = BalanceAdjustment::where('user_id', $inv->id)->firstOrFail();
        $this->assertSame([300.0, 100.0, 200.0], [(float) $row->balance_before, (float) $row->amount, (float) $row->balance_after]);
        $this->assertSame(1, WalletLedgerEntry::where('entry_key', "withdrawal:{$id}:payout")->count());
        $this->assertSame(0, WithdrawalFeeEvent::count(), 'no fee, no fee events');
    }

    public function test_an_active_but_unapproved_or_future_or_inactive_policy_never_charges(): void
    {
        $base = ['fee_type' => 'fixed', 'fixed_amount' => '5.00', 'currency' => 'USD', 'reason' => 'test', 'configured_by_name' => 'test', 'created_at' => now()];
        foreach ([['is_active' => true, 'policy_approved' => false, 'effective_from' => now()->subMinute()],
                  ['is_active' => false, 'policy_approved' => true, 'effective_from' => now()->subMinute()],
                  ['is_active' => true, 'policy_approved' => true, 'effective_from' => now()->addDay()]] as $i => $p) {
            WithdrawalFeePolicy::query()->delete();
            WithdrawalFeePolicy::create($base + $p);
            $inv = $this->withPin($this->investor(), '1234', '500.00');
            $q = $this->fin($inv)->getJson(self::BASE . '/quote?amount=100')->assertOk()->json('quote');
            $this->assertSame(['100.00', '0.00', '100.00', false], [$q['gross'], $q['fee'], $q['net'], $q['fee_applies']], "policy variant {$i}");
            $id = $this->submit($inv)->assertCreated()->json('withdrawal.id');   // no confirmation needed when no fee applies
            $this->assertSame('0.00', $this->money(Withdrawal::find($id)->fee_amount), "policy variant {$i}");
            Withdrawal::query()->delete();
        }
    }

    public function test_an_approved_fee_is_disclosed_confirmed_frozen_and_collected_once_inside_the_gross_debit(): void
    {
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor(), '1234', '300.00');
        $this->submit($inv)->assertStatus(422)->assertJsonPath('code', 'FEE_CONFIRMATION_REQUIRED');
        $this->submit($inv, ['confirmed_fee' => '4.00', 'confirmed_net' => '96.00'])->assertStatus(422);   // not the server's figures
        $id = $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertCreated()->json('withdrawal.id');

        $this->approveFee('fixed', ['fixed_amount' => '50.00']);   // a LATER policy must not change the submitted withdrawal
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$id}/approve")->assertOk();
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$id}/approve")->assertStatus(422);

        $w = Withdrawal::findOrFail($id);
        $this->assertSame(['100.00', '5.00', '95.00', 'collected'], [$this->money($w->gross_amount), $this->money($w->fee_amount), $this->money($w->net_amount), $w->fee_status]);
        $this->assertSame('200.00', $this->money($inv->fresh()->balance), 'debit = gross once; the fee is not a second debit');
        $this->assertSame(1, BalanceAdjustment::where('user_id', $inv->id)->count());
        $this->assertSame(1, WithdrawalFeeEvent::where('withdrawal_id', $id)->where('event', 'collected')->count());
    }

    public function test_a_rejected_withdrawal_leaves_no_deduction_and_no_collected_fee(): void
    {
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor(), '1234', '300.00');
        $id = $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertCreated()->json('withdrawal.id');
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$id}/reject", ['reason' => 'test'])->assertOk();
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$id}/approve")->assertStatus(422);   // a rejected request cannot be approved afterwards

        $this->assertSame('300.00', $this->money($inv->fresh()->balance));
        $this->assertSame(0, BalanceAdjustment::where('user_id', $inv->id)->count());
        $this->assertSame(0, WalletLedgerEntry::where('entry_key', "withdrawal:{$id}:payout")->count());
        $this->assertSame('not_collected', Withdrawal::find($id)->fee_status);
        $this->assertSame(0, WithdrawalFeeEvent::where('withdrawal_id', $id)->where('event', 'collected')->count());
    }

    public function test_approval_is_refused_when_the_balance_fell_after_submission_and_nothing_goes_negative(): void
    {
        $inv = $this->withPin($this->investor(), '1234', '100.00');
        $id = $this->submit($inv)->assertCreated()->json('withdrawal.id');
        $inv->forceFill(['balance' => '40.00'])->save();   // e.g. an administrator deduction after the request was made
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$id}/approve")->assertStatus(422);
        $this->assertSame('40.00', $this->money($inv->fresh()->balance));
        $this->assertSame('pending', Withdrawal::find($id)->status);
        $this->assertSame(0, BalanceAdjustment::where('user_id', $inv->id)->count());
    }

    public function test_only_financial_or_admin_staff_can_approve_or_reject_and_registration_staff_cannot(): void
    {
        $inv = $this->withPin($this->investor(), '1234', '300.00');
        $id = $this->submit($inv)->assertCreated()->json('withdrawal.id');
        foreach (['registration_member', 'registration_lead', 'marvflow_member'] as $role) {
            $staff = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->fin($staff)->postJson("/api/financial/withdrawals/{$id}/approve")->assertStatus(403);
            $this->fin($staff)->postJson("/api/financial/withdrawals/{$id}/reject", ['reason' => 'x'])->assertStatus(403);
        }
        $this->fin($inv)->postJson("/api/financial/withdrawals/{$id}/approve")->assertStatus(403);   // the investor cannot approve their own
        $this->assertSame('pending', Withdrawal::find($id)->status);
        $this->assertSame('300.00', $this->money($inv->fresh()->balance));
    }
}
