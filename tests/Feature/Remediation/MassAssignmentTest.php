<?php
// LOCATION: tests/Feature/Remediation/MassAssignmentTest.php
// F-12: untrusted request data can never change role, status, balance or withdrawal PIN. STATUS: written, NOT executed by the author.

namespace Tests\Feature\Remediation;

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Tests\Feature\Rank\FinancialTestCase;

class MassAssignmentTest extends FinancialTestCase
{
    public function test_privileged_fields_are_not_mass_assignable(): void
    {
        $fillable = (new User())->getFillable();
        foreach (['role', 'status', 'balance', 'withdrawal_pin', 'investor_rank_id', 'kyc_status', 'kyc_verified', 'kyc_verified_at'] as $f) $this->assertNotContains($f, $fillable, $f);
    }

    public function test_strict_mode_makes_a_protected_mass_assignment_loud_not_silent(): void
    {
        $this->strictMassAssignment(true);
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '10.00']);
        foreach (['balance' => '1', 'role' => 'admin', 'status' => 'frozen', 'withdrawal_pin' => 'x', 'kyc_verified' => true, 'kyc_status' => 'approved'] as $k => $v) {
            try { $u->update([$k => $v]); $this->fail("$k was mass-assignable"); }
            catch (MassAssignmentException) { $this->assertTrue(true); }
        }
        $u->forceFill(['balance' => '25.00'])->save();       // the explicit, audited path still works
        $this->assertSame('25.00', number_format((float) $u->fresh()->balance, 2, '.', ''));
    }

    public function test_fill_ignores_privileged_keys(): void
    {
        $this->strictMassAssignment(false);   // this test asserts the production (silent-discard) behaviour; strict mode is asserted above
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '10.00']);
        $u->fill(['name' => 'New Name', 'role' => 'admin', 'status' => 'frozen', 'balance' => '999999.00', 'withdrawal_pin' => 'x'])->save();
        $u = $u->fresh();
        $this->assertSame('New Name', $u->name);
        $this->assertSame('investor', $u->role);
        $this->assertSame('active', $u->status);
        $this->assertSame('10.00', number_format((float) $u->balance, 2, '.', ''));
        $this->assertNull($u->withdrawal_pin);
    }

    public function test_investor_profile_update_cannot_escalate(): void
    {
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active', 'balance' => '10.00']);
        $this->actingAs($u, 'sanctum')->putJson('/api/investor-investment/investor/profile', ['name' => 'Valid Name', 'role' => 'admin', 'status' => 'active', 'balance' => 5000, 'withdrawal_pin' => '1234']);
        $u = $u->fresh();
        $this->assertSame('investor', $u->role);
        $this->assertSame('10.00', number_format((float) $u->balance, 2, '.', ''));
        $this->assertNull($u->withdrawal_pin);
    }

    public function test_legitimate_admin_status_changes_still_work(): void
    {
        // Test isolation: AccountStatusNotification hard-codes ->mailer('brevo') (bypasses MAIL_MAILER=array); without this the suspend call attempts a REAL Brevo send.
        \Illuminate\Support\Facades\Mail::fake();
        $u = User::factory()->create(['role' => 'investor', 'status' => 'active']);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/users/{$u->id}/suspend")->assertSuccessful();
        $this->assertSame('suspended', $u->fresh()->status);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/users/{$u->id}", ['status' => 'active'])->assertSuccessful();
        $this->assertSame('active', $u->fresh()->status);
    }
}
