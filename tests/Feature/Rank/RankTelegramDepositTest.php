<?php
// LOCATION: tests/Feature/Rank/RankTelegramDepositTest.php
//
// Telegram-coordinated, multi-currency deposits: the actual payment is preserved; nothing is approved by a Telegram
// message; only approved + staff-verified deposits reach a rank (converted with a recorded rate); thresholds unchanged.

namespace Tests\Feature\Rank;

use App\Models\Deposit;
use App\Models\FinancialAuditLog;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\UsdValuation;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankInvestmentLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RankTelegramDepositTest extends RankTestCase
{
    protected InvestmentPlan $plan;
    protected User $fin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->plan = InvestmentPlan::create(['name' => 'Starter', 'min_amount' => 1, 'profit_percentage' => 10, 'profit_percent' => 10, 'duration_days' => 30, 'status' => 'active']);
        $this->fin  = User::factory()->create(['role' => 'financial', 'status' => 'active']);
    }

    protected function today(): string { return now()->toDateString(); }

    protected function pending(User $u, float $requested = 500, string $status = 'pending'): Deposit
    {
        return Deposit::create([
            'user_id' => $u->id, 'investment_plan_id' => $this->plan->id, 'amount' => $requested, 'payment_method' => 'bank_transfer',
            'transaction_reference' => 'DEP-2026-' . strtoupper(substr(uniqid(), -8)), 'status' => $status,
        ]);
    }

    protected function details(array $o = []): array
    {
        return array_merge([
            'paid_amount' => 2000000, 'paid_currency' => 'NGN', 'rate' => 0.00065, 'rate_source' => 'Central bank reference rate', 'rate_date' => $this->today(), 'payment_date' => $this->today(),
            'external_reference' => 'TRF-' . uniqid(), 'funding_channel' => 'telegram', 'note' => 'Matched bank slip with the Telegram agent',
        ], $o);
    }

    protected function record(Deposit $d, array $o = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->fin, 'sanctum')->postJson("/api/financial/deposits/{$d->id}/payment-details", $this->details($o));
    }

    protected function approve(Deposit $d, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->fin, 'sanctum')->postJson("/api/financial/deposits/{$d->id}/approve");
    }

    protected function facts(User $u): array { return app(RankInvestmentLedger::class)->forUsers([$u->id])[$u->id]; }

    protected function rankOf(User $u): ?string
    {
        app(RankEvaluationService::class)->evaluateUser($u->id, 'test');
        return $this->freshRank($u);
    }

    /** Fund an investor through the full Telegram path and return the investment that approval opened. */
    protected function fundedInvestment(User $u, array $o = []): InvestmentAccount
    {
        $d = $this->pending($u, 1);
        $this->record($d, $o)->assertOk();
        $this->approve($d)->assertOk();
        return InvestmentAccount::where('source_deposit_id', $d->id)->sole();
    }

    protected function complete(InvestmentAccount $i, int $n = 0): void
    {
        DB::table('investment_accounts')->where('id', $i->id)->update(['status' => 'completed', 'start_date' => now()->subDays(60 + $n)->toDateString(), 'end_date' => now()->subDays(25 + $n)->toDateString()]);
    }

    // ── Telegram is coordination only ───────────────────────────────────────────────

    public function test_investor_starts_a_deposit_and_gets_the_telegram_agent_link_but_nothing_is_paid_or_approved(): void
    {
        $u = $this->investor();
        $res = $this->actingAs($u, 'sanctum')->postJson('/api/investor-investment/investor/deposits/initiate', ['investment_plan_id' => $this->plan->id, 'amount' => 500])->assertCreated();

        $this->assertStringContainsString('t.me/', $res->json('agent.link'));
        $d = Deposit::findOrFail($res->json('deposit_id'));
        $this->assertSame('pending', $d->status);
        $this->assertNull($d->paid_currency);
        $this->assertNull($d->payment_verified_at);
        $this->assertEquals(0, (float) $u->fresh()->balance);
    }

    protected function webhook(array $headers, string $text = 'hello')
    {
        return $this->postJson('/api/telegram/webhook', ['message' => ['chat' => ['id' => 1], 'from' => ['id' => 2, 'first_name' => 'X'], 'text' => $text]], $headers);
    }

    public function test_the_webhook_is_disabled_until_a_secret_is_configured(): void
    {
        config(['services.telegram.webhook_secret' => null]);
        $this->webhook(['X-Telegram-Bot-Api-Secret-Token' => 'anything-at-all-12345'])->assertStatus(503);
        $this->webhook([])->assertStatus(503);
    }

    public function test_the_webhook_rejects_requests_without_telegrams_secret_token(): void
    {
        config(['services.telegram.webhook_secret' => 'a-long-random-secret-0123456789']);
        $this->webhook([])->assertForbidden();
        $this->webhook(['X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret-wrong-secret-1'])->assertForbidden();
        $this->webhook(['X-Telegram-Bot-Api-Secret-Token' => 'a-long-random-secret-0123456789'])->assertOk();
    }

    public function test_a_weak_secret_is_treated_as_not_configured(): void
    {
        config(['services.telegram.webhook_secret' => 'short']);
        $this->webhook(['X-Telegram-Bot-Api-Secret-Token' => 'short'])->assertStatus(503);
    }

    public function test_the_old_authenticated_webhook_path_is_gone(): void
    {
        $this->actingAs($this->investor(), 'sanctum')->postJson('/api/investor-investment/telegram/webhook', ['message' => []])->assertStatus(404);
    }

    public function test_telegram_messages_never_approve_or_change_a_deposit(): void
    {
        config(['services.telegram.webhook_secret' => 'a-long-random-secret-0123456789']);
        $u = $this->investor(); $d = $this->pending($u);
        $this->record($d)->assertOk();
        $before = $d->fresh()->toJson(); $balance = (float) $u->fresh()->balance;
        foreach (["approve {$d->transaction_reference}", "/approve {$d->id}", "PAID {$d->transaction_reference} NGN 2,000,000 confirmed", "/start"] as $text) {
            $this->webhook(['X-Telegram-Bot-Api-Secret-Token' => 'a-long-random-secret-0123456789'], $text)->assertOk();
        }
        $this->assertSame($before, $d->fresh()->toJson());
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertSame($balance, (float) $u->fresh()->balance);
        $this->assertSame(0, InvestmentAccount::count());
    }

    // ── what the investor declares vs what staff verify ─────────────────────────────

    public function test_investor_declaration_is_stored_unverified_and_changes_nothing_else(): void
    {
        $u = $this->investor(); $d = $this->pending($u, 500);
        $this->actingAs($u, 'sanctum')->putJson("/api/investor-investment/investor/deposits/{$d->id}/confirm", [
            'payment_method' => 'bank_transfer', 'paid_amount' => 800000, 'paid_currency' => 'ngn', 'external_reference' => 'BANK-123', 'funding_channel' => 'telegram',
        ])->assertOk();

        $d->refresh();
        $this->assertSame('NGN', $d->paid_currency);
        $this->assertEquals(800000, (float) $d->paid_amount);
        $this->assertNull($d->payment_verified_at);
        $this->assertEquals(500, (float) $d->amount);                         // the credited figure is untouched
        $this->assertSame('pending', $d->status);
    }

    public function test_investor_cannot_declare_an_unknown_currency_or_forge_verification(): void
    {
        $u = $this->investor(); $d = $this->pending($u);
        $this->actingAs($u, 'sanctum')->putJson("/api/investor-investment/investor/deposits/{$d->id}/confirm", ['payment_method' => 'bank_transfer', 'paid_currency' => 'NRG', 'paid_amount' => 5])->assertStatus(422);
        $this->putJson("/api/investor-investment/investor/deposits/{$d->id}/confirm", ['payment_method' => 'bank_transfer', 'payment_verified_at' => now()->toDateTimeString(), 'approved_amount' => 99999, 'amount' => 99999, 'status' => 'approved'])->assertOk();
        $d->refresh();
        $this->assertNull($d->payment_verified_at);
        $this->assertNull($d->approved_amount);
        $this->assertSame('pending', $d->status);
        $this->assertEquals(500, (float) $d->amount);
    }

    public function test_investor_cannot_record_staff_payment_details(): void
    {
        $u = $this->investor(); $d = $this->pending($u);
        $this->record($d, [], $u)->assertForbidden();
        $this->assertNull($d->fresh()->payment_verified_at);
    }

    // ── staff record the actual payment (different currencies, methods) ─────────────

    public function test_staff_record_actual_payment_and_approved_usd_without_approving_or_moving_money(): void
    {
        $u = $this->investor(['balance' => 10]); $d = $this->pending($u, 999);
        $this->record($d)->assertOk()->assertJsonPath('status', 'pending');

        $d->refresh();
        $this->assertEquals(2000000, (float) $d->paid_amount);
        $this->assertSame('NGN', $d->paid_currency);
        $this->assertEquals(0.00065, (float) $d->fx_rate);
        $this->assertSame('Central bank reference rate', $d->rate_source);
        $this->assertEquals(1300.00, (float) $d->approved_amount);
        $this->assertEquals(1300.00, (float) $d->amount);
        $this->assertSame($this->fin->id, (int) $d->payment_verified_by);
        $this->assertSame('pending', $d->status);
        $this->assertEquals(10, (float) $u->fresh()->balance);                    // nothing credited yet
        $log = FinancialAuditLog::where('action', 'deposit.payment_recorded')->sole();
        $this->assertStringContainsString('999', json_encode($log->toArray()));  // the requested figure is kept in the audit trail
    }

    public function test_every_supported_funding_method_and_currency_can_be_recorded(): void
    {
        $u = $this->investor();
        foreach ([['bitcoin', 'BTC', 60000.0], ['ethereum', 'ETH', 3000.0], ['usdt', 'USDT', 1.0], ['bank_transfer', 'GBP', 1.25], ['bank_transfer', 'USD', null]] as [$method, $cur, $rate]) {
            $d = $this->pending($u); $d->update(['payment_method' => $method]);
            $this->record($d, ['paid_amount' => 10, 'paid_currency' => $cur, 'rate' => $rate, 'rate_source' => 'Rate card', 'rate_date' => $this->today()])->assertOk();
            $this->assertSame($method, $d->fresh()->payment_method);
            $this->assertEquals(round(10 * ($rate ?? 1), 2), (float) $d->fresh()->amount);
        }
    }

    public function test_recording_is_validated_and_never_assumes_usd(): void
    {
        $d = $this->pending($this->investor());
        $this->record($d, ['paid_currency' => 'NRG'])->assertStatus(422)->assertJsonValidationErrors('paid_currency');
        $this->record($d, ['rate' => null, 'rate_source' => null, 'rate_date' => null])->assertStatus(422)->assertJsonValidationErrors(['rate', 'rate_source', 'rate_date']);
        $this->record($d, ['paid_amount' => 0])->assertStatus(422)->assertJsonValidationErrors('paid_amount');
        $this->record($d, ['rate_date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('rate_date');
        $this->assertNull($d->fresh()->payment_verified_at);
        $this->assertSame(0, FinancialAuditLog::where('action', 'deposit.payment_recorded')->count());
    }

    public function test_only_pending_or_held_deposits_accept_payment_details(): void
    {
        $u = $this->investor();
        foreach (['approved', 'rejected'] as $s) {
            $this->record($this->pending($u, 100, $s))->assertStatus(422)->assertJsonValidationErrors('deposit');
        }
        $this->record($this->pending($u, 100, 'hold'))->assertOk();
    }

    public function test_a_surprising_rate_needs_acknowledgement(): void
    {
        $u = $this->investor();
        $this->record($this->pending($u), ['paid_currency' => 'GBP', 'paid_amount' => 100, 'rate' => 1.25])->assertOk();
        $d = $this->pending($u);
        $this->record($d, ['paid_currency' => 'GBP', 'paid_amount' => 100, 'rate' => 0.8])->assertStatus(422)->assertJsonValidationErrors('rate_warnings');
        $this->assertNull($d->fresh()->payment_verified_at);
        $this->record($d, ['paid_currency' => 'GBP', 'paid_amount' => 100, 'rate' => 0.8, 'acknowledge_rate_warnings' => true])->assertOk();
    }

    public function test_the_same_transfer_reference_cannot_be_recorded_twice_unless_confirmed(): void
    {
        $u = $this->investor(); $a = $this->pending($u); $b = $this->pending($u);
        $this->record($a, ['external_reference' => 'BANK-777'])->assertOk();
        $this->record($b, ['external_reference' => 'bank-777'])->assertStatus(422)->assertJsonValidationErrors('duplicate');
        $this->record($b, ['external_reference' => 'bank-777', 'confirm_distinct' => true])->assertOk();

        $a->update(['status' => 'rejected']);                                    // a rejected deposit never blocks a re-submission
        $c = $this->pending($u); $b->update(['external_reference' => null]);
        $this->record($c, ['external_reference' => 'BANK-777'])->assertOk();
    }

    // ── approval keeps its existing behaviour; verified payments are valued, others flagged ─

    public function test_approval_credits_the_approved_usd_amount_and_links_the_investment(): void
    {
        $u = $this->investor(['balance' => 0]); $d = $this->pending($u, 999);
        $this->record($d, ['paid_amount' => 1000, 'paid_currency' => 'EUR', 'rate' => 1.1])->assertOk();
        $this->approve($d)->assertOk();

        $this->assertEquals(1100.0, (float) $u->fresh()->balance);
        $inv = InvestmentAccount::where('source_deposit_id', $d->id)->sole();
        $this->assertEquals(1100.0, (float) $inv->amount);
        $this->assertSame(2, UsdValuation::count());                              // the deposit and the investment it opened
        $this->assertSame([], $this->facts($u)['unverified']);
        $this->assertEquals(1100.0, $this->facts($u)['total_usd']);
    }

    // ── the approval safety gate ────────────────────────────────────────────────────

    public function test_a_deposit_with_unknown_payment_cannot_be_approved_credited_or_invested(): void
    {
        $u = $this->investor(); $d = $this->pending($u, 5000);
        $this->approve($d)->assertStatus(422)->assertJsonPath('code', 'payment_unverified');

        $this->assertSame('pending', $d->fresh()->status);
        $this->assertEquals(0, (float) $u->fresh()->balance);
        $this->assertSame(0, InvestmentAccount::count());
        $this->assertSame(0, FinancialAuditLog::where('action', 'deposit.approved')->count());
    }

    public function test_a_declared_non_usd_payment_cannot_be_approved_until_the_rate_is_recorded(): void
    {
        $u = $this->investor(); $d = $this->pending($u, 800);
        $this->actingAs($u, 'sanctum')->putJson("/api/investor-investment/investor/deposits/{$d->id}/confirm", ['payment_method' => 'bank_transfer', 'paid_amount' => 2000000, 'paid_currency' => 'NGN'])->assertOk();

        $this->approve($d)->assertStatus(422)->assertJsonPath('code', 'payment_unverified');
        // …and the USD attestation shortcut is refused too: the investor said NGN, so USD cannot be assumed.
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/deposits/{$d->id}/approve", ['confirm_usd' => true, 'reason' => 'Looks like dollars to me'])->assertStatus(422);
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertEquals(0, (float) $u->fresh()->balance);

        $this->record($d, ['paid_amount' => 2000000, 'paid_currency' => 'NGN'])->assertOk();
        $this->approve($d)->assertOk();
        $this->assertEquals(1300.0, (float) $u->fresh()->balance);          // the verified USD value, not 800 and not 2,000,000
    }

    public function test_staff_may_explicitly_attest_that_a_payment_was_received_in_usd(): void
    {
        $u = $this->investor(); $d = $this->pending($u, 750);
        $pay = ['confirm_usd' => true];
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/deposits/{$d->id}/approve", $pay)->assertStatus(422);                       // needs a reason
        $this->postJson("/api/financial/deposits/{$d->id}/approve", $pay + ['reason' => 'USD wire seen on statement'])->assertOk();

        $d->refresh();
        $this->assertSame('USD', $d->paid_currency);
        $this->assertEquals(750.0, (float) $d->paid_amount);
        $this->assertNotNull($d->payment_verified_at);
        $this->assertEquals(750.0, (float) $u->fresh()->balance);
        $this->assertSame(2, UsdValuation::count());
        $this->assertSame(1, FinancialAuditLog::where('action', 'deposit.payment_recorded')->count());
    }

    public function test_non_usd_payments_need_a_rate_a_source_and_a_payment_date(): void
    {
        $d = $this->pending($this->investor());
        $this->record($d, ['payment_date' => null])->assertStatus(422)->assertJsonValidationErrors('payment_date');
        $this->record($d, ['payment_date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('payment_date');
        $this->record($d, ['rate' => 0])->assertStatus(422)->assertJsonValidationErrors('rate');
        $this->record($d, ['rate_source' => 'x'])->assertStatus(422)->assertJsonValidationErrors('rate_source');
        $this->assertNull($d->fresh()->payment_verified_at);
        $this->record($d, ['paid_currency' => 'USD', 'rate' => null, 'rate_source' => null, 'rate_date' => null, 'payment_date' => null])->assertOk();   // USD needs none of them
    }

    public function test_a_usd_declaration_without_verified_receipt_cannot_be_approved_or_credited(): void
    {
        $u = $this->investor(); $d = $this->pending($u, 700);

        // The investor DECLARES a USD payment. A declaration is a claim, not proof that money was received.
        $this->actingAs($u, 'sanctum')->putJson("/api/investor-investment/investor/deposits/{$d->id}/confirm", ['payment_method' => 'bank_transfer', 'paid_amount' => 700, 'paid_currency' => 'USD'])->assertOk();
        $this->assertNull($d->fresh()->payment_verified_at);

        // The investor cannot approve their own deposit.
        $this->actingAs($u, 'sanctum')->postJson("/api/financial/deposits/{$d->id}/approve")->assertStatus(403);
        // Authorized staff cannot approve it either until the receipt has been verified.
        $this->approve($d)->assertStatus(422)->assertJsonPath('code', 'payment_unverified');
        // The USD attestation shortcut needs a real written reason; a throwaway one does not unlock it.
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/deposits/{$d->id}/approve", ['confirm_usd' => true, 'reason' => 'ok'])
            ->assertStatus(422)->assertJsonPath('code', 'payment_unverified');

        $this->assertSame('pending', $d->fresh()->status);
        $this->assertNull($d->fresh()->payment_verified_at);
        $this->assertEquals(0, (float) $u->fresh()->balance);
        $this->assertSame(0, InvestmentAccount::count());
        $this->assertSame(0, FinancialAuditLog::where('action', 'deposit.approved')->count());
    }

    public function test_invalid_conversion_data_cannot_be_used_to_bypass_verification(): void
    {
        $u = $this->investor(); $d = $this->pending($u, 800);

        // Zero, negative, malformed and missing rates are all refused for a non-USD payment.
        foreach ([['rate' => 0], ['rate' => -0.5], ['rate' => 'abc'], ['rate' => null]] as $bad) {
            $this->record($d, $bad)->assertStatus(422)->assertJsonValidationErrors('rate');
        }
        // So are an unsupported currency and a zero / negative amount paid.
        $this->record($d, ['paid_currency' => 'NRG'])->assertStatus(422)->assertJsonValidationErrors('paid_currency');
        $this->record($d, ['paid_amount' => 0])->assertStatus(422)->assertJsonValidationErrors('paid_amount');
        $this->record($d, ['paid_amount' => -5])->assertStatus(422)->assertJsonValidationErrors('paid_amount');

        // None of the rejected attempts verified anything, so approval is still blocked and nothing was credited.
        $this->assertNull($d->fresh()->payment_verified_at);
        $this->approve($d)->assertStatus(422)->assertJsonPath('code', 'payment_unverified');
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertEquals(0, (float) $u->fresh()->balance);
        $this->assertSame(0, InvestmentAccount::count());
        $this->assertSame(0, UsdValuation::count());
        $this->assertSame(0, FinancialAuditLog::where('action', 'deposit.approved')->count());
    }

    public function test_the_approved_amount_must_follow_from_the_paid_amount_and_rate(): void
    {
        $u = $this->investor(); $d = $this->pending($u);
        // 2,000,000 × 0.00065 = 1,300.00. 2,000 is far off.
        $this->record($d, ['approved_amount' => 2000])->assertStatus(422)->assertJsonValidationErrors('amount_difference');
        $this->assertNull($d->fresh()->payment_verified_at);
        $this->record($d, ['approved_amount' => 1305])->assertOk();                                   // +0.4 %: within the 1 % tolerance
        $this->assertFalse((bool) $d->fresh()->amount_difference_acknowledged);

        $e = $this->pending($u);
        $this->record($e, ['approved_amount' => 1200, 'acknowledge_amount_difference' => true])->assertOk();   // fees: knowingly acknowledged
        $this->assertTrue((bool) $e->fresh()->amount_difference_acknowledged);
        $this->approve($e)->assertOk();
        $this->assertEquals(1200.0, (float) $u->fresh()->balance);                                    // only the approved value is credited
        $this->assertStringContainsString('"amount_difference_acknowledged":true', json_encode(FinancialAuditLog::where('action', 'deposit.payment_recorded')->latest('id')->first()->toArray()));
    }

    public function test_a_figure_changed_after_verification_blocks_approval(): void
    {
        $u = $this->investor(); $d = $this->pending($u);
        $this->record($d)->assertOk();
        DB::table('deposits')->where('id', $d->id)->update(['amount' => 99999]);                     // tampered outside the verified path

        $this->approve($d)->assertStatus(422)->assertJsonPath('code', 'payment_unverified');
        $this->assertEquals(0, (float) $u->fresh()->balance);
        $this->assertSame(0, InvestmentAccount::count());
    }

    public function test_a_retry_cannot_credit_or_invest_twice(): void
    {
        $u = $this->investor(['balance' => 0]); $d = $this->pending($u);
        $this->record($d)->assertOk();
        $this->approve($d)->assertOk();
        $this->approve($d)->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/deposits/{$d->id}/approve")->assertStatus(422);

        $this->assertEquals(1300.0, (float) $u->fresh()->balance);
        $this->assertSame(1, InvestmentAccount::where('source_deposit_id', $d->id)->count());
        $this->assertSame(1, FinancialAuditLog::where('action', 'deposit.approved')->count());
        $this->assertSame(1, DB::table('balance_adjustments')->where('user_id', $u->id)->count());
    }

    public function test_the_database_itself_refuses_a_second_investment_for_one_deposit(): void
    {
        $u = $this->investor(); $d = $this->pending($u);
        $this->record($d)->assertOk();
        $this->approve($d)->assertOk();
        $this->expectException(\Illuminate\Database\QueryException::class);
        InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $this->plan->id, 'amount' => 1, 'source_deposit_id' => $d->id, 'status' => 'active',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString()]);
    }

    public function test_the_approved_deposit_keeps_the_original_payment_for_audit(): void
    {
        $u = $this->investor(); $d = $this->pending($u, 999);
        $this->record($d, ['paid_amount' => 1000, 'paid_currency' => 'EUR', 'rate' => 1.1])->assertOk();
        $this->approve($d)->assertOk();
        $d->refresh();
        $this->assertEquals(1000.0, (float) $d->paid_amount);
        $this->assertSame('EUR', $d->paid_currency);
        $this->assertEquals(1.1, (float) $d->fx_rate);
        $this->assertEquals(1100.0, (float) $d->approved_amount);
        $this->assertSame($this->today(), $d->payment_date->toDateString());
        $this->assertStringContainsString('999', json_encode(FinancialAuditLog::where('action', 'deposit.payment_recorded')->sole()->toArray()));
    }

    public function test_investors_can_list_payment_currencies_but_not_staff_currency_tools(): void
    {
        $u = $this->investor();
        $codes = collect($this->actingAs($u, 'sanctum')->getJson('/api/investor-investment/investor/deposits/currencies')->assertOk()->json('currencies'))->pluck('code');
        $this->assertTrue($codes->contains('NGN') && $codes->contains('USD') && $codes->contains('GBP'));
        $this->getJson('/api/financial/currency/supported')->assertForbidden();
    }

    public function test_pending_held_and_rejected_deposits_never_count(): void
    {
        $u = $this->investor();
        $pending = $this->pending($u, 50000); $hold = $this->pending($u, 50000, 'hold'); $rejected = $this->pending($u, 50000, 'rejected');
        $this->record($pending)->assertOk();                                      // verified details, but still pending
        $f = app(RankInvestmentLedger::class)->depositsForUsers([$u->id])[$u->id];
        $this->assertEquals(0.0, $f['total_usd']);
        $this->assertSame([], $f['unverified']);                                  // not even queued: they are not approved
        $this->assertSame(0, UsdValuation::count());
        $this->assertNull($this->rankOf($u));
    }

    public function test_a_rejected_deposit_creates_no_investment_and_no_valuation(): void
    {
        $u = $this->investor(); $d = $this->pending($u);
        $this->record($d)->assertOk();
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/deposits/{$d->id}/reject", ['reason' => 'Payment not received'])->assertOk();
        $this->assertSame(0, InvestmentAccount::count());
        $this->assertSame(0, UsdValuation::count());
        $this->assertEquals(0, (float) $u->fresh()->balance);
    }

    public function test_a_duplicate_looking_second_investment_is_excluded_for_review(): void
    {
        $u = $this->investor();
        $a = $this->fundedInvestment($u, ['paid_amount' => 1000, 'paid_currency' => 'EUR', 'rate' => 1.1]);
        $b = $this->fundedInvestment($u, ['paid_amount' => 1000, 'paid_currency' => 'EUR', 'rate' => 1.1]);   // same plan/amount/day, moments apart

        $f = $this->facts($u);
        $this->assertContains($b->id, $f['duplicates']);
        $this->assertEquals(1100.0, $f['total_usd']);                              // counted once
    }

    // ── ranks from Telegram-funded, multi-currency, completed cycles ────────────────

    public function test_telegram_funded_investments_in_different_currencies_reach_the_right_rank(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor();

        // 3 cycles: NGN 2,000,000 @ 0.00065 = 1,300 each → $3,900 → Silver (not Gold, whatever the raw NGN figure).
        foreach ([0, 1, 2] as $i) $this->complete($this->fundedInvestment($u, ['paid_amount' => 2000000 + $i, 'paid_currency' => 'NGN', 'rate' => 0.00065]), $i);
        $this->assertSame('silver', $this->rankOf($u));

        // A GBP deposit pushes it to exactly $5,000+ with 4 cycles → Gold.
        $this->complete($this->fundedInvestment($u, ['paid_amount' => 880, 'paid_currency' => 'GBP', 'rate' => 1.25]), 3);   // +$1,100 = $5,000
        $this->assertEquals(5000.0, $this->facts($u)['total_usd']);
        $this->assertSame('gold', $this->rankOf($u));
    }

    public function test_pending_deposits_do_not_complete_cycles_or_add_amount(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor();
        $this->complete($this->fundedInvestment($u, ['paid_amount' => 100, 'paid_currency' => 'USD', 'rate' => null]), 0);
        $this->assertSame('bronze', $this->rankOf($u));
        $this->record($this->pending($u, 90000), ['paid_amount' => 90000, 'paid_currency' => 'USD', 'rate' => null])->assertOk();
        $this->assertSame('bronze', $this->rankOf($u));
    }

    public function test_legacy_approved_deposits_without_a_conversion_are_flagged_and_never_rank(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor();
        for ($i = 0; $i < 3; $i++) {                                          // approved before payment recording existed
            $d = $this->pending($u, 6000, 'approved');
            $inv = $this->invest($u, 6000, 30, 'completed', false);
            DB::table('investment_accounts')->where('id', $inv->id)->update(['source_deposit_id' => $d->id]);
            $this->complete($inv->fresh(), $i);
        }
        $this->assertNull($this->rankOf($u));
        $this->assertCount(3, $this->facts($u)['unverified']);
        $this->assertCount(3, app(RankInvestmentLedger::class)->depositsForUsers([$u->id])[$u->id]['unverified']);
    }

    public function test_rank_evaluation_never_changes_deposits_balances_or_investments(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
        $u = $this->investor();
        foreach ([0, 1, 2] as $i) $this->complete($this->fundedInvestment($u, ['paid_amount' => 2000000 + $i, 'paid_currency' => 'NGN', 'rate' => 0.00065]), $i);
        $snap = fn () => [DB::table('deposits')->orderBy('id')->get()->toJson(), DB::table('investment_accounts')->orderBy('id')->get()->toJson(), (float) $u->fresh()->balance, DB::table('withdrawals')->count()];

        $before = $snap();
        $this->rankOf($u);
        $this->assertSame($before, $snap());
    }

    public function test_approval_permissions_are_unchanged(): void
    {
        $u = $this->investor(); $d = $this->pending($u);
        $this->approve($d, $u)->assertForbidden();
        $support = User::factory()->create(['role' => 'support', 'status' => 'active']);
        $this->approve($d, $support)->assertForbidden();
        $this->record($d, [], $support)->assertForbidden();
        $this->assertSame('pending', $d->fresh()->status);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/deposits/{$d->id}/payment-details", $this->details())->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/deposits/{$d->id}/approve")->assertOk();
    }

    public function test_thresholds_remain_exactly_managements(): void
    {
        $this->artisan('ranks:load-proposed')->assertSuccessful();
        $expected = ['bronze' => [100, 1], 'silver' => [1000, 2], 'gold' => [5000, 3], 'platinum' => [10000, 5], 'diamond' => [20000, 8], 'elite' => [50000, 12]];
        foreach ($expected as $key => [$amt, $cyc]) {
            $c = \App\Models\RankCriterion::with('conditions')->where('investor_rank_id', $this->rank($key)->id)->sole();
            $this->assertEquals($amt, (float) $c->conditions->firstWhere('field', 'total_invested')->value);
            $this->assertEquals($cyc, (float) $c->conditions->firstWhere('field', 'completed_investment_count')->value);
        }
    }

    // ── visibility ──────────────────────────────────────────────────────────────────

    public function test_staff_screens_and_the_investor_page_show_what_was_actually_paid(): void
    {
        $u = $this->investor(); $d = $this->pending($u);
        $this->record($d)->assertOk();

        $this->actingAs($this->fin, 'sanctum')->getJson("/api/financial/transactions/deposit/{$d->id}")->assertOk()
            ->assertJsonPath('transaction.payment.paid_currency', 'NGN')->assertJsonPath('transaction.payment.verified', true);
        $this->getJson('/api/financial/deposits')->assertOk()->assertJsonPath('deposits.0.payment.paid_currency', 'NGN');

        $this->approve($d)->assertOk();
        $rows = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/ranks/investors/{$u->id}")->assertOk()->json('currency.deposit_funding'))->keyBy('currency');
        $this->assertEquals(2000000, $rows['NGN']['paid_total']);
        $this->assertEquals(1300, $rows['NGN']['approved_usd']);
        $this->assertSame(1, $rows['NGN']['verified']);
    }
}
