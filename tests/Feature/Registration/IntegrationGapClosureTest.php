<?php
// LOCATION: tests/Feature/Registration/IntegrationGapClosureTest.php
// Gap-closure round after the Tasks 2-5 merge. WRITTEN, NOT EXECUTED (no PHP runtime where it was authored) — run it before trusting it.
// Covers: delegated-staff mailing scope (Registration never sees investor mail), my-permissions endpoints, Registration export + password change,
// and the default-off fee adjustment flag lives in WithdrawalFeeTest.

namespace Tests\Feature\Registration;

use App\Models\Mailing\MailDepartment;
use App\Models\Mailing\MailMessage;
use App\Models\Mailing\MailPermission;
use App\Models\RegistrationEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class IntegrationGapClosureTest extends RegistrationTestCase
{
    public function test_my_permissions_endpoints_return_only_the_callers_grants_and_refuse_non_staff(): void
    {
        MailPermission::create(['user_id' => $this->member->id, 'permission' => 'public.view']);
        MailPermission::create(['user_id' => $this->member->id, 'permission' => 'investor.view']);   // a (mistaken) row that must never take effect
        $r = $this->asUser($this->member)->getJson('/api/mailing/my/permissions')->assertOk();
        $this->assertContains('public.view', $r->json('permissions'));
        $this->assertNotContains('investor.view', $r->json('permissions'), 'registration roles never hold investor.* mailing permissions');
        $this->assertFalse($r->json('is_admin'));

        $investor = User::factory()->create(['role' => 'investor', 'status' => 'active']);
        $this->asUser($investor)->getJson('/api/mailing/my/permissions')->assertStatus(403);
        $this->asUser($investor)->getJson('/api/news-admin/my/permissions')->assertStatus(403);
    }

    public function test_registration_staff_cannot_use_investor_mailing_even_with_a_stray_permission_row(): void
    {
        MailPermission::create(['user_id' => $this->lead->id, 'permission' => 'investor.view']);
        MailPermission::create(['user_id' => $this->lead->id, 'permission' => 'investor.send']);
        foreach (['/api/mailing/investor/dashboard', '/api/mailing/investor/messages', '/api/mailing/investor/campaigns'] as $url) {
            $this->asUser($this->lead)->getJson($url)->assertStatus(403);
        }
    }

    public function test_registration_staff_never_see_investor_departments_or_messages_and_cannot_be_added_to_them(): void
    {
        $pub = MailDepartment::create(['name' => 'Registration', 'slug' => 'registration-t', 'environment' => 'public', 'is_active' => true]);
        $inv = MailDepartment::create(['name' => 'Investor Support', 'slug' => 'investor-support-t', 'environment' => 'investor', 'is_active' => true]);
        MailPermission::create(['user_id' => $this->member->id, 'permission' => 'departments.view']);

        $names = collect($this->asUser($this->member)->getJson('/api/mailing/departments')->assertOk()->json('departments'))->pluck('name');
        $this->assertContains('Registration', $names->all());
        $this->assertNotContains('Investor Support', $names->all());

        $admin = User::factory()->create(['role' => 'admin']);
        $this->asUser($admin)->putJson("/api/mailing/departments/{$inv->id}/members", ['members' => [['user_id' => $this->member->id, 'role' => 'member']]])->assertStatus(422);
    }

    public function test_registration_export_is_lead_or_admin_only_formula_safe_audited_and_excludes_sensitive_columns(): void
    {
        $app = $this->submittedApplicant(true);
        $app->user->forceFill(['full_name' => '=HYPERLINK("http://evil")'])->save();

        $this->asUser($this->member)->get('/api/registration/applications/export')->assertStatus(403);

        $res = $this->asUser($this->lead)->get('/api/registration/applications/export')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->getContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'a leading = is neutralised');
        $this->assertStringNotContainsString("\"=HYPERLINK", $csv);
        foreach (['phone', 'id_number', 'selfie', 'document_path', 'password', 'balance'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower(strtok($csv, "\n")), "header must not contain {$forbidden}");
        }
        $this->assertTrue(RegistrationEvent::where('event_type', 'report_exported')->exists(), 'every export is recorded');
        $this->asUser(User::factory()->create(['role' => 'admin']))->get('/api/registration/applications/export')->assertOk();
    }

    public function test_staff_password_change_requires_the_current_password_and_signs_out_other_sessions(): void
    {
        $this->member->forceFill(['password' => Hash::make('OldPassw0rd!')])->save();
        $other = $this->member->createToken('other-device');
        $this->member->createToken('this-device');

        $this->asUser($this->member)->postJson('/api/registration/profile/password', ['current_password' => 'wrong', 'new_password' => 'NewPassw0rd!', 'new_password_confirmation' => 'NewPassw0rd!'])->assertStatus(422);
        $this->asUser($this->member)->postJson('/api/registration/profile/password', ['current_password' => 'OldPassw0rd!', 'new_password' => 'OldPassw0rd!', 'new_password_confirmation' => 'OldPassw0rd!'])->assertStatus(422);
        $this->asUser($this->member)->postJson('/api/registration/profile/password', ['current_password' => 'OldPassw0rd!', 'new_password' => 'NewPassw0rd!', 'new_password_confirmation' => 'NewPassw0rd!'])->assertOk();

        $this->assertTrue(Hash::check('NewPassw0rd!', $this->member->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);

        $investor = User::factory()->create(['role' => 'investor', 'status' => 'active']);
        $this->asUser($investor)->postJson('/api/registration/profile/password', ['current_password' => 'x', 'new_password' => 'NewPassw0rd!', 'new_password_confirmation' => 'NewPassw0rd!'])->assertStatus(403);
    }

    public function test_referral_scan_command_never_approves_or_credits_and_is_scheduled_without_backfill(): void
    {
        $this->artisan('referrals:scan --no-backfill')->assertExitCode(0);
        $this->assertSame(0, \App\Models\ReferralReward::whereIn('status', ['approved', 'credited'])->count());
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");
        $this->assertStringContainsString('referrals:scan --no-backfill', $events);
    }

    public function test_with_a_real_bearer_token_only_the_other_sessions_are_revoked_and_the_current_one_survives(): void
    {
        $this->member->forceFill(['password' => Hash::make('OldPassw0rd!')])->save();
        $current = $this->member->createToken('this-device');
        $other   = $this->member->createToken('other-device');

        $this->withHeader('Authorization', 'Bearer ' . $current->plainTextToken)
            ->postJson('/api/registration/profile/password', ['current_password' => 'OldPassw0rd!', 'new_password' => 'NewPassw0rd!', 'new_password_confirmation' => 'NewPassw0rd!'])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
    }
}
