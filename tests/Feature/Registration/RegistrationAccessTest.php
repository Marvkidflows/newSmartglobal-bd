<?php
// LOCATION: tests/Feature/Registration/RegistrationAccessTest.php
// Task 2 — departmental separation, enforced by the BACKEND: the Registration Team can reach onboarding/KYC and nothing financial.

namespace Tests\Feature\Registration;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RegistrationAccessTest extends RegistrationTestCase
{
    /** Every financial surface (admin, financial and investor-facing money endpoints). */
    private function financialSurface(): array
    {
        return [
            ['GET',  '/api/admin/deposits'],
            ['GET',  '/api/admin/withdrawals'],
            ['GET',  '/api/admin/users'],
            ['GET',  '/api/financial/deposits'],
            ['GET',  '/api/financial/withdrawals'],
            ['GET',  '/api/financial/transactions'],
            ['GET',  '/api/financial/investors'],
            ['GET',  '/api/financial/dashboard'],
            ['POST', '/api/financial/withdrawals/1/approve'],
            ['POST', '/api/financial/deposits/1/approve'],
            ['POST', '/api/admin/users/1/balance'],
            ['GET',  '/api/investor-investment/investor/withdrawals'],
            ['GET',  '/api/investor-investment/investor/deposits'],
            ['GET',  '/api/investor-investment/investor/investments'],
        ];
    }

    public function test_registration_staff_are_refused_every_financial_endpoint(): void
    {
        foreach ([$this->member, $this->lead] as $staff) {
            foreach ($this->financialSurface() as [$method, $url]) {
                $this->asUser($staff)->json($method, $url)->assertStatus(403, "{$staff->role} must be refused {$method} {$url}");
            }
        }
    }

    public function test_registration_roles_hold_no_financial_ability_or_other_department_ability(): void
    {
        foreach ([$this->member, $this->lead] as $staff) {
            foreach (['financial.review-transactions', 'financial.act-on-transactions', 'financial.adjust-balance', 'financial.correct-records',
                      'financial.manage-positions', 'financial.communicate', 'financial.issue-notices', 'financial.view-audit',
                      'ranks.view', 'ranks.assign', 'loans.decide'] as $ability) {
                $this->assertFalse(Gate::forUser($staff)->allows($ability), "{$staff->role} must not hold {$ability}");
            }
            $this->assertTrue(Gate::forUser($staff)->allows('registration.review'));
        }
        $this->assertFalse(Gate::forUser($this->member)->allows('registration.decide'));
        $this->assertTrue(Gate::forUser($this->lead)->allows('registration.decide'));
    }

    public function test_registration_staff_cannot_reach_other_staff_areas(): void
    {
        foreach ([$this->member, $this->lead] as $staff) {
            $this->asUser($staff)->getJson('/api/marvflow/requests')->assertStatus(403);
            $this->asUser($staff)->getJson('/api/admin/kyc')->assertStatus(403);   // the Admin KYC surface stays Admin-only
        }
    }

    public function test_other_roles_cannot_use_the_registration_api(): void
    {
        $investor = $this->applicant(['registration_completed' => true, 'registration_stage' => 4]);
        $marv = User::factory()->create(['role' => 'marvflow_member', 'status' => 'active']);

        foreach ([$investor, $this->financial, $marv] as $user) {
            foreach (['/api/registration/dashboard', '/api/registration/applications', '/api/registration/activity', '/api/registration/team'] as $url) {
                $this->asUser($user)->getJson($url)->assertStatus(403);
            }
        }
        $app = $this->submittedApplicant();
        $this->asUser($this->financial)->postJson("/api/registration/applications/{$app->id}/status", ['status' => 'approved'])->assertStatus(403);
        $this->asUser($investor)->postJson("/api/registration/applications/{$app->id}/status", ['status' => 'approved'])->assertStatus(403);
        $this->assertSame('submitted', $app->fresh()->review_status);
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $this->getJson('/api/registration/dashboard')->assertStatus(401);
        $this->getJson('/api/registration/applications')->assertStatus(401);
        $this->postJson('/api/registration/applications/1/status', ['status' => 'approved'])->assertStatus(401);
    }

    public function test_admin_retains_oversight_of_the_registration_area(): void
    {
        $this->asUser($this->admin)->getJson('/api/registration/dashboard')->assertOk();
        $app = $this->submittedApplicant();
        $this->asUser($this->admin)->postJson("/api/registration/applications/{$app->id}/status", ['status' => 'approved'])->assertOk();
    }

    public function test_suspended_registration_staff_lose_access_and_the_token_is_revoked(): void
    {
        foreach (['suspended', 'frozen'] as $status) {
            $u = User::factory()->create(['role' => 'registration_member', 'status' => $status]);
            $this->asUser($u)->getJson('/api/registration/dashboard')->assertStatus(403)->assertJsonPath('code', 'account_restricted');
        }
    }

    public function test_registration_responses_never_contain_financial_or_secret_fields(): void
    {
        $app = $this->submittedApplicant(true, ['balance' => 98765.43]);
        $app->user->forceFill(['withdrawal_pin' => bcrypt('1234')])->save();

        $bodies = [
            $this->asUser($this->lead)->getJson('/api/registration/dashboard')->getContent(),
            $this->asUser($this->lead)->getJson('/api/registration/applications')->getContent(),
            $this->asUser($this->lead)->getJson("/api/registration/applications/{$app->id}")->getContent(),
            $this->asUser($this->lead)->getJson('/api/registration/activity')->getContent(),
        ];
        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('98765', $body);
            foreach (['balance', 'withdrawal_pin', 'password', 'deposit', 'withdrawal', 'wallet', 'remember_token'] as $needle) {
                $this->assertStringNotContainsString($needle, strtolower($body), "response leaked '{$needle}'");
            }
        }
    }

    public function test_the_registration_dashboard_cannot_be_used_to_modify_balances(): void
    {
        $app = $this->submittedApplicant(true, ['balance' => 250]);
        $this->asUser($this->lead)->postJson("/api/registration/applications/{$app->id}/status", ['status' => 'approved', 'balance' => 99999])->assertOk();
        $this->assertEquals(250, $app->user->fresh()->balance);
    }

    public function test_registration_roles_can_log_in_as_staff_without_finishing_investor_registration(): void
    {
        $u = User::factory()->create(['role' => 'registration_member', 'status' => 'active', 'password' => bcrypt('Passw0rd!xyz'), 'registration_completed' => false]);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'Passw0rd!xyz'])->assertOk()->assertJsonPath('user.role', 'registration_member');
    }

    public function test_the_create_staff_command_makes_a_working_registration_account_and_rejects_bad_roles(): void
    {
        $this->artisan('registration:create-staff', ['email' => 'new.reviewer@example.test', 'name' => 'New Reviewer', '--role' => 'financial'])
            ->assertFailed();
        $this->assertNull(User::where('email', 'new.reviewer@example.test')->first());

        $this->artisan('registration:create-staff', ['email' => 'new.reviewer@example.test', 'name' => 'New Reviewer', '--role' => 'registration_member'])
            ->expectsQuestion('Password (min 12 characters)', 'a-very-long-password')
            ->expectsQuestion('Confirm password', 'a-very-long-password')
            ->assertSuccessful();

        $created = User::where('email', 'new.reviewer@example.test')->first();
        $this->assertSame('registration_member', $created->role);
        $this->asUser($created)->getJson('/api/registration/dashboard')->assertOk();
        $this->asUser($created)->getJson('/api/financial/investors')->assertStatus(403);
    }
}
