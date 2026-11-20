<?php
// LOCATION: tests/Feature/Remediation/InvestorIdorTest.php   (NEW FILE — D-28)
// Two-account object-level authorization check for the investor routes that take a record id: investor A must not read or change investor B's records by
// changing the id. Scope: deposits (show, confirm), investments (show), messages (show), notifications (read, delete). Source review of the other
// investor id routes (loan withdraw, emails, task assignments) found an ownership check in each; they are not covered by an executed test here.
// NOT a sweep of all 522 routes: staff routes and the admin/financial surfaces are still NOT VERIFIED. WRITTEN, NOT EXECUTED (no vendor/).
// Run: php artisan test --filter=InvestorIdorTest

namespace Tests\Feature\Remediation;

use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvestorIdorTest extends TestCase
{
    use RefreshDatabase;

    private User $a;
    private User $b;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        $make = fn (string $name) => User::factory()->create([
            'role' => 'investor', 'name' => $name, 'status' => 'active', 'registration_stage' => 4, 'registration_completed' => true, 'balance' => 1000,
            'email' => Str::lower(Str::random(8)) . '@ssi-test.test', 'email_verified_at' => now(),
        ]);
        $this->a = $make('Investor A');
        $this->b = $make('Investor B');
        $this->planId = DB::table('investment_plans')->insertGetId(['name' => 'Plan ' . uniqid(), 'min_amount' => 1, 'duration_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function asA() { $this->app['auth']->forgetGuards(); return $this->actingAs($this->a, 'sanctum'); }

    private function deposit(User $u): Deposit
    {
        return Deposit::create(['user_id' => $u->id, 'amount' => 500, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-IDOR-' . uniqid(), 'status' => 'pending']);
    }

    private function investment(User $u): InvestmentAccount
    {
        return InvestmentAccount::create([
            'user_id' => $u->id, 'investment_plan_id' => $this->planId, 'amount' => 1000, 'profit_percentage' => 10, 'expected_profit' => 100, 'total_return' => 1100,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(), 'remaining_days' => 30, 'status' => 'active',
        ]);
    }

    public function test_a_cannot_view_or_confirm_bs_deposit_but_can_view_their_own(): void
    {
        $theirs = $this->deposit($this->b);
        $mine   = $this->deposit($this->a);

        $this->asA()->getJson("/api/investor-investment/investor/deposits/{$theirs->id}")->assertStatus(403);
        $this->asA()->putJson("/api/investor-investment/investor/deposits/{$theirs->id}/confirm", ['payment_method' => 'bank_transfer'])->assertStatus(403);
        $this->assertSame('pending', $theirs->fresh()->status, "B's deposit must be unchanged");

        $this->asA()->getJson("/api/investor-investment/investor/deposits/{$mine->id}")->assertOk();
    }

    public function test_a_cannot_view_bs_investment_but_can_view_their_own(): void
    {
        $theirs = $this->investment($this->b);
        $mine   = $this->investment($this->a);

        $this->asA()->getJson("/api/investor-investment/investor/investments/{$theirs->id}")->assertStatus(403);
        $this->asA()->getJson("/api/investor-investment/investor/investments/{$mine->id}")->assertOk();
    }

    public function test_a_cannot_read_bs_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $msg = fn (User $u) => Message::create(['investor_id' => $u->id, 'sender_id' => $admin->id, 'receiver_id' => $u->id, 'body' => 'private', 'initiated_by' => 'admin', 'read_by_admin' => true, 'read_by_investor' => false, 'department' => 'admin']);
        $theirs = $msg($this->b);
        $mine   = $msg($this->a);

        $this->asA()->getJson("/api/investor-investment/messages/{$theirs->id}")->assertStatus(403);
        $this->assertFalse((bool) $theirs->fresh()->read_by_investor, "reading attempt must not mark B's message read");
        $this->asA()->getJson("/api/investor-investment/messages/{$mine->id}")->assertOk();
    }

    public function test_a_cannot_mark_read_or_delete_bs_notification(): void
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $this->b->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $this->asA()->postJson("/api/investor-investment/notifications/{$id}/read")->assertStatus(404);
        $this->asA()->deleteJson("/api/investor-investment/notifications/{$id}")->assertOk();   // the endpoint answers the same way whether or not it deleted anything

        $row = DB::table('notifications')->where('id', $id)->first();
        $this->assertNotNull($row, "B's notification must still exist");
        $this->assertNull($row->read_at, "B's notification must not be marked read");
    }
}
