<?php
// LOCATION: tests/Feature/InvestmentSettlementTest.php
// The Admin "Complete" action and the scheduled maturity command share one locked, idempotent
// settlement routine. These tests prove profit is credited exactly once and only for ACTIVE investments.

namespace Tests\Feature;

use App\Models\InvestmentAccount;
use App\Models\User;
use App\Services\InvestmentSettlementService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvestmentSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    protected function investor(float $balance = 100.00): User
    {
        return User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => $balance]);
    }

    protected function investment(User $u, array $over = []): InvestmentAccount
    {
        $planId = DB::table('investment_plans')->insertGetId([
            'name' => 'Plan ' . uniqid(), 'min_amount' => 1, 'duration_days' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return InvestmentAccount::create(array_merge([
            'user_id' => $u->id, 'investment_plan_id' => $planId, 'amount' => 1000,
            'profit_percentage' => 5, 'expected_profit' => 50.00, 'status' => 'active',
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->addDays(10)->toDateString(),
        ], $over));
    }

    protected function balance(User $u): float
    {
        return (float) $u->fresh()->balance;
    }

    // ── Admin "Complete" action ─────────────────────────────────────────────────────

    public function test_admin_complete_credits_the_profit_exactly_once_even_if_clicked_twice(): void
    {
        $u = $this->investor(100); $inv = $this->investment($u);
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson("/api/admin/investments/{$inv->id}/complete")->assertOk();
        $this->assertSame('completed', $inv->fresh()->status);
        $this->assertEquals(150.00, $this->balance($u));

        $this->postJson("/api/admin/investments/{$inv->id}/complete")->assertStatus(422);   // second click refused
        $this->postJson("/api/admin/investments/{$inv->id}/complete")->assertStatus(422);
        $this->assertEquals(150.00, $this->balance($u));                                    // never paid again
    }

    public function test_admin_cannot_complete_a_cancelled_investment_or_pay_its_profit(): void
    {
        $u = $this->investor(100); $inv = $this->investment($u, ['status' => 'cancelled']);
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson("/api/admin/investments/{$inv->id}/complete")
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'cancelled'));

        $this->assertSame('cancelled', $inv->fresh()->status);
        $this->assertEquals(100.00, $this->balance($u));
    }

    public function test_only_admins_can_complete_an_investment(): void
    {
        $u = $this->investor(100); $inv = $this->investment($u);

        $this->actingAs($u, 'sanctum')->postJson("/api/admin/investments/{$inv->id}/complete")->assertStatus(403);
        $this->actingAs(User::factory()->create(['role' => 'financial', 'status' => 'active']), 'sanctum')
            ->postJson("/api/admin/investments/{$inv->id}/complete")->assertStatus(403);

        $this->assertSame('active', $inv->fresh()->status);
        $this->assertEquals(100.00, $this->balance($u));
    }

    public function test_a_stale_copy_of_an_investment_cannot_be_paid_twice(): void
    {
        $u = $this->investor(100); $inv = $this->investment($u);
        $stale = InvestmentAccount::find($inv->id);                 // loaded while still active

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/investments/{$inv->id}/complete")->assertOk();
        $result = app(InvestmentSettlementService::class)->complete($stale, 'schedule');   // the scheduler's old copy

        $this->assertFalse($result['completed']);
        $this->assertSame('status_completed', $result['reason']);
        $this->assertEquals(150.00, $this->balance($u));
    }

    public function test_completion_does_not_touch_other_investors_or_the_principal_record(): void
    {
        $a = $this->investor(100); $b = $this->investor(500);
        $inv = $this->investment($a); $other = $this->investment($b);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/investments/{$inv->id}/complete")->assertOk();

        $this->assertEquals(500.00, $this->balance($b));
        $this->assertSame('active', $other->fresh()->status);
        $this->assertEquals(1000, $inv->fresh()->amount);           // amount untouched
    }

    // ── Scheduled maturity command ──────────────────────────────────────────────────

    public function test_command_completes_and_pays_a_matured_investment_once_across_repeated_runs(): void
    {
        $u = $this->investor(100);
        $inv = $this->investment($u, ['end_date' => now()->subDay()->toDateString()]);

        $this->artisan('investments:update-maturity')->assertSuccessful();
        $this->artisan('investments:update-maturity')->assertSuccessful();
        $this->artisan('investments:update-maturity')->assertSuccessful();

        $this->assertSame('completed', $inv->fresh()->status);
        $this->assertEquals(150.00, $this->balance($u));            // once, not three times
    }

    public function test_command_pays_on_the_end_date_itself_and_not_the_day_before(): void
    {
        $u = $this->investor(0);
        $today = $this->investment($u, ['end_date' => now()->toDateString(), 'expected_profit' => 10]);
        $tomorrow = $this->investment($u, ['end_date' => now()->addDay()->toDateString(), 'expected_profit' => 99]);

        $this->artisan('investments:update-maturity')->assertSuccessful();

        $this->assertSame('completed', $today->fresh()->status);
        $this->assertSame('active', $tomorrow->fresh()->status);
        $this->assertEquals(10.00, $this->balance($u));
    }

    public function test_command_leaves_unmatured_investments_alone_but_refreshes_their_countdown(): void
    {
        $u = $this->investor(100);
        $inv = $this->investment($u, ['end_date' => now()->addDays(10)->toDateString(), 'remaining_days' => 99]);

        $this->artisan('investments:update-maturity')->assertSuccessful();

        $this->assertSame('active', $inv->fresh()->status);
        $this->assertSame(10, (int) $inv->fresh()->remaining_days);
        $this->assertEquals(100.00, $this->balance($u));
    }

    public function test_command_never_pays_an_active_investment_that_has_no_end_date(): void
    {
        $u = $this->investor(100);
        $inv = $this->investment($u, ['remaining_days' => 0]);
        DB::table('investment_accounts')->where('id', $inv->id)->update(['end_date' => null]);

        $this->artisan('investments:update-maturity')->assertSuccessful();

        $this->assertSame('active', $inv->fresh()->status);
        $this->assertEquals(100.00, $this->balance($u));
    }

    public function test_command_ignores_cancelled_and_already_completed_investments(): void
    {
        $u = $this->investor(100);
        $this->investment($u, ['status' => 'cancelled', 'end_date' => now()->subDay()->toDateString()]);
        $this->investment($u, ['status' => 'completed', 'end_date' => now()->subDay()->toDateString()]);

        $this->artisan('investments:update-maturity')->assertSuccessful();

        $this->assertEquals(100.00, $this->balance($u));
    }

    public function test_dry_run_reports_the_backlog_and_changes_nothing(): void
    {
        $a = $this->investor(100); $b = $this->investor(200);
        $x = $this->investment($a, ['end_date' => now()->subDays(30)->toDateString(), 'expected_profit' => 50, 'remaining_days' => 7]);
        $y = $this->investment($b, ['end_date' => now()->subDay()->toDateString(), 'expected_profit' => 25.50]);
        $this->investment($b, ['end_date' => now()->addDays(5)->toDateString()]);

        $this->artisan('investments:update-maturity --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('WOULD be completed: 2')
            ->expectsOutputToContain('75.50 across 2 investor(s)')
            ->assertSuccessful();

        $this->assertSame('active', $x->fresh()->status);
        $this->assertSame('active', $y->fresh()->status);
        $this->assertEquals(100.00, $this->balance($a));
        $this->assertEquals(200.00, $this->balance($b));
        $this->assertSame(7, (int) $x->fresh()->remaining_days);          // even the countdown is untouched
    }

    // ── Scheduling is explicit and off by default for the payout ────────────────────

    protected function scheduled(string $command)
    {
        \Illuminate\Support\Facades\Artisan::call('schedule:list');   // boots the console kernel, which loads routes/console.php
        return collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, $command));
    }

    public function test_maturity_payout_is_registered_but_disabled_until_explicitly_enabled(): void
    {
        $event = $this->scheduled('investments:update-maturity');
        $this->assertNotNull($event, 'maturity task must be registered with the scheduler');
        $this->assertSame('0 0 * * *', $event->expression);

        $this->assertFalse($event->filtersPass($this->app), 'must NOT run by default — it pays money');

        config(['scheduling.investment_maturity' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_fixture_sync_is_registered_and_on_by_default_and_switchable(): void
    {
        $event = $this->scheduled('gaming:sync-fixtures');
        $this->assertNotNull($event);
        $this->assertSame('*/30 * * * *', $event->expression);   // everyThirtyMinutes()
        $this->assertTrue($event->filtersPass($this->app));

        config(['scheduling.fixture_sync' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }

    public function test_the_legacy_kernel_no_longer_defines_a_second_copy_of_the_schedule(): void
    {
        $src = file_get_contents(app_path('Console/Kernel.php'));
        $this->assertStringNotContainsString("->command('investments:update-maturity')", $src);
        $this->assertStringNotContainsString("->command('gaming:sync-fixtures')", $src);
    }
}
