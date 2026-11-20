<?php
// LOCATION: tests/Feature/Remediation/MaturityScheduleGuardTest.php   (NEW FILE — D-05)
// The unattended maturity payout must be fail-closed: no Finance approval recorded => pays nobody; backlog above the approved ceiling => pays nobody;
// approved => pays once and never twice. WRITTEN, NOT EXECUTED (no vendor/). Run: php artisan test --filter=MaturityScheduleGuardTest

namespace Tests\Feature\Remediation;

use App\Models\InvestmentAccount;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaturityScheduleGuardTest extends TestCase
{
    use RefreshDatabase;

    private function matured(User $u, float $profit): InvestmentAccount
    {
        $planId = DB::table('investment_plans')->insertGetId(['name' => 'Plan ' . uniqid(), 'min_amount' => 1, 'duration_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        return InvestmentAccount::create([
            'user_id' => $u->id, 'investment_plan_id' => $planId, 'amount' => 1000, 'profit_percentage' => 5, 'expected_profit' => $profit, 'status' => 'active',
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(3)->toDateString(),
        ]);
    }

    private function investor(): User
    {
        return User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => 100]);
    }

    public function test_the_schedule_runs_the_guarded_form_of_the_command(): void
    {
        \Illuminate\Support\Facades\Artisan::call('schedule:list');
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'investments:update-maturity'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('--scheduled', (string) $event->command);
    }

    public function test_scheduled_run_with_no_recorded_approval_pays_nobody(): void
    {
        $u = $this->investor(); $inv = $this->matured($u, 50);
        config(['scheduling.investment_maturity_approval_ref' => null, 'scheduling.investment_maturity_max_run_total' => null]);

        $this->artisan('investments:update-maturity --scheduled')->assertFailed();

        $this->assertSame('active', $inv->fresh()->status);
        $this->assertEquals(100.00, (float) $u->fresh()->balance);
    }

    public function test_a_reference_without_a_ceiling_or_a_ceiling_without_a_reference_is_still_refused(): void
    {
        $u = $this->investor(); $inv = $this->matured($u, 50);

        config(['scheduling.investment_maturity_approval_ref' => 'FIN-REF', 'scheduling.investment_maturity_max_run_total' => null]);
        $this->artisan('investments:update-maturity --scheduled')->assertFailed();
        config(['scheduling.investment_maturity_approval_ref' => '', 'scheduling.investment_maturity_max_run_total' => '1000']);
        $this->artisan('investments:update-maturity --scheduled')->assertFailed();
        config(['scheduling.investment_maturity_approval_ref' => 'FIN-REF', 'scheduling.investment_maturity_max_run_total' => '0']);
        $this->artisan('investments:update-maturity --scheduled')->assertFailed();

        $this->assertSame('active', $inv->fresh()->status);
        $this->assertEquals(100.00, (float) $u->fresh()->balance);
    }

    public function test_a_backlog_above_the_approved_ceiling_pays_nothing(): void
    {
        $a = $this->investor(); $b = $this->investor();
        $x = $this->matured($a, 60); $y = $this->matured($b, 60);                 // backlog 120
        config(['scheduling.investment_maturity_approval_ref' => 'FIN-REF', 'scheduling.investment_maturity_max_run_total' => '100']);

        $this->artisan('investments:update-maturity --scheduled')->assertFailed();

        $this->assertSame('active', $x->fresh()->status);
        $this->assertSame('active', $y->fresh()->status);
        $this->assertEquals(100.00, (float) $a->fresh()->balance);
        $this->assertEquals(100.00, (float) $b->fresh()->balance);
    }

    public function test_an_approved_run_pays_once_and_repeats_pay_nothing_more(): void
    {
        $u = $this->investor(); $inv = $this->matured($u, 50);
        config(['scheduling.investment_maturity_approval_ref' => 'FIN-REF', 'scheduling.investment_maturity_max_run_total' => '100']);

        $this->artisan('investments:update-maturity --scheduled')->assertSuccessful();
        $this->artisan('investments:update-maturity --scheduled')->assertSuccessful();
        $this->artisan('investments:update-maturity --scheduled')->assertSuccessful();

        $this->assertSame('completed', $inv->fresh()->status);
        $this->assertEquals(150.00, (float) $u->fresh()->balance, 'paid exactly once');
    }

    public function test_dry_run_is_never_blocked_by_the_guard_and_changes_nothing(): void
    {
        $u = $this->investor(); $inv = $this->matured($u, 50);
        $this->artisan('investments:update-maturity --scheduled --dry-run')->assertSuccessful();
        $this->assertSame('active', $inv->fresh()->status);
        $this->assertEquals(100.00, (float) $u->fresh()->balance);
    }

    public function test_the_switch_still_defaults_to_off(): void
    {
        $this->assertFalse((bool) config('scheduling.investment_maturity'));
    }
}
