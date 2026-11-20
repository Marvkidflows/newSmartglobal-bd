<?php
// LOCATION: tests/Feature/Remediation/WithdrawalApprovalSafeguardsTest.php   (NEW FILE — D-06)
//
// Withdrawal approval: refused cleanly when the balance is too low (nothing written), debits exactly once, a second approval cannot debit again,
// and a rejected/non-pending withdrawal never touches the balance. WRITTEN, NOT EXECUTED by its author (no vendor/): run
//     php artisan test --filter=WithdrawalApprovalSafeguardsTest
// NOT covered here, on purpose: "investor row missing". Foreign keys make that state unreachable inside the per-test SQLite transaction (PRAGMA foreign_keys
// cannot be switched off inside a transaction), so that fail-closed branch is verified by code reading only; see the D-06 note in the changelog.
// Throw-away factory users only; in-memory SQLite.

namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalApprovalSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function investor(string $balance): User
    {
        return User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => $balance, 'registration_completed' => true]);
    }

    private function withdrawal(User $u, float $amount, string $status = 'pending'): Withdrawal
    {
        return Withdrawal::create(['user_id' => $u->id, 'amount' => $amount, 'method' => 'bank_transfer', 'account_details' => json_encode(['bank_name' => 'T']), 'status' => $status]);
    }

    private function approve(User $actor, Withdrawal $w, string $area = 'financial')
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($actor, 'sanctum')->postJson("/api/{$area}/withdrawals/{$w->id}/approve");
    }

    public function test_approval_debits_exactly_once_and_records_one_adjustment(): void
    {
        $fin = $this->staff('financial'); $u = $this->investor('100.00'); $w = $this->withdrawal($u, 80);

        $this->approve($fin, $w)->assertOk();

        $this->assertSame('approved', $w->fresh()->status);
        $this->assertEquals(20.0, (float) $u->fresh()->balance);
        $this->assertSame(1, BalanceAdjustment::where('user_id', $u->id)->where('type', 'deduct')->count());
    }

    public function test_a_second_approval_of_the_same_withdrawal_is_refused_and_debits_nothing_more(): void
    {
        $fin = $this->staff('financial'); $admin = $this->staff('admin'); $u = $this->investor('100.00'); $w = $this->withdrawal($u, 80);

        $this->approve($fin, $w)->assertOk();
        $this->approve($fin, $w)->assertStatus(422);
        $this->approve($admin, $w, 'admin')->assertStatus(422);

        $this->assertEquals(20.0, (float) $u->fresh()->balance, 'the balance was debited once');
        $this->assertSame(1, BalanceAdjustment::where('user_id', $u->id)->where('type', 'deduct')->count());
    }

    public function test_insufficient_balance_is_refused_and_nothing_is_written(): void
    {
        $fin = $this->staff('financial'); $u = $this->investor('50.00'); $w = $this->withdrawal($u, 80);

        $this->approve($fin, $w)->assertStatus(422)->assertJsonPath('message', 'Insufficient user balance.');

        $this->assertSame('pending', $w->fresh()->status);
        $this->assertEquals(50.0, (float) $u->fresh()->balance);
        $this->assertSame(0, BalanceAdjustment::where('user_id', $u->id)->count());
    }

    public function test_the_exact_balance_can_be_withdrawn_and_never_goes_negative(): void
    {
        $fin = $this->staff('financial'); $u = $this->investor('80.00'); $w = $this->withdrawal($u, 80);

        $this->approve($fin, $w)->assertOk();

        $this->assertEquals(0.0, (float) $u->fresh()->balance);
    }

    public function test_a_non_pending_withdrawal_cannot_be_approved_and_its_balance_is_untouched(): void
    {
        $fin = $this->staff('financial'); $u = $this->investor('100.00');
        // 'cancelled' is not a withdrawals.status value (cancellation workflow is a pending Management decision)
        foreach (['rejected', 'approved'] as $status) {
            $w = $this->withdrawal($u, 10, $status);
            $this->approve($fin, $w)->assertStatus(422);
            $this->assertSame($status, $w->fresh()->status);
        }
        $this->assertEquals(100.0, (float) $u->fresh()->balance);
    }

    public function test_the_approval_source_has_no_unconditional_decrement_and_fails_closed_on_a_missing_user(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Admin/AdminWithdrawalController.php'));
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $src);
        $this->assertStringNotContainsString("\$user->decrement('balance'", $code);
        $this->assertStringContainsString("where('balance', '>=', \$locked->amount)->decrement(", $code);
        $this->assertStringContainsString("'reason' => 'missing_user'", $code);
    }
}
