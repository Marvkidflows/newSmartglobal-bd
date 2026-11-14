<?php
// LOCATION: tests/Feature/Remediation/BalanceAdjustmentControlsTest.php
// F-03 / F-14: locked, decimal, audited balance adjustments; preview; second approval; no self-approval. STATUS: written, NOT executed by the author.

namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\FinancialApprovalRequest;
use App\Models\FinancialAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Rank\FinancialTestCase;

class BalanceAdjustmentControlsTest extends FinancialTestCase
{
    protected User $admin2; protected User $fin2; protected User $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin2 = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->fin2   = User::factory()->create(['role' => 'financial', 'status' => 'active']);
        $this->inv    = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '100.00']);
    }

    protected function bal(User $u): string { return number_format((float) $u->fresh()->balance, 2, '.', ''); }
    protected function adminAdjust(User $by, array $body, array $headers = []) { return $this->actingAs($by, 'sanctum')->postJson("/api/admin/users/{$this->inv->id}/balance", $body, $headers); }

    public function test_add_records_admin_reason_before_after_and_an_audit_row_with_exact_decimals(): void
    {
        $this->adminAdjust($this->admin, ['type' => 'add', 'amount' => 25.50, 'reason' => 'Goodwill credit'])->assertOk();
        $this->assertSame('125.50', $this->bal($this->inv));
        $a = BalanceAdjustment::where('user_id', $this->inv->id)->firstOrFail();
        $this->assertSame($this->admin->id, (int) $a->admin_id);
        $this->assertSame('100.00', (string) $a->balance_before);
        $this->assertSame('125.50', (string) $a->balance_after);
        $this->assertSame('Goodwill credit', $a->reason);
        $this->assertNotNull($a->created_at);
        $this->assertSame(1, FinancialAuditLog::where('entity_type', 'balance_adjustment')->where('entity_id', $a->id)->count());
    }

    public function test_ten_adds_of_ten_cents_equal_exactly_one_dollar(): void
    {
        for ($i = 0; $i < 10; $i++) $this->adminAdjust($this->admin, ['type' => 'add', 'amount' => 0.10, 'reason' => "tick {$i}"])->assertOk();
        $this->assertSame('101.00', $this->bal($this->inv));
    }

    public function test_deducting_more_than_the_balance_is_refused_and_changes_nothing(): void
    {
        $this->adminAdjust($this->admin, ['type' => 'deduct', 'amount' => 100.01, 'reason' => 'too much'])->assertStatus(422);
        $this->assertSame('100.00', $this->bal($this->inv));
        $this->assertSame(0, BalanceAdjustment::count());
    }

    public function test_a_repeated_idempotency_key_applies_once(): void
    {
        $h = ['Idempotency-Key' => 'adj-dup-key-000001'];
        $this->adminAdjust($this->admin, ['type' => 'add', 'amount' => 5, 'reason' => 'dup test'], $h)->assertOk()->assertJsonPath('duplicate', false);
        $this->adminAdjust($this->admin, ['type' => 'add', 'amount' => 5, 'reason' => 'dup test'], $h)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('105.00', $this->bal($this->inv));
        $this->assertSame(1, BalanceAdjustment::count());
    }

    public function test_reset_needs_a_second_approver_and_changes_nothing_until_then(): void
    {
        $r = $this->adminAdjust($this->admin, ['type' => 'reset', 'reason' => 'Account closure review'])->assertStatus(202)->assertJsonPath('pending_approval', true);
        $id = $r->json('approval_request_id');
        $this->assertSame('100.00', $this->bal($this->inv));
        $this->assertSame(0, BalanceAdjustment::count());

        // the requester cannot approve their own request
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertStatus(422);
        $this->assertSame('100.00', $this->bal($this->inv));

        // a different authorised person can
        $this->actingAs($this->fin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertOk();
        $this->assertSame('0.00', $this->bal($this->inv));
        $a = BalanceAdjustment::firstOrFail();
        $this->assertSame($this->admin->id, (int) $a->admin_id, 'the REQUESTER is the recorded administrator');
        $this->assertSame($this->fin2->id, (int) $a->approved_by);
        $this->assertSame((int) $id, (int) $a->approval_request_id);
        $this->assertSame('executed', FinancialApprovalRequest::find($id)->status);

        // cannot be decided twice
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertStatus(422);
        $this->assertSame(1, BalanceAdjustment::count());
    }

    public function test_rejecting_a_request_changes_nothing(): void
    {
        $id = $this->adminAdjust($this->admin, ['type' => 'reset', 'reason' => 'Account closure review'])->json('approval_request_id');
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/reject", ['note' => 'Not justified'])->assertOk();
        $this->assertSame('100.00', $this->bal($this->inv));
        $this->assertSame('rejected', FinancialApprovalRequest::find($id)->status);
    }

    public function test_the_threshold_routes_large_adjustments_to_approval_and_small_ones_through(): void
    {
        $this->adminAdjust($this->admin, ['type' => 'add', 'amount' => 9999.99, 'reason' => 'just under'])->assertOk();
        $this->adminAdjust($this->admin, ['type' => 'add', 'amount' => 10000.00, 'reason' => 'at threshold'])->assertStatus(202);
    }

    public function test_approval_can_be_switched_off_by_configuration(): void
    {
        config(['financial_controls.dual_approval_enabled' => false]);
        $this->adminAdjust($this->admin, ['type' => 'reset', 'reason' => 'configured off'])->assertOk();
        $this->assertSame('0.00', $this->bal($this->inv));
    }

    public function test_an_approval_that_can_no_longer_execute_fails_safely_and_moves_no_money(): void
    {
        DB::table('users')->where('id', $this->inv->id)->update(['balance' => '20000.00']);
        $id = $this->adminAdjust($this->admin, ['type' => 'deduct', 'amount' => 15000, 'reason' => 'large correction'])->assertStatus(202)->json('approval_request_id');
        DB::table('users')->where('id', $this->inv->id)->update(['balance' => '100.00']);          // the wallet changed meanwhile
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertStatus(422);
        $this->assertSame('100.00', $this->bal($this->inv));
        $this->assertSame('failed', FinancialApprovalRequest::find($id)->status);
    }

    public function test_financial_team_adjustments_are_also_decimal_audited_and_threshold_gated(): void
    {
        $url = "/api/financial/investors/{$this->inv->id}/balance";
        $this->actingAs($this->fin, 'sanctum')->postJson($url, ['type' => 'add', 'amount' => 0.30, 'reason' => 'small fix up'])->assertOk();
        $this->assertSame('100.30', $this->bal($this->inv));
        $id = $this->actingAs($this->fin, 'sanctum')->postJson($url, ['type' => 'add', 'amount' => 20000, 'reason' => 'large credit approved'])->assertStatus(202)->json('approval_request_id');
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertStatus(422);       // own request
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertOk();
        $this->assertSame('20100.30', $this->bal($this->inv));
    }

    public function test_bulk_preview_writes_nothing_and_reports_skips(): void
    {
        $poor = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '5.00']);
        $r = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/global/balance-bulk', [
            'scope' => 'selected', 'user_ids' => [$this->inv->id, $poor->id], 'type' => 'deduct', 'amount_type' => 'fixed', 'amount' => 10, 'reason' => 'preview only', 'preview' => true,
        ])->assertOk();
        $this->assertSame(1, $r->json('will_change'));
        $this->assertSame('insufficient_balance', $r->json('skipped.0.reason'));
        $this->assertSame(0, BalanceAdjustment::count());
        $this->assertSame('100.00', $this->bal($this->inv));
    }

    public function test_bulk_changes_need_a_second_approver_then_apply_atomically_with_full_history(): void
    {
        $b = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '200.00']);
        $payload = ['scope' => 'selected', 'user_ids' => [$this->inv->id, $b->id], 'type' => 'add', 'amount_type' => 'percentage', 'amount' => 10, 'reason' => 'Approved year-end adjustment'];
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/global/balance-bulk', $payload)->assertStatus(202)->json('approval_request_id');
        $this->assertSame('100.00', $this->bal($this->inv)); $this->assertSame(0, BalanceAdjustment::count());

        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertOk();
        $this->assertSame('110.00', $this->bal($this->inv));
        $this->assertSame('220.00', $this->bal($b));
        $this->assertSame(2, BalanceAdjustment::count());
        $this->assertSame(2, BalanceAdjustment::whereNotNull('approved_by')->where('reason', 'Approved year-end adjustment')->count());
    }

    public function test_bulk_deterministic_cap_refuses_oversized_runs(): void
    {
        config(['financial_controls.bulk_max_accounts' => 1, 'financial_controls.dual_approval_enabled' => false]);
        User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '1.00']);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/global/balance-bulk', ['scope' => 'all', 'type' => 'add', 'amount_type' => 'fixed', 'amount' => 1, 'reason' => 'cap check'])->assertStatus(422);
        $this->assertSame(0, BalanceAdjustment::count());
    }

    public function test_role_matrix_for_balance_tools_and_approvals(): void
    {
        $mf = User::factory()->create(['role' => 'marvflow_member', 'status' => 'active']);
        $body = ['type' => 'add', 'amount' => 1, 'reason' => 'matrix check'];
        $this->adminAdjust($this->fin, $body)->assertStatus(403);                       // financial is NOT admin for /api/admin/*
        $this->adminAdjust($this->inv, $body)->assertStatus(403);
        $this->adminAdjust($mf, $body)->assertStatus(403);
        foreach ([$this->inv, $mf] as $who) {
            $this->actingAs($who, 'sanctum')->getJson('/api/financial/approvals')->assertStatus(403);
            $this->actingAs($who, 'sanctum')->postJson('/api/financial/approvals/1/approve')->assertStatus(403);
        }
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/admin/global/balance-bulk", ['scope' => 'all', 'type' => 'add', 'amount_type' => 'fixed', 'amount' => 1, 'reason' => 'matrix check'])->assertStatus(403);
    }
}
