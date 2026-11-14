<?php
// LOCATION: tests/Feature/Registration/RegistrationTeamBoundaryTest.php   (NEW FILE)
//
// Adds ONLY what RegistrationAccessTest does not already cover: the investor-mailing administration boundary, the Admin-only permission grant,
// and a registration user's logout. (Financial endpoints, withdrawal approval, balances, other-role refusal, suspended staff and Admin oversight
// are already asserted in RegistrationAccessTest and are deliberately not repeated.) Extends the existing RegistrationTestCase fixtures.

namespace Tests\Feature\Registration;

use App\Models\User;

class RegistrationTeamBoundaryTest extends RegistrationTestCase
{
    public function test_registration_staff_cannot_use_investor_mailing_administration(): void
    {
        $requests = [
            ['GET',  '/api/mailing/investor/dashboard'],
            ['GET',  '/api/mailing/investor/messages'],
            ['GET',  '/api/mailing/investor/campaigns'],
            ['GET',  '/api/mailing/investor/audiences'],
            ['GET',  '/api/mailing/investor/templates'],
            ['POST', '/api/mailing/investor/campaigns'],
            ['POST', '/api/mailing/investor/messages'],
        ];

        foreach ([$this->member, $this->lead] as $staff) {
            foreach ($requests as [$method, $url]) {
                $this->asUser($staff)->json($method, $url)->assertStatus(403);
            }
        }
    }

    public function test_registration_staff_cannot_grant_themselves_or_anyone_mailing_permissions(): void
    {
        foreach ([$this->member, $this->lead] as $staff) {
            // Granting mailing permissions is an Admin-only action (existing 'admin' middleware).
            $this->asUser($staff)->getJson('/api/mailing/permissions')->assertStatus(403);
            $this->asUser($staff)->putJson("/api/mailing/permissions/{$staff->id}", ['permissions' => ['investor.send', 'investor.manage']])->assertStatus(403);
        }
    }

    public function test_registration_staff_cannot_reach_unrestricted_admin_functions(): void
    {
        foreach ([$this->member, $this->lead] as $staff) {
            foreach ([
                ['GET',  '/api/admin/dashboard'],
                ['GET',  '/api/admin/investments'],
                ['GET',  '/api/admin/announcements'],
                ['GET',  '/api/admin/analytics'],
                ['GET',  '/api/admin/tasks'],
                ['POST', '/api/admin/users/1/balance'],
                ['POST', '/api/admin/global/balance-bulk'],
                ['POST', '/api/admin/users/1/manual-verify'],   // Admin-only e-mail verification override
            ] as [$method, $url]) {
                $this->asUser($staff)->json($method, $url)->assertStatus(403);
            }
        }
    }

    public function test_a_registration_user_can_log_out_and_the_token_is_revoked(): void
    {
        $pw = 'Passw0rd!' . bin2hex(random_bytes(6));
        $u = User::factory()->create(['role' => 'registration_member', 'status' => 'active', 'password' => bcrypt($pw)]);

        $login = $this->postJson('/api/login', ['email' => $u->email, 'password' => $pw])->assertOk();
        $this->assertSame('registration_member', $login->json('user.role'));
        $token = $login->json('token');
        $this->assertSame(1, $u->tokens()->count());

        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/logout')->assertOk();

        $this->assertSame(0, $u->fresh()->tokens()->count(), 'logging out must delete the token that was issued at login');
    }

    public function test_logging_out_one_registration_user_does_not_revoke_another_users_token(): void
    {
        $pw = 'Passw0rd!' . bin2hex(random_bytes(6));
        $a = User::factory()->create(['role' => 'registration_member', 'status' => 'active', 'password' => bcrypt($pw)]);
        $b = User::factory()->create(['role' => 'registration_lead', 'status' => 'active', 'password' => bcrypt($pw)]);

        $tokenA = $this->postJson('/api/login', ['email' => $a->email, 'password' => $pw])->assertOk()->json('token');
        $this->postJson('/api/login', ['email' => $b->email, 'password' => $pw])->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)->postJson('/api/logout')->assertOk();

        $this->assertSame(0, $a->fresh()->tokens()->count());
        $this->assertSame(1, $b->fresh()->tokens()->count());
    }

    public function test_a_deactivated_registration_account_cannot_log_in(): void
    {
        $pw = 'Passw0rd!' . bin2hex(random_bytes(6));
        $u = User::factory()->create(['role' => 'registration_member', 'status' => 'deactivated', 'password' => bcrypt($pw)]);

        $this->postJson('/api/login', ['email' => $u->email, 'password' => $pw])->assertStatus(403);
        $this->assertSame(0, $u->fresh()->tokens()->count());
    }
}
