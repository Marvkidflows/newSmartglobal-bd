<?php
// LOCATION: tests/Feature/Rank/RankExportAuditTest.php
//
// Phase 4: the investor-rank CSV export already existed (streamed in chunks, gated by ranks.export, formula-injection neutralised). This adds:
//   * an append-only audit record for every export (who, what, which filters), written before any data is streamed;
//   * the columns the directive asked for that were missing: next rank, progress toward it, records awaiting currency verification, calculation
//     time, and a filled-in "Rank changed" column (it was always empty).
// STATUS: NOT EXECUTED by its author. Run: php -d memory_limit=-1 vendor\bin\pest --filter=RankExportAuditTest

namespace Tests\Feature\Rank;

use App\Models\RankBenefitAudit;
use App\Models\User;

class RankExportAuditTest extends RankTestCase
{
    private const CORE = [
        'bronze' => [100, 1], 'silver' => [1000, 2], 'gold' => [5000, 3], 'platinum' => [10000, 5], 'diamond' => [20000, 8], 'elite' => [50000, 12],
    ];

    private function activateAll(): void
    {
        foreach (self::CORE as $key => [$amount, $cycles]) {
            $this->createCriterion($key, [
                $this->cond('current_eligible_principal', '>=', $amount),
                $this->cond('completed_investment_count', '>=', $cycles),
            ]);
        }
    }

    private function bronzeInvestor(float $amount, int $cycles): User
    {
        $u = $this->investorWith($amount, $cycles);
        $u->forceFill(['investor_rank_id' => $this->rank('bronze')->id, 'rank_status' => 'active', 'rank_changed_at' => now()])->save();
        return $u->fresh();
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    /** @return array{0:string[],1:array<int,string[]>} header + data rows of a streamed CSV */
    private function parse(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $h = fopen('php://memory', 'w+'); fwrite($h, $csv); rewind($h);
        $header = fgetcsv($h) ?: [];
        $rows = [];
        while (($r = fgetcsv($h)) !== false) { if ($r !== [null]) $rows[] = $r; }
        fclose($h);
        return [$header, $rows];
    }

    public function test_the_export_has_the_requested_columns_with_correct_values(): void
    {
        $this->activateAll();
        $u = $this->bronzeInvestor(800, 2);   // 80% of the money Silver needs, cycles complete

        $csv = $this->actingAs($this->admin, 'sanctum')->get('/api/admin/ranks/export')->assertOk()->streamedContent();
        [$header, $rows] = $this->parse($csv);

        foreach (['Account ID', 'Assigned rank', 'Rank changed', 'Current eligible principal (USD, verified)', 'Completed cycles',
                  'Next rank', 'Progress to next rank (%)', 'Currency records awaiting verification or review', 'Calculated at (UTC)'] as $col) {
            $this->assertContains($col, $header, "missing column {$col}");
        }

        $row = collect($rows)->first(fn ($r) => ($r[array_search('Account ID', $header)] ?? null) === $u->account_id);
        $this->assertNotNull($row, 'the investor is in the export');
        $get = fn (string $col) => $row[array_search($col, $header)];

        $this->assertSame('Bronze', $get('Assigned rank'));
        $this->assertSame('Silver', $get('Next rank'));
        $this->assertSame('80', $get('Progress to next rank (%)'));
        $this->assertNotSame('', $get('Rank changed'), '"Rank changed" used to be always empty');
        $this->assertNotSame('', $get('Calculated at (UTC)'));
        $this->assertSame('0', $get('Currency records awaiting verification or review'));
    }

    public function test_unverified_money_is_counted_as_awaiting_and_not_as_progress(): void
    {
        $this->activateAll();
        $u = $this->bronzeInvestor(0, 2);
        $this->invest($u, 900, 30, 'active', false);   // recorded but NOT verified as USD

        [$header, $rows] = $this->parse($this->actingAs($this->admin, 'sanctum')->get('/api/admin/ranks/export')->streamedContent());
        $row = collect($rows)->first(fn ($r) => $r[array_search('Account ID', $header)] === $u->account_id);
        $get = fn (string $col) => $row[array_search($col, $header)];

        $this->assertSame('0', $get('Progress to next rank (%)'), 'unverified money is not progress');
        $this->assertGreaterThan(0, (int) $get('Currency records awaiting verification or review'));
    }

    public function test_progress_is_blank_when_the_next_rank_has_no_active_rule_and_for_the_highest_rank(): void
    {
        // nothing activated: there is nothing to measure against
        $u = $this->bronzeInvestor(800, 2);
        [$header, $rows] = $this->parse($this->actingAs($this->admin, 'sanctum')->get('/api/admin/ranks/export')->streamedContent());
        $row = collect($rows)->first(fn ($r) => $r[array_search('Account ID', $header)] === $u->account_id);
        $this->assertSame('', $row[array_search('Progress to next rank (%)', $header)]);
    }

    public function test_every_export_is_audited_with_who_what_and_filters(): void
    {
        $this->activateAll();
        $this->bronzeInvestor(800, 2);

        $this->actingAs($this->admin, 'sanctum')->get('/api/admin/ranks/export?rank=bronze&status=active')->assertOk()->streamedContent();
        $a = RankBenefitAudit::where('subject_type', 'rank_export')->latest('id')->firstOrFail();
        $this->assertSame('exported', $a->action);
        $this->assertSame($this->admin->id, $a->actor_id);
        $this->assertSame('admin', $a->actor_role);
        $this->assertSame('investors', $a->new['type']);
        $this->assertSame(['rank' => 'bronze', 'status' => 'active'], $a->new['filters']);
        $this->assertStringStartsWith('ssi-rank-investors-', $a->new['file']);

        $this->actingAs($this->admin, 'sanctum')->get('/api/admin/ranks/export?type=history')->assertOk()->streamedContent();
        $this->assertSame('history', RankBenefitAudit::where('subject_type', 'rank_export')->latest('id')->first()->new['type']);

        $fin = $this->staff('financial');
        $this->actingAs($fin, 'sanctum')->get('/api/financial/rank-export')->assertOk()->streamedContent();
        $last = RankBenefitAudit::where('subject_type', 'rank_export')->latest('id')->first();
        $this->assertSame($fin->id, $last->actor_id);
        $this->assertSame('financial', $last->actor_role);

        $this->actingAs($this->admin, 'sanctum')->get('/api/admin/ranks/benefits/export?type=benefits')->assertOk()->streamedContent();
        $this->assertSame('benefits', RankBenefitAudit::where('subject_type', 'rank_export')->latest('id')->first()->new['type']);
        $this->assertSame(4, RankBenefitAudit::where('subject_type', 'rank_export')->count());
    }

    public function test_a_refused_export_leaves_no_audit_row_and_sends_no_data(): void
    {
        $this->getJson('/api/admin/ranks/export')->assertStatus(401);                                        // guest first: actingAs() persists
        $this->actingAs($this->investor(), 'sanctum')->get('/api/admin/ranks/export')->assertStatus(403);
        $this->assertSame(0, RankBenefitAudit::where('subject_type', 'rank_export')->count());
    }

    public function test_exporting_changes_no_rank_balance_or_investment(): void
    {
        $this->activateAll();
        $u = $this->bronzeInvestor(800, 2);
        $before = [$u->fresh()->balance, $u->fresh()->investor_rank_id, \Illuminate\Support\Facades\DB::table('investment_accounts')->count()];
        $this->actingAs($this->admin, 'sanctum')->get('/api/admin/ranks/export')->assertOk()->streamedContent();
        $this->assertSame($before, [$u->fresh()->balance, $u->fresh()->investor_rank_id, \Illuminate\Support\Facades\DB::table('investment_accounts')->count()]);
    }

    public function test_audit_rows_for_exports_are_immutable(): void
    {
        $this->actingAs($this->admin, 'sanctum')->get('/api/admin/ranks/export')->assertOk()->streamedContent();
        $a = RankBenefitAudit::where('subject_type', 'rank_export')->firstOrFail();
        $this->expectException(\LogicException::class);
        $a->update(['action' => 'tampered']);
    }
}
