<?php
// LOCATION: tests/Feature/FinancialTransactionsAndCommunicationsTest.php
//
// Covers the Financial Team correction brief: unified transactions,
// approve/reject with audit, controlled investment-record corrections,
// immutable audit trail, the department-tagged mailbox (financial <->
// investor), official notices, and the security boundaries around all of it.
// Every assertion goes through real routes + real middleware/gates.

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\FinancialAuditLog;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\Message;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialTransactionsAndCommunicationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $financial;
    protected User $investor;
    protected User $otherInvestor;
    protected InvestmentPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin     = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $this->financial = User::factory()->create(['role' => 'financial', 'name' => 'Fin Officer']);
        $this->investor  = $this->makeInvestor('Ivy Investor');
        $this->otherInvestor = $this->makeInvestor('Otto Other');

        $this->plan = InvestmentPlan::create([
            'name' => 'Starter', 'min_amount' => 100, 'profit_percentage' => 10,
            'profit_percent' => 10, 'duration_days' => 30, 'status' => 'active',
        ]);
    }

    protected function makeInvestor(string $name, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'investor', 'name' => $name, 'registration_stage' => 4,
            'registration_completed' => true, 'balance' => 1000,
        ], $extra));
    }

    protected function makeInvestment(User $user, array $extra = []): InvestmentAccount
    {
        return InvestmentAccount::create(array_merge([
            'user_id' => $user->id, 'investment_plan_id' => $this->plan->id, 'amount' => 1000,
            'profit_percentage' => 10, 'expected_profit' => 100, 'total_return' => 1100,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(),
            'remaining_days' => 30, 'status' => 'active',
        ], $extra));
    }

    protected function makeDeposit(User $user, string $status = 'pending', float $amount = 500): Deposit
    {
        return Deposit::create([
            'user_id' => $user->id, 'amount' => $amount, 'payment_method' => 'bank_transfer',
            'transaction_reference' => 'DEP-T-' . uniqid(), 'status' => $status,
        ]);
    }

    protected function makeWithdrawal(User $user, string $status = 'pending', float $amount = 200): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $user->id, 'amount' => $amount, 'method' => 'bank_transfer',
            'account_details' => json_encode(['bank_name' => 'Test']), 'status' => $status,
        ]);
    }

    protected function fin(): static { return $this->actingAs($this->financial, 'sanctum'); }

    // ═══════════════════════════════════════════════════════════════
    // TRANSACTION MANAGEMENT
    // ═══════════════════════════════════════════════════════════════

    public function test_transactions_list_includes_summary_and_normalised_statuses(): void
    {
        $this->makeDeposit($this->investor, 'pending');
        $this->makeDeposit($this->investor, 'hold');
        $this->makeDeposit($this->investor, 'approved');
        $this->makeWithdrawal($this->investor, 'rejected');
        $this->makeInvestment($this->investor);                        // active  -> approved
        $this->makeInvestment($this->investor, ['status' => 'completed']);
        $this->makeInvestment($this->investor, ['status' => 'cancelled']);

        $res = $this->fin()->getJson('/api/financial/transactions')->assertOk();

        $res->assertJsonPath('summary.pending', 2);      // pending + hold
        $res->assertJsonPath('summary.rejected', 1);
        $res->assertJsonPath('summary.cancelled', 1);
        $res->assertJsonPath('summary.completed', 1);
        $res->assertJsonPath('summary.approved', 2);     // approved deposit + active investment
        $res->assertJsonPath('summary.total', 7);
        $this->assertCount(7, $res->json('data'));

        $held = collect($res->json('data'))->firstWhere('raw_status', 'hold');
        $this->assertSame('pending', $held['status']);
        $this->assertTrue($held['on_hold']);
    }

    public function test_transactions_search_and_filters(): void
    {
        $this->makeDeposit($this->investor, 'pending');
        $this->makeDeposit($this->otherInvestor, 'approved');
        $this->makeWithdrawal($this->investor, 'pending');

        // search by investor name
        $names = collect($this->fin()->getJson('/api/financial/transactions?search=Otto')->assertOk()->json('data'))
            ->pluck('investor.name')->unique()->all();
        $this->assertSame(['Otto Other'], $names);

        // type filter
        $types = collect($this->fin()->getJson('/api/financial/transactions?type=withdrawal')->json('data'))->pluck('type')->unique()->all();
        $this->assertSame(['withdrawal'], $types);

        // status filter
        $st = collect($this->fin()->getJson('/api/financial/transactions?status=approved')->json('data'))->pluck('status')->unique()->all();
        $this->assertSame(['approved'], $st);

        // reference search (DEP-000001 style)
        $dep = Deposit::first();
        $ref = 'DEP-' . str_pad((string) $dep->id, 6, '0', STR_PAD_LEFT);
        $found = collect($this->fin()->getJson("/api/financial/transactions?search={$ref}")->json('data'))->pluck('reference')->all();
        $this->assertContains($ref, $found);

        // date filter: nothing in the far future
        $this->assertCount(0, $this->fin()->getJson('/api/financial/transactions?date_from=' . now()->addYear()->toDateString())->json('data'));

        // bad input rejected
        $this->fin()->getJson('/api/financial/transactions?status=bogus')->assertStatus(422);
    }

    public function test_transaction_detail_returns_actions_and_investor_context(): void
    {
        $dep = $this->makeDeposit($this->investor, 'pending', 750);

        $res = $this->fin()->getJson("/api/financial/transactions/deposit/{$dep->id}")->assertOk();
        $res->assertJsonPath('transaction.amount', 750);
        $res->assertJsonPath('investor.name', 'Ivy Investor');
        $res->assertJsonPath('actions.approve', true);
        $res->assertJsonPath('actions.hold', true);

        $this->fin()->getJson('/api/financial/transactions/bogus/1')->assertStatus(404);
        $this->fin()->getJson('/api/financial/transactions/deposit/999999')->assertStatus(404);
    }

    public function test_financial_approval_credits_balance_and_writes_audit(): void
    {
        $dep = $this->makeDeposit($this->investor, 'pending', 500);

        $this->fin()->postJson("/api/financial/deposits/{$dep->id}/approve")->assertOk();

        $this->assertSame('approved', $dep->fresh()->status);
        $this->assertEquals(1500, $this->investor->fresh()->balance);

        $log = FinancialAuditLog::where('action', 'deposit.approved')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->financial->id, $log->actor_id);
        $this->assertSame('financial', $log->actor_role);
        $this->assertSame($dep->id, $log->entity_id);
        $this->assertSame($this->investor->id, $log->investor_id);
        $this->assertSame('pending', $log->previous_value['status']);
        $this->assertSame('approved', $log->new_value['status']);
    }

    public function test_reject_and_hold_are_audited_with_reason(): void
    {
        $w = $this->makeWithdrawal($this->investor, 'pending');

        $this->fin()->postJson("/api/financial/withdrawals/{$w->id}/hold", ['reason' => 'KYC check'])->assertOk();
        $this->fin()->postJson("/api/financial/withdrawals/{$w->id}/reject", ['reason' => 'Mismatched account name'])->assertOk();

        $this->assertSame('rejected', $w->fresh()->status);
        $this->assertSame('KYC check', FinancialAuditLog::where('action', 'withdrawal.held')->first()->reason);
        $rej = FinancialAuditLog::where('action', 'withdrawal.rejected')->first();
        $this->assertSame('Mismatched account name', $rej->reason);
        $this->assertSame('hold', $rej->previous_value['status']);
    }

    public function test_double_approval_is_rejected_and_audited_once(): void
    {
        $dep = $this->makeDeposit($this->investor, 'pending');
        $this->fin()->postJson("/api/financial/deposits/{$dep->id}/approve")->assertOk();
        $this->fin()->postJson("/api/financial/deposits/{$dep->id}/approve")->assertStatus(422);

        $this->assertSame(1, FinancialAuditLog::where('action', 'deposit.approved')->count());
        $this->assertEquals(1500, $this->investor->fresh()->balance);
    }

    // ═══════════════════════════════════════════════════════════════
    // RECORD CORRECTIONS
    // ═══════════════════════════════════════════════════════════════

    public function test_authorized_correction_updates_record_recomputes_and_audits(): void
    {
        $inv = $this->makeInvestment($this->investor);
        $balanceBefore = (float) $this->investor->fresh()->balance;

        $res = $this->fin()->postJson("/api/financial/investments/{$inv->id}/correct", [
            'amount' => 2000, 'reason' => 'Correction requested after record review.',
        ])->assertOk();

        $inv->refresh();
        $this->assertEquals(2000, $inv->amount);
        $this->assertEquals(200, $inv->expected_profit);   // 10% recomputed server-side
        $this->assertEquals(2200, $inv->total_return);
        // record correction does NOT move wallet money
        $this->assertEquals($balanceBefore, (float) $this->investor->fresh()->balance);

        $log = FinancialAuditLog::where('action', 'investment.record_corrected')->firstOrFail();
        $this->assertSame($res->json('audit_reference'), $log->reference);
        $this->assertEquals(1000, $log->previous_value['amount']);
        $this->assertEquals(2000, $log->new_value['amount']);
        $this->assertSame('Correction requested after record review.', $log->reason);
        $this->assertSame($this->financial->id, $log->actor_id);
        $this->assertSame($this->investor->id, $log->investor_id);
        $this->assertNotNull($log->created_at);

        // investor is told
        $this->assertSame(1, $this->investor->notifications()->count());
    }

    public function test_client_cannot_supply_computed_fields(): void
    {
        $inv = $this->makeInvestment($this->investor);
        $this->fin()->postJson("/api/financial/investments/{$inv->id}/correct", [
            'amount' => 500, 'expected_profit' => 999999, 'total_return' => 999999,
            'reason' => 'Fixing a data entry mistake.',
        ])->assertOk();

        $this->assertEquals(50, $inv->fresh()->expected_profit);
        $this->assertEquals(550, $inv->fresh()->total_return);
    }

    public function test_invalid_corrections_are_rejected_and_not_audited(): void
    {
        $inv = $this->makeInvestment($this->investor);
        $url = "/api/financial/investments/{$inv->id}/correct";

        $this->fin()->postJson($url, ['amount' => 500])->assertStatus(422);                                        // no reason
        $this->fin()->postJson($url, ['amount' => 500, 'reason' => 'short'])->assertStatus(422);                    // reason too short
        $this->fin()->postJson($url, ['amount' => -5, 'reason' => 'Negative amount attempt.'])->assertStatus(422);  // negative
        $this->fin()->postJson($url, ['amount' => 'abc', 'reason' => 'Non numeric amount.'])->assertStatus(422);
        $this->fin()->postJson($url, ['reason' => 'Nothing to change here.'])->assertStatus(422);                   // no value
        $this->fin()->postJson($url, ['amount' => 1000, 'reason' => 'Identical to current value.'])->assertStatus(422); // unchanged

        $this->assertEquals(1000, $inv->fresh()->amount);
        $this->assertSame(0, FinancialAuditLog::count());
    }

    public function test_completed_or_paid_investments_cannot_be_corrected(): void
    {
        $done = $this->makeInvestment($this->investor, ['status' => 'completed']);
        $paid = $this->makeInvestment($this->investor, ['is_paid' => true]);

        foreach ([$done, $paid] as $inv) {
            $this->fin()->postJson("/api/financial/investments/{$inv->id}/correct", [
                'amount' => 5, 'reason' => 'Trying to alter a closed record.',
            ])->assertStatus(422);
        }
        $this->assertSame(0, FinancialAuditLog::count());
    }

    public function test_investor_and_guest_cannot_correct_records(): void
    {
        $inv = $this->makeInvestment($this->investor);
        $payload = ['amount' => 5000, 'reason' => 'Investor trying to inflate own record.'];

        $this->actingAs($this->investor, 'sanctum')->postJson("/api/financial/investments/{$inv->id}/correct", $payload)->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/financial/investments/{$inv->id}/correct", $payload)->assertStatus(401);
        $this->assertEquals(1000, $inv->fresh()->amount);
    }

    public function test_financial_cannot_use_admin_endpoints_to_move_money_or_change_status(): void
    {
        $this->fin()->postJson("/api/admin/users/{$this->investor->id}/balance", ['type' => 'add', 'amount' => 999, 'reason' => 'x'])->assertStatus(403);
        $this->fin()->postJson("/api/admin/users/{$this->investor->id}/freeze")->assertStatus(403);
        $this->fin()->postJson('/api/admin/investments/1/countdown/extend', ['days' => 5])->assertStatus(403);
        $this->fin()->postJson('/api/financial/investments/1/countdown/extend', ['days' => 5])->assertStatus(404);
    }

    public function test_financial_wallet_action_is_add_deduct_only_and_scoped_to_investors(): void
    {
        $url = "/api/financial/investors/{$this->investor->id}/balance";

        // freeze / reset are no longer reachable from the financial route
        $this->fin()->postJson($url, ['type' => 'reset', 'reason' => 'Trying to wipe balance'])->assertStatus(422);
        $this->fin()->postJson($url, ['type' => 'freeze', 'reason' => 'Trying to freeze account'])->assertStatus(422);
        $this->assertEquals(1000, $this->investor->fresh()->balance);
        $this->assertSame('active', $this->investor->fresh()->status ?? 'active');

        // valid add is audited and in the ledger
        $this->fin()->postJson($url, ['type' => 'add', 'amount' => 50, 'reason' => 'Manual profit credit'])->assertOk();
        $this->assertEquals(1050, $this->investor->fresh()->balance);
        $this->assertSame(1, BalanceAdjustment::where('user_id', $this->investor->id)->count());
        $this->assertSame(1, FinancialAuditLog::where('action', 'wallet.add')->count());

        // over-deduct blocked
        $this->fin()->postJson($url, ['type' => 'deduct', 'amount' => 999999, 'reason' => 'Way too much'])->assertStatus(422);

        // reason mandatory
        $this->fin()->postJson($url, ['type' => 'add', 'amount' => 5])->assertStatus(422);

        // cannot target an admin or another staff account
        $this->fin()->postJson("/api/financial/investors/{$this->admin->id}/balance", ['type' => 'add', 'amount' => 5, 'reason' => 'Targeting an admin'])->assertStatus(404);
        $this->fin()->getJson("/api/financial/investors/{$this->admin->id}")->assertStatus(404);
    }

    public function test_investor_overview_exposes_only_financial_fields(): void
    {
        $this->makeInvestment($this->investor);
        $this->makeDeposit($this->investor, 'approved', 300);

        $res = $this->fin()->getJson("/api/financial/investors/{$this->investor->id}")->assertOk();
        $res->assertJsonPath('user.name', 'Ivy Investor');
        $res->assertJsonPath('user.total_invested', 1000);
        $res->assertJsonPath('user.total_deposited', 300);
        $res->assertJsonStructure(['user' => ['investor_id'], 'investments', 'deposits', 'withdrawals', 'balance_history', 'audit_history']);

        $flat = json_encode($res->json());
        foreach (['id_number', 'id_document_path', 'selfie_path', 'withdrawal_pin', 'password', 'address'] as $secret) {
            $this->assertStringNotContainsString("\"{$secret}\"", $flat);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // AUDIT TRAIL
    // ═══════════════════════════════════════════════════════════════

    public function test_audit_records_are_immutable(): void
    {
        $log = app(\App\Services\FinancialAuditService::class)->record(
            $this->financial, 'test.action', 'deposit', 1, $this->investor->id, ['a' => 1], ['a' => 2], 'because'
        );

        $this->expectException(\LogicException::class);
        $log->update(['reason' => 'tampered']);
    }

    public function test_audit_records_cannot_be_deleted(): void
    {
        $log = app(\App\Services\FinancialAuditService::class)->record($this->financial, 'test.action', 'deposit', 1);
        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_no_write_endpoints_exist_for_audit_logs(): void
    {
        $this->fin()->postJson('/api/financial/audit-logs', [])->assertStatus(405);
        $this->fin()->putJson('/api/financial/audit-logs/1', [])->assertStatus(404);
        $this->fin()->deleteJson('/api/financial/audit-logs/1')->assertStatus(404);
    }

    public function test_audit_log_endpoint_is_read_only_filterable_and_role_gated(): void
    {
        $dep = $this->makeDeposit($this->investor);
        $this->fin()->postJson("/api/financial/deposits/{$dep->id}/approve")->assertOk();

        $res = $this->fin()->getJson("/api/financial/audit-logs?investor_id={$this->investor->id}&action=deposit")->assertOk();
        $this->assertSame('deposit.approved', $res->json('data.0.action'));
        $this->assertSame('Fin Officer', $res->json('data.0.actor_name'));

        $this->actingAs($this->investor, 'sanctum')->getJson('/api/financial/audit-logs')->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════════
    // COMMUNICATION
    // ═══════════════════════════════════════════════════════════════

    public function test_financial_message_to_investor_carries_department_identity(): void
    {
        $this->fin()->postJson("/api/financial/messages/{$this->investor->id}/send", [
            'subject' => 'Your withdrawal', 'body' => 'It has been processed.',
        ])->assertStatus(201)->assertJsonPath('data.sender', 'Smart System Investment — Financial Team');

        $row = Message::firstOrFail();
        $this->assertSame('financial', $row->department);
        $this->assertSame('Smart System Investment — Financial Team', $row->sender_label);
        $this->assertSame($this->financial->id, $row->sender_id);
        $this->assertFalse($row->read_by_investor);

        // investor sees it, identified as Financial Team, flagged unread
        $inv = $this->actingAs($this->investor, 'sanctum');
        $this->assertSame(1, $inv->getJson('/api/investor-investment/messages/unread-count')->json('unread_count'));

        // badge poll does NOT consume the unread state
        $this->assertSame(1, $inv->getJson('/api/investor-investment/messages/unread-count')->json('unread_count'));

        $thread = $inv->getJson('/api/investor-investment/messages')->assertOk();
        $this->assertSame('Smart System Investment — Financial Team', $thread->json('thread.0.sender_label'));
        $this->assertSame('financial', $thread->json('thread.0.department'));
        $this->assertTrue($thread->json('thread.0.was_unread'));
        $this->assertSame(1, $thread->json('unread_count'));

        // opening the thread marks it read
        $this->assertSame(0, $inv->getJson('/api/investor-investment/messages/unread-count')->json('unread_count'));
    }

    public function test_investor_can_write_to_financial_team_and_financial_can_reply(): void
    {
        $this->actingAs($this->investor, 'sanctum')->postJson('/api/investor-investment/messages', [
            'subject' => 'Question about my deposit', 'body' => 'When will it clear?', 'department' => 'financial',
        ])->assertStatus(201)->assertJsonPath('data.department', 'financial');

        $row = Message::firstOrFail();
        $this->assertSame('financial', $row->department);
        $this->assertSame('investor', $row->initiated_by);
        $this->assertFalse($row->read_by_financial);
        $this->assertTrue($row->read_by_admin); // not in the admin support inbox's unread count

        // Financial inbox: unread, then read after opening; identity + status correct
        $inbox = $this->fin()->getJson('/api/financial/messages')->assertOk();
        $this->assertSame(1, $inbox->json('total_unread'));
        $this->assertSame($this->investor->id, $inbox->json('conversations.0.investor.id'));

        $thread = $this->fin()->getJson("/api/financial/messages/{$this->investor->id}")->assertOk();
        $this->assertSame('investor', $thread->json('thread.0.from'));
        $this->assertSame('Ivy Investor', $thread->json('thread.0.sender'));
        $this->assertSame('Smart System Investment — Financial Team', $thread->json('thread.0.recipient'));
        $this->assertSame(0, $this->fin()->getJson('/api/financial/messages/unread-count')->json('unread'));

        // Reply
        $this->fin()->postJson("/api/financial/messages/{$this->investor->id}/send", ['body' => 'Within 24 hours.'])->assertStatus(201);
        $thread = $this->fin()->getJson("/api/financial/messages/{$this->investor->id}");
        $this->assertCount(2, $thread->json('thread'));
        $this->assertSame('delivered', $thread->json('thread.1.status'));

        // Investor sees the whole conversation history in one thread
        $mine = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/messages');
        $this->assertCount(2, $mine->json('thread'));
    }

    public function test_investor_default_target_is_still_support_and_unknown_department_is_rejected(): void
    {
        $this->actingAs($this->investor, 'sanctum')->postJson('/api/investor-investment/messages', ['body' => 'Hello support'])->assertStatus(201);
        $this->assertSame('admin', Message::firstOrFail()->department);

        $this->actingAs($this->investor, 'sanctum')->postJson('/api/investor-investment/messages', ['body' => 'x', 'department' => 'marvflow'])->assertStatus(422);

        // support-addressed mail never appears in the Financial mailbox
        $this->assertCount(0, $this->fin()->getJson('/api/financial/messages')->json('conversations'));
    }

    public function test_financial_mailbox_is_scoped_to_its_own_department_and_searchable(): void
    {
        Message::create(['sender_id' => $this->investor->id, 'receiver_id' => $this->admin->id, 'investor_id' => $this->investor->id,
            'body' => 'support secret', 'initiated_by' => 'investor', 'department' => 'admin', 'read_by_admin' => false, 'read_by_investor' => true]);
        $this->fin()->postJson("/api/financial/messages/{$this->otherInvestor->id}/send", ['subject' => 'Statement', 'body' => 'Your statement is ready'])->assertStatus(201);

        $list = $this->fin()->getJson('/api/financial/messages')->json('conversations');
        $this->assertCount(1, $list);
        $this->assertSame($this->otherInvestor->id, $list[0]['investor']['id']);

        // the support thread is not readable through the financial endpoint
        $t = $this->fin()->getJson("/api/financial/messages/{$this->investor->id}")->assertOk();
        $this->assertCount(0, $t->json('thread'));

        // search by body text and by investor name
        $this->assertCount(1, $this->fin()->getJson('/api/financial/messages?q=statement')->json('conversations'));
        $this->assertCount(1, $this->fin()->getJson('/api/financial/messages?q=Otto')->json('conversations'));
        $this->assertCount(0, $this->fin()->getJson('/api/financial/messages?q=nomatchxyz')->json('conversations'));
    }

    public function test_multi_recipient_notice_selected_and_all(): void
    {
        $this->fin()->postJson('/api/financial/messages/broadcast', [
            'scope' => 'selected', 'investor_ids' => [$this->investor->id, $this->admin->id, 99999],
            'subject' => 'Maintenance window', 'body' => 'Withdrawals paused tonight.', 'kind' => 'notice',
        ])->assertStatus(201)->assertJsonPath('recipient_count', 1); // admin + unknown id dropped server-side

        $this->assertSame(0, Message::where('investor_id', $this->admin->id)->count());

        $res = $this->fin()->postJson('/api/financial/messages/broadcast', [
            'scope' => 'all', 'subject' => 'Policy update', 'body' => 'New fee schedule.',
        ])->assertStatus(201);
        $this->assertSame(2, $res->json('recipient_count')); // both investors, no staff

        $this->assertSame(2, Message::where('broadcast_id', $res->json('broadcast_id'))->count());
        $this->assertSame('notice', Message::where('broadcast_id', $res->json('broadcast_id'))->first()->kind);
        $this->assertSame(2, FinancialAuditLog::where('action', 'communication.broadcast_issued')->count());

        // each investor has a bell notification
        $this->assertGreaterThanOrEqual(1, $this->investor->notifications()->where('type', 'like', '%FinancialAlert%')->count());

        // history with read counts
        $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/messages')->assertOk();
        $hist = $this->fin()->getJson('/api/financial/messages/broadcasts')->assertOk();
        $this->assertCount(2, $hist->json('data'));
        $policy = collect($hist->json('data'))->firstWhere('subject', 'Policy update');
        $this->assertSame(2, $policy['recipients']);
        $this->assertSame(1, $policy['read_count']);
        $this->assertSame('Smart System Investment — Financial Team', $policy['sender_label']);

        $detail = $this->fin()->getJson('/api/financial/messages/broadcasts/' . $res->json('broadcast_id'))->assertOk();
        $this->assertCount(2, $detail->json('recipients'));
    }

    public function test_broadcast_validation(): void
    {
        $this->fin()->postJson('/api/financial/messages/broadcast', ['scope' => 'selected', 'subject' => 's', 'body' => 'b'])->assertStatus(422);
        $this->fin()->postJson('/api/financial/messages/broadcast', ['scope' => 'everyone', 'subject' => 's', 'body' => 'b'])->assertStatus(422);
        $this->fin()->postJson('/api/financial/messages/broadcast', ['scope' => 'all', 'body' => 'b'])->assertStatus(422);
        $this->fin()->postJson('/api/financial/messages/broadcast', ['scope' => 'selected', 'investor_ids' => [99999], 'subject' => 's', 'body' => 'b'])->assertStatus(422);
    }

    public function test_financial_communications_cannot_be_deleted_by_admin(): void
    {
        $this->fin()->postJson("/api/financial/messages/{$this->investor->id}/send", ['body' => 'Official record'])->assertStatus(201);
        $id = Message::firstOrFail()->id;

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/messages/{$id}")->assertStatus(403);
        $this->assertNotNull(Message::find($id));
    }

    public function test_deactivated_investor_cannot_be_messaged(): void
    {
        $gone = $this->makeInvestor('Gone Guy', ['status' => 'deactivated']);
        $this->fin()->postJson("/api/financial/messages/{$gone->id}/send", ['body' => 'hi'])->assertStatus(422);
    }

    // ── News Centre: Financial Team Notice ─────────────────────────────

    public function test_financial_notice_is_published_to_news_centre_with_department_identity(): void
    {
        $res = $this->fin()->postJson('/api/financial/notices', [
            'title' => 'Q3 payout schedule', 'content' => 'Payouts begin Monday.',
            'category' => 'important_notice', 'department' => 'admin', 'is_popup' => true, 'is_featured' => true, // all ignored
        ])->assertStatus(201);

        $a = Announcement::findOrFail($res->json('notice.id'));
        $this->assertSame('financial_notice', $a->category);   // forced
        $this->assertSame('financial', $a->department);        // forced
        $this->assertFalse($a->is_popup);
        $this->assertFalse($a->is_featured);
        $this->assertSame(1, FinancialAuditLog::where('action', 'communication.news_notice_published')->count());

        $feed = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/announcements')->assertOk();
        $item = collect($feed->json('announcements'))->firstWhere('slug', $a->slug);
        $this->assertSame('financial_notice', $item['category']);
        $this->assertSame('Smart System Investment — Financial Team', $item['source']);
    }

    public function test_financial_cannot_touch_other_departments_announcements(): void
    {
        $adminAnn = Announcement::create(['title' => 'Admin news', 'content' => 'x', 'type' => 'info', 'category' => 'company_announcement',
            'status' => 'published', 'published_at' => now(), 'created_by' => $this->admin->id]);

        $this->fin()->postJson("/api/financial/notices/{$adminAnn->id}/unpublish")->assertStatus(404);
        $this->assertSame('published', $adminAnn->fresh()->status);

        $mine = $this->fin()->getJson('/api/financial/notices')->assertOk();
        $this->assertCount(0, $mine->json('notices'));
    }

    // ═══════════════════════════════════════════════════════════════
    // SECURITY BOUNDARIES
    // ═══════════════════════════════════════════════════════════════

    public function test_investor_cannot_reach_any_financial_endpoint(): void
    {
        $dep = $this->makeDeposit($this->investor);
        $inv = $this->makeInvestment($this->investor);
        $as  = fn () => $this->actingAs($this->investor, 'sanctum');

        $as()->getJson('/api/financial/transactions')->assertStatus(403);
        $as()->getJson("/api/financial/transactions/deposit/{$dep->id}")->assertStatus(403);
        $as()->getJson('/api/financial/audit-logs')->assertStatus(403);
        $as()->getJson('/api/financial/messages')->assertStatus(403);
        $as()->postJson("/api/financial/messages/{$this->otherInvestor->id}/send", ['body' => 'impersonating finance'])->assertStatus(403);
        $as()->postJson('/api/financial/messages/broadcast', ['scope' => 'all', 'subject' => 's', 'body' => 'b'])->assertStatus(403);
        $as()->postJson('/api/financial/notices', ['title' => 't', 'content' => 'c'])->assertStatus(403);
        $as()->postJson("/api/financial/deposits/{$dep->id}/approve")->assertStatus(403);
        $as()->postJson("/api/financial/investments/{$inv->id}/correct", ['amount' => 1, 'reason' => 'Attempted tampering'])->assertStatus(403);
        $as()->postJson("/api/financial/investors/{$this->investor->id}/balance", ['type' => 'add', 'amount' => 1000, 'reason' => 'self credit'])->assertStatus(403);

        $this->assertSame('pending', $dep->fresh()->status);
        $this->assertEquals(1000, $this->investor->fresh()->balance);
    }

    public function test_other_staff_roles_cannot_use_financial_endpoints(): void
    {
        $marv = User::factory()->create(['role' => 'marvflow_member']);
        $this->actingAs($marv, 'sanctum')->getJson('/api/financial/transactions')->assertStatus(403);
        $this->actingAs($marv, 'sanctum')->getJson('/api/financial/messages')->assertStatus(403);
    }

    public function test_admin_retains_access_to_financial_endpoints(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/financial/transactions')->assertOk();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/financial/audit-logs')->assertOk();
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/financial/transactions')->assertStatus(401);
        $this->getJson('/api/financial/messages')->assertStatus(401);
        $this->postJson('/api/financial/messages/broadcast', [])->assertStatus(401);
    }

    public function test_existing_admin_message_and_deposit_flows_still_work(): void
    {
        // admin sends support message (unchanged behaviour, default department)
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/messages/{$this->investor->id}/send", ['body' => 'Hello'])->assertStatus(201);
        $this->assertSame('admin', Message::firstOrFail()->department);
        $thread = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/messages');
        $this->assertSame('Smart System Investment — Support Team', $thread->json('thread.0.sender_label'));

        // admin approves a deposit through the admin route: still works + audited under admin
        $dep = $this->makeDeposit($this->investor, 'pending', 100);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/deposits/{$dep->id}/approve")->assertOk();
        $this->assertSame('admin', FinancialAuditLog::where('action', 'deposit.approved')->first()->department);
    }
}
