<?php
// LOCATION: tests/Feature/Remediation/ProfitAdjustmentControlsTest.php
// F-04: per-investment before/after records, preview, second approval, documented authorisation, reversal; and rank activity never triggers it.
// STATUS: written, NOT executed by the author.

namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\InvestmentAccount;
use App\Models\ProfitAdjustment;
use App\Models\ProfitAdjustmentItem;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Rank\FinancialTestCase;

class ProfitAdjustmentControlsTest extends FinancialTestCase
{
    protected User $admin2; protected array $inv = []; protected int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin2 = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->planId = $this->plan(1, 30, 10);
        foreach ([1000, 2000, 3000] as $amt) {
            $u = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '0.00']);
            $this->inv[] = InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $this->planId, 'amount' => $amt, 'profit_percentage' => 10,
                'expected_profit' => $amt / 10, 'total_return' => $amt * 1.1, 'status' => 'active', 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(), 'remaining_days' => 30]);
        }
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active']);
        $this->inv[] = InvestmentAccount::create(['user_id' => $u->id, 'investment_plan_id' => $this->planId, 'amount' => 500, 'profit_percentage' => 10, 'expected_profit' => 50, 'total_return' => 550,
            'status' => 'completed', 'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(10)->toDateString(), 'remaining_days' => 0]);
    }

    protected function body(array $over = []): array
    {
        return $over + ['scope' => 'global', 'percentage_change' => 2, 'reason' => 'Board-approved rate revision', 'authorization_reference' => 'BOARD-2026-11-001'];
    }
    protected function submit(User $by, array $b) { return $this->actingAs($by, 'sanctum')->postJson('/api/admin/global/profit-adjustments', $b); }
    protected function snapshot(): array { return InvestmentAccount::orderBy('id')->get(['id', 'profit_percentage', 'expected_profit', 'total_return'])->toArray(); }

    public function test_preview_lists_each_investment_before_and_after_and_writes_nothing(): void
    {
        $before = $this->snapshot();
        $r = $this->submit($this->admin, $this->body(['preview' => true]))->assertOk();
        $this->assertSame(3, $r->json('will_change'), 'completed investments are never in scope');
        $this->assertSame('10.00', $r->json('rows.0.before_pct'));
        $this->assertSame('12.00', $r->json('rows.0.after_pct'));
        $this->assertSame('120.00', $r->json('rows.0.after_expected'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, ProfitAdjustment::count());
    }

    public function test_documented_authorisation_is_mandatory(): void
    {
        $b = $this->body(); unset($b['authorization_reference']);
        $this->submit($this->admin, $b)->assertStatus(422);
        $this->assertSame(0, ProfitAdjustment::count());
    }

    public function test_submitting_changes_nothing_and_the_requester_cannot_approve(): void
    {
        $before = $this->snapshot();
        $id = $this->submit($this->admin, $this->body())->assertStatus(202)->json('approval_request_id');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, ProfitAdjustment::count());
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertStatus(422);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_approval_records_every_affected_investment_with_before_and_after_values(): void
    {
        $id = $this->submit($this->admin, $this->body())->json('approval_request_id');
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertOk();

        $adj = ProfitAdjustment::firstOrFail();
        $this->assertSame(3, (int) $adj->affected_count);
        $this->assertSame($this->admin->id, (int) $adj->admin_id);
        $this->assertSame($this->admin2->id, (int) $adj->approved_by);
        $this->assertSame('BOARD-2026-11-001', $adj->authorization_reference);
        $this->assertSame('applied', $adj->status);

        $items = ProfitAdjustmentItem::where('profit_adjustment_id', $adj->id)->orderBy('investment_id')->get();
        $this->assertCount(3, $items);
        foreach ($items as $it) {
            $this->assertSame('10.00', number_format((float) $it->before_profit_percentage, 2, '.', ''));
            $this->assertSame('12.00', number_format((float) $it->after_profit_percentage, 2, '.', ''));
            $inv = InvestmentAccount::find($it->investment_id);
            // SQLite hands back 120 for a DECIMAL(15,2) column where MySQL returns 120.00, so compare as exact 2-decimal strings
            $this->assertSame(number_format((float) $it->after_expected_profit, 2, '.', ''), number_format((float) $inv->expected_profit, 2, '.', ''));
            $this->assertSame(number_format((float) $it->after_total_return, 2, '.', ''), number_format((float) $inv->total_return, 2, '.', ''));
        }
        $this->assertSame('120.00', (string) InvestmentAccount::find($this->inv[0]->id)->expected_profit);
        $this->assertSame('10.00', (string) InvestmentAccount::find($this->inv[3]->id)->profit_percentage, 'completed investment untouched');
    }

    public function test_the_profit_floor_is_zero_percent(): void
    {
        $id = $this->submit($this->admin, $this->body(['percentage_change' => -50]))->json('approval_request_id');
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertOk();
        $this->assertSame('0.00', (string) InvestmentAccount::find($this->inv[0]->id)->profit_percentage);
        $this->assertSame('0.00', (string) InvestmentAccount::find($this->inv[0]->id)->expected_profit);
        $this->assertSame('1000.00', (string) InvestmentAccount::find($this->inv[0]->id)->total_return);
    }

    public function test_reversal_restores_the_recorded_before_values_and_cannot_be_repeated(): void
    {
        $before = $this->snapshot();
        $id = $this->submit($this->admin, $this->body())->json('approval_request_id');
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertOk();
        $adj = ProfitAdjustment::firstOrFail();

        $rid = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/global/profit-adjustments/{$adj->id}/reverse", ['reason' => 'Authorisation withdrawn by Board'])->assertStatus(202)->json('approval_request_id');
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$rid}/approve")->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('reversed', $adj->fresh()->status);
        $this->assertSame(0, ProfitAdjustmentItem::where('profit_adjustment_id', $adj->id)->whereNull('reversed_at')->count());

        $rid2 = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/global/profit-adjustments/{$adj->id}/reverse", ['reason' => 'Second attempt should fail'])->json('approval_request_id');
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$rid2}/approve")->assertStatus(422);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_reversal_never_overwrites_a_later_change_and_reports_the_conflict(): void
    {
        $id = $this->submit($this->admin, $this->body())->json('approval_request_id');
        $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$id}/approve")->assertOk();
        $adj = ProfitAdjustment::firstOrFail();
        InvestmentAccount::whereKey($this->inv[1]->id)->update(['profit_percentage' => 15]);       // a later, separate change

        $rid = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/global/profit-adjustments/{$adj->id}/reverse", ['reason' => 'Partial reversal check'])->json('approval_request_id');
        $res = $this->actingAs($this->admin2, 'sanctum')->postJson("/api/financial/approvals/{$rid}/approve")->assertOk();
        $this->assertSame('partially_reversed', $res->json('result.status'));
        $this->assertSame(1, count($res->json('result.conflicts')));
        $this->assertSame('15.00', (string) InvestmentAccount::find($this->inv[1]->id)->profit_percentage, 'the later change is preserved');
        $this->assertSame('10.00', (string) InvestmentAccount::find($this->inv[0]->id)->profit_percentage);
    }

    public function test_only_admin_can_start_a_profit_adjustment(): void
    {
        $this->submit($this->fin, $this->body())->assertStatus(403);
        $this->submit(User::find($this->inv[0]->user_id), $this->body())->assertStatus(403);
    }

    public function test_rank_evaluation_never_adjusts_profit_or_balances(): void
    {
        $before = $this->snapshot();
        $balances = User::orderBy('id')->pluck('balance', 'id')->toArray();
        Artisan::call('ranks:evaluate');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($balances, User::orderBy('id')->pluck('balance', 'id')->toArray());
        $this->assertSame(0, ProfitAdjustment::count());
        $this->assertSame(0, BalanceAdjustment::count());
    }
}
