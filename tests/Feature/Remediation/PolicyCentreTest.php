<?php
// LOCATION: tests/Feature/Remediation/PolicyCentreTest.php   (NEW — module-accessibility remediation)
// Policy & Configuration Centre and staff visibility are READ-ONLY, admin-only, and never leak credentials.
// WRITTEN, NOT EXECUTED (no vendor/). Run: php artisan test --filter=PolicyCentreTest

namespace Tests\Feature\Remediation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PolicyCentreTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'email' => Str::lower(Str::random(8)) . '@ssi-test.test', 'email_verified_at' => now()]);
    }

    public function test_admin_can_read_catalogue_and_restricted_features_report_off(): void
    {
        $r = $this->actingAs($this->user('admin'), 'sanctum')->getJson('/api/admin/policy-centre')->assertOk()->assertJsonPath('read_only', true);
        $by = collect($r->json('items'))->keyBy('key');
        $this->assertSame('OFF', $by['lending']['current']);
        $this->assertSame('HARD-LOCKED', $by['lending']['activation']);
        $this->assertSame('OFF', $by['rank_manual_override']['current']);
        $this->assertSame('OFF', $by['incentives']['current']);
        $this->assertSame('Awaiting Management decision', $by['withdrawal_fee']['approval']);
        $this->assertSame('INACTIVE', $by['referral_policy']['activation']);
    }

    public function test_non_admin_roles_are_refused(): void
    {
        foreach (['investor', 'financial', 'marvflow'] as $role) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->user($role), 'sanctum')->getJson('/api/admin/policy-centre')->assertForbidden();
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->user($role), 'sanctum')->getJson('/api/admin/staff')->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/policy-centre')->assertUnauthorized();
    }

    public function test_staff_list_excludes_investors_and_credentials(): void
    {
        $admin = $this->user('admin'); $this->user('financial'); $this->user('investor');
        $r = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/staff')->assertOk();
        $roles = collect($r->json('staff'))->pluck('role')->unique()->values()->all();
        $this->assertNotContains('investor', $roles);
        $this->assertStringNotContainsString('password', strtolower($r->getContent()));
        $this->assertStringNotContainsString('token', strtolower($r->getContent()));
    }

    public function test_catalogue_is_get_only(): void
    {
        $this->actingAs($this->user('admin'), 'sanctum')->postJson('/api/admin/policy-centre', [])->assertStatus(405);
    }
}
