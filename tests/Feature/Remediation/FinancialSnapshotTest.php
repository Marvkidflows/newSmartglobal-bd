<?php
// LOCATION: tests/Feature/Remediation/FinancialSnapshotTest.php
// ssi:financial-snapshot — read-only preservation evidence for a LIVE database. STATUS: written, NOT executed.
namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Rank\FinancialTestCase;

class FinancialSnapshotTest extends FinancialTestCase
{
    protected string $file;
    protected User $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/ssi-snap-' . uniqid() . '.json';
        $this->inv = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '500.00']);
        foreach ([10, 20] as $a) $this->adj($a);
    }
    protected function tearDown(): void { @unlink($this->file); parent::tearDown(); }

    protected function adj(float $amount): BalanceAdjustment
    {
        return BalanceAdjustment::create(['user_id' => $this->inv->id, 'admin_id' => $this->admin->id, 'type' => 'add', 'amount' => $amount,
            'balance_before' => '0.00', 'balance_after' => number_format($amount, 2, '.', ''), 'reason' => 'fixture']);
    }
    protected function snap(array $o = []): int { return Artisan::call('ssi:financial-snapshot', ['--out' => $this->file, '--hash' => true] + $o); }
    protected function cmp(array $o = []): int { return Artisan::call('ssi:financial-snapshot', ['--compare' => $this->file] + $o); }

    public function test_unchanged_data_passes_and_the_snapshot_is_owner_only(): void
    {
        $this->assertSame(0, $this->snap());
        $this->assertSame(0, $this->cmp(['--strict' => true]), Artisan::output());
        if (DIRECTORY_SEPARATOR === '/') $this->assertSame('0600', substr(sprintf('%o', fileperms($this->file)), -4));
        $j = json_decode(file_get_contents($this->file), true);
        $this->assertSame(2, $j['tables']['balance_adjustments']['count']);
        $this->assertSame('30.00', $j['tables']['balance_adjustments']['sums']['amount']);
    }

    public function test_it_never_overwrites_an_existing_snapshot_without_force(): void
    {
        $this->assertSame(0, $this->snap());
        $this->assertSame(1, $this->snap());
        $this->assertSame(0, $this->snap(['--force' => true]));
    }

    public function test_activity_after_the_snapshot_does_not_fail_a_live_comparison(): void
    {
        $this->snap();
        $this->adj(5);                                                                         // a NEW append-only row (higher id) — normal on a live site
        $this->inv->forceFill(['balance' => '999.00'])->save();                                  // a mutable table moved — normal on a live site
        $code = $this->cmp(); $output = Artisan::output();   // read ONCE: reading the buffer empties it
        $this->assertSame(0, $code, $output);                                                    // immutable history up to the snapshot id is untouched
        $this->assertStringContainsString('mutable', $output);
        $this->assertSame(1, $this->cmp(['--strict' => true]));                                  // …but on a quiet staging copy it is flagged
    }

    public function test_tampering_with_historical_immutable_rows_is_detected(): void
    {
        $this->snap();
        $row = BalanceAdjustment::first();
        DB::table('balance_adjustments')->where('id', $row->id)->update(['amount' => 11]);        // same row count, altered amount
        $this->assertSame(1, $this->cmp());
        $this->assertStringContainsString('IMMUTABLE', Artisan::output());
    }

    public function test_a_hash_difference_is_caught_even_when_sums_are_unchanged(): void
    {
        $this->snap();
        $rows = BalanceAdjustment::orderBy('id')->get();
        DB::table('balance_adjustments')->where('id', $rows[0]->id)->update(['amount' => 20]);   // 10 + 20 → 20 + 10: same sum, different rows
        DB::table('balance_adjustments')->where('id', $rows[1]->id)->update(['amount' => 10]);
        $this->assertSame(1, $this->cmp());
        $this->assertStringContainsString('row hash differs', Artisan::output());
    }

    public function test_a_deleted_historical_row_is_detected(): void
    {
        $this->snap();
        DB::table('balance_adjustments')->where('id', BalanceAdjustment::first()->id)->delete();
        $this->assertSame(1, $this->cmp());
    }

    public function test_the_command_is_read_only(): void
    {
        $before = [DB::table('users')->count(), DB::table('balance_adjustments')->count(), (string) DB::table('users')->sum('balance')];
        $this->snap(); $this->cmp(['--strict' => true]);
        $this->assertSame($before, [DB::table('users')->count(), DB::table('balance_adjustments')->count(), (string) DB::table('users')->sum('balance')]);
    }

    public function test_a_non_snapshot_file_is_refused(): void
    {
        file_put_contents($this->file, '{"nope":1}');
        $this->assertSame(1, $this->cmp());
    }
}
