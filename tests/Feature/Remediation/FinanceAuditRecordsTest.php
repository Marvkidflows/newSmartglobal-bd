<?php
// LOCATION: tests/Feature/Remediation/FinanceAuditRecordsTest.php   (NEW — 19 Nov)
// investment.profit_credited and investment.funded_from_wallet audit rows: exactly once, rollback, actor attribution, immutability.
// WRITTEN, NOT EXECUTED. Run: php artisan test --filter=FinanceAuditRecordsTest

namespace Tests\Feature\Remediation;

use App\Models\FinancialAuditLog;
use App\Services\InvestmentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Rank\FinancialTestCase;

class FinanceAuditRecordsTest extends FinancialTestCase
{
    use RefreshDatabase;

    private function dueInvestment($u, float $profit = 10)
    {
        $i = $this->invest($u, 100);
        DB::table('investment_accounts')->where('id', $i->id)->update(['expected_profit' => $profit]);
        return $i->fresh();
    }

    protected function tearDown(): void
    {
        FinancialAuditLog::flushEventListeners();
        FinancialAuditLog::clearBootedModels();
        parent::tearDown();
    }

    // ── profit credit ────────────────────────────────────────────────────────────
    public function test_profit_audit_row_is_written_exactly_once(): void
    {
        $u = $this->cash($this->investor(), 5);
        $i = $this->dueInvestment($u);
        $svc = app(InvestmentSettlementService::class);
        $this->assertTrue($svc->complete($i, 'admin', $this->fin->id)['completed']);
        $this->assertFalse($svc->complete($i, 'admin', $this->fin->id)['completed']);
        $this->assertSame(1, FinancialAuditLog::where('action', 'investment.profit_credited')->where('entity_id', $i->id)->count());
        $this->assertSame(15.0, $this->balance($u));
    }

    public function test_profit_audit_row_attributes_the_staff_actor(): void
    {
        $u = $this->investor();
        $i = $this->dueInvestment($u);
        app(InvestmentSettlementService::class)->complete($i, 'admin', $this->admin->id);
        $row = FinancialAuditLog::where('action', 'investment.profit_credited')->firstOrFail();
        $this->assertSame($this->admin->id, $row->actor_id);
        $this->assertSame('admin', $row->actor_role);
        $this->assertSame('admin', $row->department);
        $j = $this->dueInvestment($this->investor());
        app(InvestmentSettlementService::class)->complete($j, 'admin', $this->fin->id);
        $this->assertSame('financial', FinancialAuditLog::where('entity_id', $j->id)->where('action', 'investment.profit_credited')->value('department'));
    }

    public function test_scheduler_credit_is_attributed_to_the_system(): void
    {
        $i = $this->dueInvestment($this->investor());
        app(InvestmentSettlementService::class)->complete($i, 'schedule', null);
        $row = FinancialAuditLog::where('action', 'investment.profit_credited')->firstOrFail();
        $this->assertNull($row->actor_id);
        $this->assertSame('System (maturity scheduler)', $row->actor_name);
        $this->assertSame('system', $row->actor_role);
        $this->assertSame('system', $row->department);
    }

    public function test_profit_credit_rolls_back_when_the_audit_row_cannot_be_written(): void
    {
        $u = $this->cash($this->investor(), 5);
        $i = $this->dueInvestment($u);
        FinancialAuditLog::creating(fn () => throw new \RuntimeException('audit unavailable'));
        try {
            app(InvestmentSettlementService::class)->complete($i, 'admin', $this->fin->id);
            $this->fail('expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        }
        $this->assertSame('active', $i->fresh()->status);
        $this->assertSame(5.0, $this->balance($u));
    }

    // ── wallet-funded investment debit ───────────────────────────────────────────
    public function test_investment_debit_audit_row_records_investor_actor_and_balances(): void
    {
        $u = $this->cash($this->investor(), 200);
        $this->investFromWallet($u, $this->plan(), 50)->assertSuccessful();
        $row = FinancialAuditLog::where('action', 'investment.funded_from_wallet')->firstOrFail();
        $this->assertSame($u->id, $row->actor_id);
        $this->assertSame('investor', $row->department);
        $this->assertEquals(200, $row->previous_value['balance']);
        $this->assertEquals(150, $row->new_value['balance']);
        $this->assertSame(150.0, $this->balance($u));
        $this->assertSame(1, FinancialAuditLog::where('action', 'investment.funded_from_wallet')->count());
    }

    public function test_investment_debit_rolls_back_when_the_audit_row_cannot_be_written(): void
    {
        $u = $this->cash($this->investor(), 200);
        $plan = $this->plan();
        FinancialAuditLog::creating(fn () => throw new \RuntimeException('audit unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->investFromWallet($u, $plan, 50);
            $this->fail('expected failure');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('audit unavailable', $e->getMessage());
        }
        $this->assertSame(200.0, $this->balance($u));
        $this->assertSame(0, \App\Models\InvestmentAccount::where('user_id', $u->id)->count());
    }

    // ── protection against modification ──────────────────────────────────────────
    public function test_audit_rows_cannot_be_updated_or_deleted(): void
    {
        $i = $this->dueInvestment($this->investor());
        app(InvestmentSettlementService::class)->complete($i, 'admin', $this->fin->id);
        $row = FinancialAuditLog::firstOrFail();
        try { $row->update(['reason' => 'tampered']); $this->fail('update allowed'); } catch (LogicException $e) { $this->assertTrue(true); }
        try { $row->delete(); $this->fail('delete allowed'); } catch (LogicException $e) { $this->assertTrue(true); }
        $this->assertNotSame('tampered', $row->fresh()->reason);
    }

    public function test_no_http_route_modifies_audit_logs(): void
    {
        $i = $this->dueInvestment($this->investor());
        app(InvestmentSettlementService::class)->complete($i, 'admin', $this->fin->id);
        $id = FinancialAuditLog::value('id');
        $before = FinancialAuditLog::count();
        foreach (['putJson', 'patchJson', 'deleteJson'] as $m) {
            foreach (['/api/financial/audit-logs/', '/api/admin/audit-logs/', '/api/financial/audit/'] as $base) {
                $this->assertContains($this->actingAs($this->admin, 'sanctum')->{$m}($base . $id, ['reason' => 'x'])->getStatusCode(), [404, 405]);
            }
        }
        $this->assertSame($before, FinancialAuditLog::count());   // no route changed or removed any audit row
    }
}
