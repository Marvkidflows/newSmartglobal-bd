<?php
// LOCATION: tests/Feature/Remediation/BalanceIntegrityTest.php   (NEW) — Finance exception monitor is read-only, role-gated and detects seeded anomalies.
// WRITTEN, NOT EXECUTED (no vendor/). Run: php artisan test --filter=BalanceIntegrityTest

namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BalanceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $x = []): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'email' => Str::lower(Str::random(8)) . '@ssi-test.test', 'email_verified_at' => now()] + $x);
    }

    private function fetch(User $u) { $this->app['auth']->forgetGuards(); return $this->actingAs($u, 'sanctum')->getJson('/api/financial/balance-integrity'); }

    public function test_investor_and_anonymous_are_refused(): void
    {
        $this->fetch($this->user('investor'))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/financial/balance-integrity')->assertUnauthorized();
    }

    public function test_clean_data_reports_zero_exceptions_for_financial_and_admin(): void
    {
        foreach (['financial', 'admin'] as $role) {
            $this->fetch($this->user($role))->assertOk()->assertJsonPath('exceptions', 0)->assertJsonPath('read_only', true);
        }
    }

    public function test_detects_approved_deposit_without_credit_and_double_credit_and_changes_nothing(): void
    {
        $inv = $this->user('investor', ['balance' => 10]);
        $admin = $this->user('admin');
        $none = Deposit::create(['user_id' => $inv->id, 'amount' => 100, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-I1', 'status' => 'approved', 'processed_by' => $admin->id, 'processed_at' => now()]);
        $twice = Deposit::create(['user_id' => $inv->id, 'amount' => 50, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-I2', 'status' => 'approved', 'processed_by' => $admin->id, 'processed_at' => now()]);
        foreach ([1, 2] as $_) BalanceAdjustment::create(['user_id' => $inv->id, 'admin_id' => $admin->id, 'type' => 'add', 'amount' => 50, 'balance_before' => 0, 'balance_after' => 50, 'reason' => "Deposit #{$twice->id} approved"]);

        $checks = collect($this->fetch($admin)->assertOk()->json('checks'))->keyBy('key');
        $this->assertSame(1, $checks['deposit_no_credit']['count']);
        $this->assertSame($none->id, $checks['deposit_no_credit']['rows'][0]['id']);
        $this->assertSame(1, $checks['deposit_multiple_credit']['count']);
        $this->assertEquals(10, $inv->fresh()->balance, 'the monitor must never change a balance');
        $this->assertSame(2, BalanceAdjustment::count());
    }

    public function test_detects_negative_balance(): void
    {
        $this->user('investor', ['balance' => -5]);
        $checks = collect($this->fetch($this->user('admin'))->json('checks'))->keyBy('key');
        $this->assertSame(1, $checks['negative_balance']['count']);
    }
}
