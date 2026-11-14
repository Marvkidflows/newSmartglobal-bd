<?php
// LOCATION: tests/Feature/Remediation/ApprovalWorkflowHardeningTest.php
// R3 / R7 / R8 / F-14 / F-15 (correction pass). STATUS: written, NOT executed by the author (no PHP in the authoring environment).
// First-run failures here are more likely test-authoring slips than product defects — read the assertion, then the code.

namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\FinancialApprovalEvent;
use App\Models\FinancialApprovalRequest as Req;
use App\Models\FinancialAuditLog;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Services\Financial\FinancialApprovalService;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Rank\FinancialTestCase;

class ApprovalWorkflowHardeningTest extends FinancialTestCase
{
    protected User $admin2; protected User $fin2; protected User $inv;

    protected function setUp(): void
    {
        parent::setUp();
        // Test isolation: AccountStatusNotification hard-codes ->mailer('brevo'), which bypasses MAIL_MAILER=array. Without this, suspend/freeze/unfreeze
        // try a REAL Brevo send (fails offline with a 500; would email real addresses online).
        \Illuminate\Support\Facades\Mail::fake();
        $this->admin2 = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->fin2   = User::factory()->create(['role' => 'financial', 'status' => 'active']);
        $this->inv    = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '20000.00']);
    }

    protected function adm(array $body, ?User $by = null, array $h = [], ?User $target = null)
    {
        return $this->actingAs($by ?? $this->admin, 'sanctum')->postJson('/api/admin/users/' . ($target ?? $this->inv)->id . '/balance', $body, $h);
    }
    protected function finAdj(array $body, ?User $by = null, array $h = [])
    {
        return $this->actingAs($by ?? $this->fin, 'sanctum')->postJson("/api/financial/investors/{$this->inv->id}/balance", $body, $h);
    }
    protected function approve(int $id, User $by) { return $this->actingAs($by, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve"); }
    protected function bal(): string { return number_format((float) $this->inv->fresh()->balance, 2, '.', ''); }

    // ── R3 ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_an_excessive_deduction_is_rejected_immediately_on_both_endpoints_and_no_request_exists(): void
    {
        $this->adm(['type' => 'deduct', 'amount' => 25000, 'reason' => 'too much'])->assertStatus(422);
        $this->finAdj(['type' => 'deduct', 'amount' => 25000, 'reason' => 'too much here'])->assertStatus(422);
        $this->assertSame(0, Req::count());
        $this->assertSame('20000.00', $this->bal());
    }

    public function test_a_valid_large_deduction_is_queued_without_touching_the_balance_and_revalidated_at_execution(): void
    {
        $id = $this->adm(['type' => 'deduct', 'amount' => 15000, 'reason' => 'large correction'])->assertStatus(202)->json('approval_request_id');
        $this->assertSame('20000.00', $this->bal());

        $this->inv->forceFill(['balance' => '5000.00'])->save();                          // wallet changed while the request waited
        $this->approve($id, $this->admin2)->assertStatus(422);
        $this->assertSame('5000.00', $this->bal());
        $r = Req::find($id);
        $this->assertSame('failed', $r->status);
        $this->assertNotEmpty($r->last_error);
        $this->assertSame(0, BalanceAdjustment::count());
    }

    // ── F-14 ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_nobody_can_adjust_their_own_account(): void
    {
        $this->adm(['type' => 'add', 'amount' => 5, 'reason' => 'self credit'], $this->admin, [], $this->admin)->assertStatus(422);
        $this->assertSame(0, BalanceAdjustment::count());
    }

    public function test_splitting_a_large_adjustment_into_small_ones_is_caught_by_the_cumulative_window(): void
    {
        config(['financial_controls.balance_threshold_usd' => '1000', 'financial_controls.balance_cumulative_threshold_usd' => '']);   // empty ⇒ reuse 1000
        $this->adm(['type' => 'add', 'amount' => 400, 'reason' => 'part one'])->assertOk();
        $this->adm(['type' => 'add', 'amount' => 400, 'reason' => 'part two'])->assertOk();
        $this->adm(['type' => 'add', 'amount' => 400, 'reason' => 'part three'])->assertStatus(202);       // 1200 in the window
        $this->assertSame('20800.00', $this->bal());
    }

    // ── R8: idempotency, duplicates, history ─────────────────────────────────────────────────────────────────────────────────────────
    public function test_an_idempotency_key_cannot_return_a_request_for_a_different_payload(): void
    {
        $h = ['Idempotency-Key' => 'key-abc-1'];
        $a = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'], null, $h)->assertStatus(202)->json('approval_request_id');
        $again = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'], null, $h)->assertStatus(202);
        $this->assertSame($a, $again->json('approval_request_id'));
        $this->assertTrue($again->json('duplicate'));
        $this->adm(['type' => 'reset', 'reason' => 'A completely different reason'], null, $h)->assertStatus(409);
        $this->assertSame(1, Req::count());
    }

    public function test_an_identical_pending_request_is_not_duplicated(): void
    {
        $a = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'])->assertStatus(202)->json('approval_request_id');
        $b = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'])->assertStatus(202)->json('approval_request_id');
        $this->assertSame($a, $b);
        $this->assertSame(1, Req::count());
    }

    public function test_the_payload_hash_survives_a_json_round_trip_with_reordered_nested_keys(): void
    {
        $p = ['b' => ['z' => 1, 'a' => ['y' => '1.00', 'x' => true]], 'a' => [3, 1, 2], 'c' => 'x'];
        $q = ['c' => 'x', 'a' => [3, 1, 2], 'b' => ['a' => ['x' => true, 'y' => '1.00'], 'z' => 1]];
        $this->assertSame(FinancialApprovalService::hash($p), FinancialApprovalService::hash($q));
        $this->assertNotSame(FinancialApprovalService::hash($p), FinancialApprovalService::hash(['b' => $p['b'], 'a' => [1, 2, 3], 'c' => 'x']));   // list order matters
    }

    public function test_the_full_history_of_a_request_is_recorded_in_order(): void
    {
        $id = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'])->assertStatus(202)->json('approval_request_id');
        $this->approve($id, $this->admin2)->assertOk();
        $events = FinancialApprovalEvent::where('approval_request_id', $id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['submitted', 'approved', 'execution_started', 'executed'], $events);
        $r = Req::find($id);
        $this->assertSame('executed', $r->status);
        $this->assertSame($this->admin2->id, (int) $r->decided_by);
        $this->assertNull($r->open_key);
        $show = $this->actingAs($this->admin2, 'sanctum')->getJson("/api/financial/approvals/{$id}")->assertOk();
        $this->assertCount(4, $show->json('request.events'));
        $this->assertSame('executed', $show->json('request.status'));
    }

    public function test_the_events_table_is_append_only(): void
    {
        $id = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'])->json('approval_request_id');
        $e = FinancialApprovalEvent::where('approval_request_id', $id)->firstOrFail();
        $this->expectException(\LogicException::class);
        $e->update(['note' => 'tampered']);
    }

    // ── R8: cancel / expiry / re-validation / retry / recovery ───────────────────────────────────────────────────────────────────────
    public function test_requester_can_cancel_a_pending_request_and_it_can_no_longer_be_approved(): void
    {
        $id = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'])->json('approval_request_id');
        $this->actingAs($this->fin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/cancel", ['reason' => 'not my request'])->assertStatus(422);   // other non-admin
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/financial/approvals/{$id}/cancel", ['reason' => 'raised in error'])->assertOk();
        $this->assertSame('cancelled', Req::find($id)->status);
        $this->approve($id, $this->admin2)->assertStatus(422);
        $this->assertSame('20000.00', $this->bal());
        // after cancellation an identical request may be submitted again
        $this->adm(['type' => 'reset', 'reason' => 'Account closure review'])->assertStatus(202);
    }

    public function test_an_expired_request_cannot_be_approved(): void
    {
        config(['financial_controls.approval_expiry_hours' => 1]);
        $id = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'])->json('approval_request_id');
        $this->travel(2)->hours();
        $this->approve($id, $this->admin2)->assertStatus(422);
        $this->assertSame('expired', Req::find($id)->status);
        $this->assertSame('20000.00', $this->bal());
    }

    public function test_expiry_is_disabled_by_default(): void
    {
        $id = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'])->json('approval_request_id');
        $this->assertNull(Req::find($id)->expires_at);
        $this->travel(30)->days();
        $this->assertSame(0, app(FinancialApprovalService::class)->expireDue());
    }

    public function test_a_suspended_approver_or_requester_cannot_complete_a_request(): void
    {
        $id = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'])->json('approval_request_id');
        $svc = app(FinancialApprovalService::class);

        $this->admin2->forceFill(['status' => 'suspended'])->save();
        try { $svc->approve($id, $this->admin2); $this->fail('suspended approver accepted'); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertTrue(true); }
        $this->admin2->forceFill(['status' => 'active'])->save();

        $this->admin->forceFill(['status' => 'frozen'])->save();                              // requester no longer active staff
        try { $svc->approve($id, $this->admin2); $this->fail('executed for a restricted requester'); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertTrue(true); }
        $this->assertSame('pending', Req::find($id)->status);
        $this->assertSame('20000.00', $this->bal());
    }

    public function test_a_failed_request_can_be_retried_once_the_blocker_is_resolved(): void
    {
        $id = $this->adm(['type' => 'deduct', 'amount' => 15000, 'reason' => 'large correction'])->json('approval_request_id');
        $this->inv->forceFill(['balance' => '100.00'])->save();
        $this->approve($id, $this->admin2)->assertStatus(422);
        $this->assertSame('failed', Req::find($id)->status);
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/retry")->assertStatus(422);     // still blocked
        $this->assertSame(2, (int) Req::find($id)->execution_attempts);

        $this->inv->forceFill(['balance' => '20000.00'])->save();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/financial/approvals/{$id}/retry")->assertStatus(422);       // the requester cannot retry their own
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/retry")->assertOk();
        $this->assertSame('executed', Req::find($id)->status);
        $this->assertSame('5000.00', $this->bal());
        $this->approve($id, $this->fin2)->assertStatus(422);                                                                 // decided once
    }

    public function test_recovery_reconciles_legacy_approved_rows_without_guessing_about_money(): void
    {
        $a = $this->adm(['type' => 'deduct', 'amount' => 15000, 'reason' => 'legacy never executed'])->json('approval_request_id');
        $b = $this->adm(['type' => 'deduct', 'amount' => 16000, 'reason' => 'legacy already executed'])->json('approval_request_id');
        BalanceAdjustment::create(['user_id' => $this->inv->id, 'admin_id' => $this->admin->id, 'type' => 'deduct', 'amount' => '16000.00', 'balance_before' => '20000.00',
            'balance_after' => '4000.00', 'reason' => 'legacy already executed', 'approval_request_id' => $b, 'approved_by' => $this->admin2->id]);
        Req::whereIn('id', [$a, $b])->update(['status' => 'approved', 'updated_at' => now()->subHour()]);

        Artisan::call('ssi:approvals-maintain', ['--dry-run' => true]);
        $this->assertSame('approved', Req::find($a)->status);                              // dry run changes nothing

        Artisan::call('ssi:approvals-maintain');
        $this->assertSame('failed', Req::find($a)->status);
        $this->assertSame('executed', Req::find($b)->status);
        $this->assertSame(1, BalanceAdjustment::count());                                  // nothing was executed or re-executed by recovery
    }

    // ── R7: correction cannot bypass approval; other direct writers ────────────────────────────────────────────────────────────────
    public function test_no_alternate_route_changes_an_investment_amount_or_rate_without_approval(): void
    {
        $inv = InvestmentAccount::create(['user_id' => $this->inv->id, 'investment_plan_id' => $this->plan(), 'amount' => 1000, 'profit_percentage' => 10, 'expected_profit' => 100,
            'total_return' => 1100, 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(), 'remaining_days' => 30, 'status' => 'active']);
        foreach ([$this->fin, $this->admin] as $who) {
            $this->actingAs($who, 'sanctum')->postJson("/api/financial/investments/{$inv->id}/correct",
                ['profit_percentage' => 500, 'reason' => 'Attempting a rate change.', 'authorization_reference' => 'CASE-77'])->assertStatus(202);
        }
        $this->assertSame(1, Req::where('operation', 'investment_correction')->count());       // the second identical request returned the first (dedupe)
        $fresh = $inv->fresh();
        $this->assertEquals(10, $fresh->profit_percentage);
        $this->assertEquals(1000, $fresh->amount);
        $this->assertEquals(100, $fresh->expected_profit);
    }

    public function test_the_profit_cap_when_configured_is_enforced_and_absent_by_default(): void
    {
        $inv = InvestmentAccount::create(['user_id' => $this->inv->id, 'investment_plan_id' => $this->plan(), 'amount' => 1000, 'profit_percentage' => 10, 'expected_profit' => 100,
            'total_return' => 1100, 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(), 'remaining_days' => 30, 'status' => 'active']);
        $body = ['profit_percentage' => 50, 'reason' => 'Rate correction request.', 'authorization_reference' => 'CASE-78'];
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/investments/{$inv->id}/correct", $body)->assertStatus(202);
        config(['financial_controls.profit_percentage_cap' => '40']);
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/investments/{$inv->id}/correct", ['profit_percentage' => 45] + $body)->assertStatus(422);
    }

    // ── F-15 ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_frozen_staff_and_every_non_active_status_are_refused_on_ordinary_authenticated_endpoints(): void
    {
        foreach (['frozen', 'suspended', 'deactivated'] as $status) {                       // the enum's non-active values; inactive/unknown: StaffAccountStatusTest
            $staff = User::factory()->create(['role' => 'financial', 'status' => 'active']);
            $staff->forceFill(['status' => $status])->save();
            $this->actingAs($staff->fresh(), 'sanctum')->getJson('/api/user')->assertStatus(403)->assertJsonPath('code', 'account_restricted');
        }
        $this->actingAs($this->fin, 'sanctum')->getJson('/api/user')->assertOk();
        $inv = User::factory()->create(['role' => 'investor', 'status' => 'active']);
        $inv->forceFill(['status' => 'frozen'])->save();
        $this->assertNotSame(403, $this->actingAs($inv->fresh(), 'sanctum')->getJson('/api/user')->getStatusCode());   // investors keep their own restriction model
    }

    public function test_unfreeze_cannot_reactivate_a_suspended_account(): void
    {
        $this->inv->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/users/{$this->inv->id}/unfreeze")->assertStatus(422);
        $this->assertSame('suspended', $this->inv->fresh()->status);
        $this->adm(['type' => 'unfreeze', 'reason' => 'trying through adjust'])->assertStatus(422);
        $this->assertSame('suspended', $this->inv->fresh()->status);

        $this->inv->forceFill(['status' => 'frozen'])->save();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/users/{$this->inv->id}/unfreeze")->assertOk();
        $this->assertSame('active', $this->inv->fresh()->status);
    }

    // ── R2 / F-12 / KYC ──────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_kyc_state_cannot_be_mass_assigned(): void
    {
        $this->strictMassAssignment(true);   // only here: strict mode elsewhere would also trip on any pre-existing silent discard in unrelated app code
        foreach (['kyc_status' => 'approved', 'kyc_verified' => true, 'kyc_verified_at' => now()] as $k => $v) {
            try { $this->inv->update([$k => $v]); $this->fail("$k was mass-assignable"); } catch (\Illuminate\Database\Eloquent\MassAssignmentException) { $this->assertTrue(true); }
        }
    }

    // ── task completion: exactly once + audited ───────────────────────────────────────────────────────────────────────────────────────
    public function test_applySigned_is_exactly_once_audited_and_keeps_the_documented_negative_loss_rule(): void
    {
        $svc = app(\App\Services\Financial\BalanceAdjustmentService::class);
        $svc->applySigned($this->inv->id, '135.00', 'Task T-1 result: profit', $this->admin, 'task-assignment-1', true);
        $again = $svc->applySigned($this->inv->id, '135.00', 'Task T-1 result: profit', $this->admin, 'task-assignment-1', true);
        $this->assertTrue($again['duplicate']);
        $this->assertSame('20135.00', $this->bal());
        $this->assertSame(1, FinancialAuditLog::where('action', 'wallet.add')->count());

        $svc->applySigned($this->inv->id, '-25000.00', 'Task T-2 result: loss', $this->admin, 'task-assignment-2', true);   // verified loss may go negative
        $this->assertSame('-4865.00', $this->bal());
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $svc->applySigned($this->inv->id, '-1.00', 'not a task loss', $this->admin, 'x-3', false);                         // …but nothing else may
    }

    // ── R4/R8: a duplicate of an ALREADY-DECIDED request must not be reported as "awaiting approval" ─────────────────────────────────
    public function test_resubmitting_a_decided_request_reports_its_real_state_not_pending(): void
    {
        $h = ['Idempotency-Key' => 'decided-dup-key-1'];
        $id = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'], null, $h)->assertStatus(202)->json('approval_request_id');
        $this->approve($id, $this->admin2)->assertOk();

        $again = $this->adm(['type' => 'reset', 'reason' => 'Account closure review'], null, $h)->assertOk();     // 200, not 202
        $this->assertFalse($again->json('pending_approval'));
        $this->assertSame('executed', $again->json('status'));
        $this->assertSame($id, $again->json('approval_request_id'));
        $this->assertTrue($again->json('duplicate'));
        $this->assertSame(1, Req::count());
    }

    // ── R4: the profit-history payload carries what the reversal UI needs ───────────────────────────────────────────────────────────
    public function test_profit_history_exposes_status_and_a_pending_reversal(): void
    {
        InvestmentAccount::create(['user_id' => $this->inv->id, 'investment_plan_id' => $this->plan(), 'amount' => 1000, 'profit_percentage' => 10, 'expected_profit' => 100,
            'total_return' => 1100, 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(), 'remaining_days' => 30, 'status' => 'active']);
        $p = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/global/profit-adjustments', ['scope' => 'global', 'percentage_change' => 2,
            'reason' => 'Board-approved rate revision', 'authorization_reference' => 'BOARD-9'])->assertStatus(202)->json('approval_request_id');
        $this->approve($p, $this->admin2)->assertOk();

        $row = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/global/profit-adjustments')->assertOk()->json('history.0');
        $this->assertSame('applied', $row['status']);
        $this->assertSame('BOARD-9', $row['authorization_reference']);
        $this->assertNull($row['reversal_pending_request_id']);

        $r = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/global/profit-adjustments/{$row['id']}/reverse", ['reason' => 'Authorisation withdrawn by Board'])
            ->assertStatus(202)->json('approval_request_id');
        $after = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/global/profit-adjustments')->json('history.0');
        $this->assertSame($r, $after['reversal_pending_request_id']);

        $this->approve($r, $this->admin2)->assertOk();
        $done = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/global/profit-adjustments')->json('history.0');
        $this->assertSame('reversed', $done['status']);
        $this->assertNull($done['reversal_pending_request_id']);
    }

    public function test_an_undeterminable_interrupted_request_can_never_be_retried(): void
    {
        $svc = app(FinancialApprovalService::class);
        $r = $svc->submit('bulk_balance', ['scope' => 'all', 'type' => 'add', 'amount_type' => 'fixed', 'amount' => '5.00', 'reason' => 'legacy bulk, unknown state'], $this->admin);
        Req::where('id', $r->id)->update(['status' => 'approved', 'updated_at' => now()->subHour()]);

        Artisan::call('ssi:approvals-maintain');
        $row = Req::find($r->id);
        $this->assertSame('failed', $row->status);
        $this->assertTrue((bool) ($row->result['needs_manual_verification'] ?? false));
        $this->assertStringContainsString('MANUAL VERIFICATION REQUIRED', $row->last_error);

        $show = $this->actingAs($this->admin2, 'sanctum')->getJson("/api/financial/approvals/{$r->id}")->assertOk();
        $this->assertTrue($show->json('request.needs_manual_verification'));
        $this->assertFalse($show->json('request.can_retry'));
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$r->id}/retry")->assertStatus(422);
        $this->assertSame('failed', Req::find($r->id)->status);
        $this->assertSame(0, BalanceAdjustment::count());                                    // retry refused ⇒ nothing executed
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/financial/approvals/{$r->id}/cancel", ['reason' => 'verified manually'])->assertOk();   // the safe exit
    }

    public function test_an_administrator_cannot_suspend_freeze_or_deactivate_their_own_account(): void
    {
        foreach (['suspend', 'freeze', 'deactivate'] as $action) {
            $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/users/{$this->admin->id}/{$action}")->assertStatus(422);
        }
        $this->assertSame('active', $this->admin->fresh()->status);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/users/{$this->inv->id}/freeze")->assertOk();     // other accounts: unchanged behaviour
        $this->assertSame('frozen', $this->inv->fresh()->status);
    }
}
