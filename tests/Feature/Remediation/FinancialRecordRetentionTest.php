<?php
// LOCATION: tests/Feature/Remediation/FinancialRecordRetentionTest.php   (NEW FILE — D-08)
// A user with financial or audit history cannot be deleted through Eloquent; a user with none still can. No retention period is decided here.
// WRITTEN, NOT EXECUTED (no vendor/). Run: php artisan test --filter=FinancialRecordRetentionTest

namespace Tests\Feature\Remediation;

use App\Models\BalanceAdjustment;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\FinancialRecordRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FinancialRecordRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function investor(): User
    {
        return User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => 100]);
    }

    private function withdrawal(User $u, ?User $processor = null): Withdrawal
    {
        return Withdrawal::create(['user_id' => $u->id, 'amount' => 10, 'method' => 'bank_transfer', 'account_details' => json_encode(['bank_name' => 'T']),
            'status' => $processor ? 'approved' : 'pending', 'processed_by' => $processor?->id]);
    }

    public function test_an_investor_with_a_withdrawal_cannot_be_deleted_and_nothing_is_lost(): void
    {
        $u = $this->investor();
        $w = $this->withdrawal($u);

        try {
            $u->delete();
            $this->fail('deleting a user with financial history must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('withdrawals.user_id', $e->getMessage());
            $this->assertStringContainsString('Deactivate', $e->getMessage());
        }

        $this->assertNotNull(User::find($u->id));
        $this->assertNotNull(Withdrawal::find($w->id));
    }

    public function test_a_staff_member_who_processed_a_withdrawal_cannot_be_deleted(): void
    {
        $inv = $this->investor();
        $fin = User::factory()->create(['role' => 'financial', 'status' => 'active']);
        $this->withdrawal($inv, $fin);

        $this->expectException(RuntimeException::class);
        $fin->delete();
    }

    public function test_a_user_with_a_balance_adjustment_cannot_be_deleted(): void
    {
        $u = $this->investor();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        BalanceAdjustment::create(['user_id' => $u->id, 'admin_id' => $admin->id, 'type' => 'deduct', 'amount' => 5, 'balance_before' => 100, 'balance_after' => 95, 'reason' => 'test']);

        $this->assertArrayHasKey('balance_adjustments.user_id', FinancialRecordRetention::holdings($u));
        $this->assertArrayHasKey('balance_adjustments.admin_id', FinancialRecordRetention::holdings($admin));
        $this->expectException(RuntimeException::class);
        $u->delete();
    }

    public function test_a_user_with_no_financial_history_can_still_be_deleted(): void
    {
        $u = $this->investor();
        $this->assertSame([], FinancialRecordRetention::holdings($u));

        $u->delete();

        $this->assertNull(User::find($u->id));
    }

    public function test_other_users_records_are_untouched_by_a_refused_delete(): void
    {
        $a = $this->investor(); $b = $this->investor();
        $this->withdrawal($a); $this->withdrawal($b);
        try { $a->delete(); } catch (RuntimeException $e) { /* expected */ }

        $this->assertSame(2, DB::table('withdrawals')->count());
        $this->assertSame(2, User::where('role', 'investor')->count());
    }
}
