<?php
// Task 5 - withdrawal fee configuration, disclosure, freezing, accounting and reconciliation.
// STATUS: written, NOT executed by the author (no PHP in the authoring environment) - see docs/task5/TEST_EXECUTION_REPORT.md.
namespace Tests\Feature\Task5;

use App\Models\Withdrawal;
use App\Models\WithdrawalFeeEvent;
use App\Models\WithdrawalFeePolicy;
use App\Services\Task5\WithdrawalFeeService;

class WithdrawalFeeTest extends Task5TestCase
{
    private const BASE = '/api/investor-investment/investor/withdrawals';

    private function body(array $o = []): array
    {
        return $o + ['amount' => 100, 'method' => 'usdt', 'wallet_address' => '0xabc', 'withdrawal_pin' => '1234'];
    }
    private function submit($inv, array $o = [], array $headers = [])
    {
        return $this->fin($inv)->postJson(self::BASE, $this->body($o), $headers);
    }

    public function test_default_state_charges_no_fee_and_existing_clients_keep_working(): void
    {
        $inv = $this->withPin($this->investor());
        $this->assertSame('none', WithdrawalFeePolicy::current()->fee_type);
        $res = $this->submit($inv)->assertCreated();
        $this->assertSame('100.00', $res->json('withdrawal.gross_amount'));
        $this->assertSame('0.00', $res->json('withdrawal.fee_amount'));
        $this->assertSame('100.00', $res->json('withdrawal.net_amount'));
        $this->assertNotEmpty($res->json('withdrawal.transaction_reference'));
    }

    public function test_quote_discloses_gross_fee_net_before_confirmation(): void
    {
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor());
        $this->fin($inv)->getJson(self::BASE . '/quote?amount=100')->assertOk()
            ->assertJsonPath('quote.gross', '100.00')->assertJsonPath('quote.fee', '5.00')->assertJsonPath('quote.net', '95.00')
            ->assertJsonPath('quote.currency', 'USD')->assertJsonPath('quote.fee_applies', true);
    }

    public function test_quote_rejects_zero_negative_and_invalid_amounts(): void
    {
        $inv = $this->investor();
        foreach (['0', '-5', 'abc', '10.999'] as $bad) $this->fin($inv)->getJson(self::BASE . '/quote?amount=' . $bad)->assertStatus(422);
    }

    public function test_submission_requires_the_investor_to_confirm_the_disclosed_fee_and_net(): void
    {
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor());
        $this->submit($inv)->assertStatus(422)->assertJsonPath('code', 'FEE_CONFIRMATION_REQUIRED')->assertJsonPath('quote.fee', '5.00');
        $this->submit($inv, ['confirmed_fee' => '0.00', 'confirmed_net' => '100.00'])->assertStatus(422)->assertJsonPath('code', 'FEE_CONFIRMATION_REQUIRED');
        $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '90.00'])->assertStatus(422);
        $this->assertSame(0, Withdrawal::count());
    }

    public function test_confirmed_submission_freezes_gross_fee_net_and_policy_and_does_not_touch_the_balance(): void
    {
        $policy = $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor());
        $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertCreated();
        $w = Withdrawal::first();
        $this->assertSame(['100.00', '5.00', '95.00', 'charged', $policy->id], [(string) $w->gross_amount, (string) $w->fee_amount, (string) $w->net_amount, $w->fee_status, $w->fee_policy_id]);
        $this->assertSame('100.00', (string) $w->amount);
        $this->assertSame('fixed', $w->fee_calculation['fee_type']);
        $this->assertSame('1000.00', number_format((float) $inv->fresh()->balance, 2, '.', ''), 'nothing is debited at submission');
        $this->assertSame(1, WithdrawalFeeEvent::where('withdrawal_id', $w->id)->where('event', 'charged')->count());
    }

    public function test_a_tampered_fee_in_the_request_is_ignored(): void
    {
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor());
        $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '95.00', 'fee_amount' => '0', 'net_amount' => '100', 'gross_amount' => '1', 'fee_status' => 'collected', 'status' => 'approved', 'transaction_reference' => 'X'])->assertCreated();
        $w = Withdrawal::first();
        $this->assertSame(['5.00', '95.00', '100.00', 'charged', 'pending'], [(string) $w->fee_amount, (string) $w->net_amount, (string) $w->gross_amount, $w->fee_status, $w->status]);
        $this->assertNotSame('X', $w->transaction_reference);
    }

    public function test_later_policy_change_does_not_alter_an_existing_withdrawal(): void
    {
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor());
        $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertCreated();
        $this->approveFee('percentage', ['percentage' => '10']);
        $w = Withdrawal::first();
        $this->assertSame('5.00', (string) $w->fee_amount);
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$w->id}/approve")->assertOk();
        $w = $w->fresh();
        $this->assertSame(['5.00', '95.00'], [(string) $w->fee_amount, (string) $w->net_amount]);
    }

    public function test_approval_debits_gross_once_collects_the_fee_once_and_a_second_approval_is_refused(): void
    {
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor());
        $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertCreated();
        $w = Withdrawal::first();
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$w->id}/approve")->assertOk();
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$w->id}/approve")->assertStatus(422);
        $this->assertSame('900.00', number_format((float) $inv->fresh()->balance, 2, '.', ''), 'gross debited exactly once (fee is part of it, not on top)');
        $this->assertSame('collected', $w->fresh()->fee_status);
        $this->assertSame(1, WithdrawalFeeEvent::where('withdrawal_id', $w->id)->where('event', 'collected')->count());
        $this->assertSame([], app(WithdrawalFeeService::class)->reconcile());
    }

    public function test_rejected_withdrawal_takes_no_fee_and_no_money(): void
    {
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor());
        $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertCreated();
        $w = Withdrawal::first();
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$w->id}/reject", ['reason' => 'test'])->assertOk();
        $this->assertSame('not_collected', $w->fresh()->fee_status);
        $this->assertSame('1000.00', number_format((float) $inv->fresh()->balance, 2, '.', ''));
        $this->assertSame(0, WithdrawalFeeEvent::where('event', 'collected')->count());
        $s = app(WithdrawalFeeService::class)->summary()['amounts'];
        $this->assertSame(['5.00', '0.00', '5.00'], [$s['fees_charged'], $s['fees_collected'], $s['fees_not_collected']], 'charged and collected are reported separately');
    }

    public function test_on_hold_withdrawal_keeps_fee_as_charged_not_collected(): void
    {
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor());
        $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertCreated();
        $w = Withdrawal::first();
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$w->id}/hold", ['reason' => 'check'])->assertOk();
        $this->assertSame('charged', $w->fresh()->fee_status);
        $this->assertSame('0.00', app(WithdrawalFeeService::class)->summary()['amounts']['fees_collected']);
    }

    public function test_second_pending_withdrawal_and_insufficient_balance_are_refused(): void
    {
        $inv = $this->withPin($this->investor(), '1234', '150.00');
        $this->submit($inv)->assertCreated();
        $this->submit($inv)->assertStatus(422);
        $this->assertSame(1, Withdrawal::count());
        $poor = $this->withPin($this->investor(), '1234', '50.00');
        $this->submit($poor)->assertStatus(422);
    }

    public function test_duplicate_submission_with_same_idempotency_key_creates_one_withdrawal(): void
    {
        $inv = $this->withPin($this->investor());
        $h = ['Idempotency-Key' => 'abcdef123456'];
        $a = $this->submit($inv, [], $h)->assertCreated();
        $b = $this->submit($inv, [], $h)->assertOk();
        $this->assertTrue($b->json('duplicate'));
        $this->assertSame($a->json('withdrawal.id'), $b->json('withdrawal.id'));
        $this->assertSame(1, Withdrawal::count());
    }

    public function test_investor_sees_only_their_own_withdrawals_with_fee_details(): void
    {
        $a = $this->withPin($this->investor()); $b = $this->withPin($this->investor());
        $this->submit($a)->assertCreated();
        $rows = $this->fin($b)->getJson(self::BASE)->assertOk()->json('withdrawals');
        $this->assertCount(0, $rows);
        $rows = $this->fin($a)->getJson(self::BASE)->assertOk()->json('withdrawals');
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('fee_amount', $rows[0]);
        $this->assertArrayHasKey('transaction_reference', $rows[0]);
    }

    // ── policy configuration ──────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_policy_versions_are_append_only_and_audited(): void
    {
        $this->fin($this->financial)->postJson('/api/financial/withdrawal-fees/policy', ['fee_type' => 'fixed', 'fixed_amount' => 2, 'is_active' => true, 'reason' => 'draft'])->assertCreated();
        $this->assertSame(2, WithdrawalFeePolicy::count());
        $this->assertDatabaseHas('financial_audit_logs', ['action' => 'withdrawal_fee.policy_saved', 'actor_id' => $this->financial->id]);
        $this->expectException(\LogicException::class);
        WithdrawalFeePolicy::first()->delete();
    }

    public function test_a_financial_user_cannot_mark_a_fee_policy_approved_so_a_draft_never_charges(): void
    {
        $this->fin($this->financial)->postJson('/api/financial/withdrawal-fees/policy', ['fee_type' => 'fixed', 'fixed_amount' => 2, 'is_active' => true, 'policy_approved' => true, 'approval_reference' => 'X', 'reason' => 'try'])->assertStatus(422);
        $this->fin($this->financial)->postJson('/api/financial/withdrawal-fees/policy', ['fee_type' => 'fixed', 'fixed_amount' => 2, 'is_active' => true, 'reason' => 'draft'])->assertCreated();
        $inv = $this->withPin($this->investor());
        $this->fin($inv)->getJson(self::BASE . '/quote?amount=100')->assertJsonPath('quote.fee', '0.00');
    }

    public function test_admin_approval_requires_a_management_reference_and_valid_numbers(): void
    {
        $base = ['fee_type' => 'fixed', 'fixed_amount' => 2, 'is_active' => true, 'policy_approved' => true, 'reason' => 'test reason'];
        $this->fin($this->admin)->postJson('/api/financial/withdrawal-fees/policy', $base)->assertStatus(422);
        $this->fin($this->admin)->postJson('/api/financial/withdrawal-fees/policy', $base + ['approval_reference' => 'M-1'])->assertCreated();
        foreach ([['fee_type' => 'fixed'], ['fee_type' => 'percentage', 'percentage' => 150], ['fee_type' => 'percentage', 'percentage' => -1], ['fee_type' => 'fixed', 'fixed_amount' => -3],
            ['fee_type' => 'combined', 'fixed_amount' => 1], ['fee_type' => 'bogus'], ['fee_type' => 'fixed', 'fixed_amount' => 1, 'currency' => 'EUR']] as $bad) {
            $this->fin($this->admin)->postJson('/api/financial/withdrawal-fees/policy', $bad + ['reason' => 'test reason'])->assertStatus(422);
        }
    }

    public function test_a_policy_cannot_be_back_dated_and_a_future_policy_does_not_apply_yet(): void
    {
        $this->fin($this->admin)->postJson('/api/financial/withdrawal-fees/policy', ['fee_type' => 'fixed', 'fixed_amount' => 2, 'effective_from' => now()->subDays(3)->toDateString(), 'reason' => 'test reason'])->assertStatus(422);
        $this->approveFee('fixed', ['fixed_amount' => '9.00', 'effective_from' => now()->addDays(2)->toDateTimeString()]);
        $this->fin($this->withPin($this->investor()))->getJson(self::BASE . '/quote?amount=100')->assertJsonPath('quote.fee', '0.00');
    }

    // ── adjustments ──────────────────────────────────────────────────────────────────────────────────────────────────────────────
    private function collectedWithdrawal()
    {
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor());
        $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertCreated();
        $w = Withdrawal::first();
        $this->fin($this->financial)->postJson("/api/financial/withdrawals/{$w->id}/approve")->assertOk();
        return [$inv, $w->fresh()];
    }

    public function test_reversal_is_linked_to_the_collection_and_cannot_happen_twice(): void
    {
        config(['ssi_task5.withdrawal_fee.allow_adjustments' => true]);   // feature is off by default (see test_fee_reversal_waiver_and_refund_are_disabled_by_default)
        [$inv, $w] = $this->collectedWithdrawal();
        $this->fin($this->financial)->postJson("/api/financial/withdrawal-fees/withdrawals/{$w->id}/adjust", ['action' => 'reverse', 'reason' => 'goodwill'])->assertOk()->assertJsonPath('fee_status', 'reversed');
        $this->fin($this->financial)->postJson("/api/financial/withdrawal-fees/withdrawals/{$w->id}/adjust", ['action' => 'reverse', 'reason' => 'again'])->assertStatus(422);
        $rev = WithdrawalFeeEvent::where('withdrawal_id', $w->id)->where('event', 'reversed')->get();
        $this->assertCount(1, $rev);
        $this->assertSame(WithdrawalFeeEvent::where('event', 'collected')->value('id'), $rev[0]->related_event_id);
        $this->assertSame(1, WithdrawalFeeEvent::where('event', 'collected')->count(), 'original history is kept');
        $this->assertSame('900.00', number_format((float) $inv->fresh()->balance, 2, '.', ''), 'a record-only reversal does not move the wallet');
        $s = app(WithdrawalFeeService::class)->summary()['amounts'];
        $this->assertSame(['5.00', '0.00', '5.00'], [$s['fees_charged'], $s['fees_collected'], $s['fees_reversed_or_adjusted']]);
    }

    public function test_refund_to_wallet_credits_the_fee_exactly_once(): void
    {
        config(['ssi_task5.withdrawal_fee.allow_adjustments' => true]);   // feature is off by default (see test_fee_reversal_waiver_and_refund_are_disabled_by_default)
        [$inv, $w] = $this->collectedWithdrawal();
        $this->fin($this->financial)->postJson("/api/financial/withdrawal-fees/withdrawals/{$w->id}/adjust", ['action' => 'reverse', 'reason' => 'refund', 'refund_to_wallet' => true])->assertOk();
        $this->fin($this->financial)->postJson("/api/financial/withdrawal-fees/withdrawals/{$w->id}/adjust", ['action' => 'reverse', 'reason' => 'refund', 'refund_to_wallet' => true])->assertStatus(422);
        $this->assertSame('905.00', number_format((float) $inv->fresh()->balance, 2, '.', ''));
    }

    public function test_only_a_collected_fee_can_be_reversed_and_review_flags_without_moving_money(): void
    {
        config(['ssi_task5.withdrawal_fee.allow_adjustments' => true]);   // feature is off by default (see test_fee_reversal_waiver_and_refund_are_disabled_by_default)
        $this->approveFee('fixed', ['fixed_amount' => '5.00']);
        $inv = $this->withPin($this->investor());
        $this->submit($inv, ['confirmed_fee' => '5.00', 'confirmed_net' => '95.00'])->assertCreated();
        $w = Withdrawal::first();
        $this->fin($this->financial)->postJson("/api/financial/withdrawal-fees/withdrawals/{$w->id}/adjust", ['action' => 'reverse', 'reason' => 'x'])->assertStatus(422);
        $this->fin($this->financial)->postJson("/api/financial/withdrawal-fees/withdrawals/{$w->id}/adjust", ['action' => 'review', 'reason' => 'unclear policy'])->assertOk()->assertJsonPath('fee_status', 'review');
        $this->assertSame('1000.00', number_format((float) $inv->fresh()->balance, 2, '.', ''));
        $this->fin($this->financial)->getJson('/api/financial/withdrawal-fees/reconciliation')->assertOk()->assertJsonPath('reconciled', false)->assertJsonPath('issues.0.code', 'flagged');
    }

    public function test_fee_reversal_waiver_and_refund_are_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('ssi_task5.withdrawal_fee.allow_adjustments'));
        [$inv, $w] = $this->collectedWithdrawal();
        $before = $w->fresh()->fee_status;
        foreach ([['reverse', false], ['waive', false], ['reverse', true]] as [$a, $refund]) {
            $this->fin($this->financial)->postJson("/api/financial/withdrawal-fees/withdrawals/{$w->id}/adjust", ['action' => $a, 'reason' => 'abc', 'refund_to_wallet' => $refund])->assertStatus(403);
        }
        $this->assertSame($before, $w->fresh()->fee_status, 'a refused adjustment changes nothing');
        $this->fin($this->financial)->postJson("/api/financial/withdrawal-fees/withdrawals/{$w->id}/adjust", ['action' => 'review', 'reason' => 'abc'])->assertOk();   // flagging stays available
    }

    public function test_reconciliation_detects_a_corrupted_record(): void
    {
        [$inv, $w] = $this->collectedWithdrawal();
        \Illuminate\Support\Facades\DB::table('withdrawals')->where('id', $w->id)->update(['fee_status' => 'charged']);
        $codes = collect(app(WithdrawalFeeService::class)->reconcile())->pluck('code')->all();
        $this->assertContains('approved_uncollected', $codes);
    }

    public function test_decimal_precision_survives_the_database_round_trip(): void
    {
        $this->approveFee('percentage', ['percentage' => '1.005']);
        $inv = $this->withPin($this->investor());
        $q = $this->fin($inv)->getJson(self::BASE . '/quote?amount=99.99')->json('quote');
        $this->assertSame(['99.99', '1.00', '98.99'], [$q['gross'], $q['fee'], $q['net']]);   // 99.99 x 1.005% = 1.0049 -> 1.00
    }
}
