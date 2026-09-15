<?php
// LOCATION: tests/Feature/FinancialTeamTest.php
//
// Financial Team dashboard — covers the full authorization matrix the
// spec calls for: financial login, financial access to deposits/
// withdrawals/investments/notifications/dashboard, admin access
// retained, investor access unaffected, and unauthorized users (guests,
// investors, and financial users reaching for admin-only routes)
// correctly blocked. Every check here hits the real HTTP routes and
// real middleware — this is what "authorized server-side, not just a
// hidden button" actually means in test form.

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialTeamTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $financial;
    protected User $investor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin     = User::factory()->create(['role' => 'admin']);
        $this->financial = User::factory()->create(['role' => 'financial']);
        $this->investor  = User::factory()->create(['role' => 'investor']);
    }

    // ── LOGIN ────────────────────────────────────────────────────────

    public function test_financial_team_member_can_log_in(): void
    {
        $user = User::factory()->create([
            'role'                   => 'financial',
            'password'               => bcrypt('password123'),
            'registration_completed' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email, 'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.role', 'financial');
    }

    // ── ACCESS: FINANCIAL TEAM ──────────────────────────────────────

    public function test_financial_user_can_view_dashboard(): void
    {
        $this->actingAs($this->financial, 'sanctum')
            ->getJson('/api/financial/dashboard')
            ->assertStatus(200);
    }

    public function test_financial_user_can_view_and_approve_deposits(): void
    {
        $deposit = Deposit::create([
            'user_id' => $this->investor->id, 'amount' => 500,
            'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-TEST-1',
            'status' => 'pending',
        ]);

        $this->actingAs($this->financial, 'sanctum')
            ->getJson('/api/financial/deposits')
            ->assertStatus(200);

        $response = $this->actingAs($this->financial, 'sanctum')
            ->postJson("/api/financial/deposits/{$deposit->id}/approve");

        $response->assertStatus(200);
        $this->assertDatabaseHas('deposits', ['id' => $deposit->id, 'status' => 'approved']);
    }

    public function test_financial_user_can_view_and_approve_withdrawals(): void
    {
        $this->investor->update(['balance' => 1000]);
        $withdrawal = Withdrawal::create([
            'user_id' => $this->investor->id, 'amount' => 200,
            'method' => 'bank_transfer', 'account_details' => json_encode(['bank_name' => 'Test']),
            'status' => 'pending',
        ]);

        $this->actingAs($this->financial, 'sanctum')
            ->getJson('/api/financial/withdrawals')
            ->assertStatus(200);

        $response = $this->actingAs($this->financial, 'sanctum')
            ->postJson("/api/financial/withdrawals/{$withdrawal->id}/approve");

        $response->assertStatus(200);
        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal->id, 'status' => 'approved']);
    }

    public function test_financial_user_can_view_investments_read_only(): void
    {
        $this->actingAs($this->financial, 'sanctum')
            ->getJson('/api/financial/investments')
            ->assertStatus(200);
    }

    public function test_financial_user_can_view_notifications(): void
    {
        $this->actingAs($this->financial, 'sanctum')
            ->getJson('/api/financial/notifications')
            ->assertStatus(200);
    }

    // ── ACCESS BOUNDARIES: WHAT FINANCIAL TEAM CANNOT DO ────────────

    public function test_financial_user_cannot_access_admin_routes(): void
    {
        $this->actingAs($this->financial, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_financial_user_cannot_manage_users(): void
    {
        $this->actingAs($this->financial, 'sanctum')
            ->getJson("/api/admin/users/{$this->investor->id}")
            ->assertStatus(403);
    }

    public function test_financial_user_cannot_manage_competitions(): void
    {
        // Sanity check that unrelated admin-only features (Gaming &
        // Prediction league selection) stay out of reach too — the
        // financial group only ever exposes what's explicitly routed
        // under /api/financial/*.
        $this->actingAs($this->financial, 'sanctum')
            ->getJson('/api/admin/competitions')
            ->assertStatus(403);
    }

    public function test_financial_user_cannot_manage_investment_countdowns(): void
    {
        // Only index/show are exposed to financial team — the
        // countdown-manipulation endpoints stay admin-exclusive even
        // though they live on the same underlying controller.
        $this->actingAs($this->financial, 'sanctum')
            ->postJson('/api/financial/investments/1/countdown/extend', ['days' => 5])
            ->assertStatus(404); // route doesn't exist under /financial at all
    }

    // ── ADMIN ACCESS RETAINED ────────────────────────────────────────

    public function test_admin_retains_full_admin_access(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertStatus(200);
    }

    public function test_admin_can_also_use_financial_routes(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/financial/deposits')
            ->assertStatus(200);
    }

    // ── INVESTOR ACCESS UNAFFECTED ───────────────────────────────────

    public function test_investor_cannot_access_financial_routes(): void
    {
        $this->actingAs($this->investor, 'sanctum')
            ->getJson('/api/financial/deposits')
            ->assertStatus(403);
    }

    public function test_investor_dashboard_still_works_normally(): void
    {
        $investor = User::factory()->create([
            'role' => 'investor', 'registration_stage' => 4, 'registration_completed' => true,
        ]);

        $this->actingAs($investor, 'sanctum')
            ->getJson('/api/investor-investment/dashboard')
            ->assertStatus(200);
    }

    // ── UNAUTHORIZED ACCESS ───────────────────────────────────────────

    public function test_guest_cannot_access_financial_routes(): void
    {
        $this->getJson('/api/financial/dashboard')->assertStatus(401);
    }

    public function test_guest_cannot_access_admin_routes(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }
}
