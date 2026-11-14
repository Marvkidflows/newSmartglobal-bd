<?php
// LOCATION: tests/Feature/Remediation/StaffAccountStatusTest.php
// F-15: suspended / deactivated / inactive staff lose access everywhere and cannot log in; active staff and suspended INVESTORS are unaffected.
// STATUS: written, NOT executed by the author.

namespace Tests\Feature\Remediation;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Rank\FinancialTestCase;

class StaffAccountStatusTest extends FinancialTestCase
{
    protected function staff(string $role, string $status): User
    {
        return User::factory()->create(['role' => $role, 'status' => $status, 'password' => Hash::make('Passw0rd!x')]);
    }

    public function test_non_active_staff_are_refused_on_protected_endpoints(): void
    {
        $matrix = [['admin', '/api/admin/users'], ['financial', '/api/financial/investors'], ['marvflow_member', '/api/marvflow/requests'], ['marvflow_lead', '/api/marvflow/requests']];
        // Only values the users.status enum can actually hold (active|suspended|frozen|deactivated). 'inactive' was removed from the enum;
        // it and unknown values are asserted on unsaved models in test_allowlist_blocks_inactive_and_unknown_statuses().
        foreach (['suspended', 'deactivated', 'frozen'] as $status) {
            foreach ($matrix as [$role, $url]) {
                $u = $this->staff($role, $status);
                $this->actingAs($u, 'sanctum')->getJson($url)->assertStatus(403)->assertJsonPath('code', 'account_restricted');
            }
        }
    }

    public function test_active_staff_still_reach_their_endpoints(): void
    {
        foreach ([['admin', '/api/admin/users'], ['financial', '/api/financial/investors'], ['marvflow_member', '/api/marvflow/requests']] as [$role, $url]) {
            $this->assertNotSame(403, $this->actingAs($this->staff($role, 'active'), 'sanctum')->getJson($url)->getStatusCode(), $role);
        }
    }

    public function test_a_suspended_staff_member_cannot_log_in_and_no_token_is_issued(): void
    {
        foreach (['admin', 'financial', 'marvflow_member'] as $role) {
            $u = $this->staff($role, 'suspended');
            $this->postJson('/api/login', ['email' => $u->email, 'password' => 'Passw0rd!x'])->assertStatus(403);
            $this->assertSame(0, $u->tokens()->count());
        }
    }

    public function test_a_real_token_issued_before_suspension_stops_working_and_is_revoked(): void
    {
        $u = $this->staff('admin', 'active');
        $token = $u->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/users')->assertSuccessful();

        $u->forceFill(['status' => 'suspended'])->save();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/users')->assertStatus(403);
        $this->assertSame(0, $u->tokens()->count(), 'the token is revoked, not merely refused');
    }

    public function test_a_suspended_investor_can_still_log_in_read_only_as_before(): void
    {
        $inv = User::factory()->create(['role' => 'investor', 'status' => 'suspended', 'password' => Hash::make('Passw0rd!x'), 'registration_completed' => true]);
        $this->postJson('/api/login', ['email' => $inv->email, 'password' => 'Passw0rd!x'])->assertOk();
    }

    public function test_allowlist_blocks_inactive_and_unknown_statuses(): void
    {
        foreach (['inactive', 'some_future_status', 'Suspended ', 'FROZEN'] as $status) {      // not persistable (enum) — unsaved model
            $u = new User(); $u->forceFill(['role' => 'financial', 'status' => $status]);
            $this->assertTrue(\App\Support\StaffAccountGuard::isBlocked($u), "'$status' must be blocked");
            $this->assertFalse(\App\Support\StaffAccountGuard::isActive($u));
        }
        foreach ([null, '', '  ', 'active', 'ACTIVE'] as $status) {                            // legacy empty status counts as active, case-insensitively
            $u = new User(); $u->forceFill(['role' => 'financial', 'status' => $status]);
            $this->assertTrue(\App\Support\StaffAccountGuard::isActive($u), var_export($status, true));
        }
        $this->assertFalse(\App\Support\StaffAccountGuard::isBlocked(null));                 // no user: authentication, not this guard, decides
    }
}
