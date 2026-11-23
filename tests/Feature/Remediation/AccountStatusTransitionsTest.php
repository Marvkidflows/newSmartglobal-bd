<?php
// LOCATION: tests/Feature/Remediation/AccountStatusTransitionsTest.php   (NEW — 22 Nov)
// Admin account-status actions behind the Investors list / detail buttons: each action moves the right status, and Activate cannot lift a freeze.
namespace Tests\Feature\Remediation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rank\FinancialTestCase;

class AccountStatusTransitionsTest extends FinancialTestCase
{
    use RefreshDatabase;

    private function as(string $method, string $uri, array $body = [])
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($this->admin, 'sanctum')->json($method, $uri, $body);
    }

    public function test_suspend_then_activate(): void
    {
        $u = $this->investor();
        $this->as('POST', "/api/admin/users/{$u->id}/suspend")->assertOk();
        $this->assertSame('suspended', $u->fresh()->status);
        $this->as('POST', "/api/admin/users/{$u->id}/activate")->assertOk();
        $this->assertSame('active', $u->fresh()->status);
    }

    public function test_deactivate_then_activate_reinstates(): void
    {
        $u = $this->investor();
        $this->as('POST', "/api/admin/users/{$u->id}/deactivate")->assertOk();
        $this->assertSame('deactivated', $u->fresh()->status);
        $this->as('POST', "/api/admin/users/{$u->id}/activate")->assertOk();
        $this->assertSame('active', $u->fresh()->status);
    }

    public function test_freeze_and_unfreeze_through_the_audited_endpoint(): void
    {
        $u = $this->investor();
        $r = $this->as('POST', "/api/admin/users/{$u->id}/balance", ['type' => 'freeze', 'reason' => 'Suspicious activity review']);
        $this->assertContains($r->status(), [200, 202]);
        if ($r->status() === 200) {
            $this->assertSame('frozen', $u->fresh()->status);
            $this->as('POST', "/api/admin/users/{$u->id}/balance", ['type' => 'unfreeze', 'reason' => 'Review complete'])->assertStatus(200);
            $this->assertSame('active', $u->fresh()->status);
        }
    }

    public function test_activate_cannot_lift_a_freeze(): void
    {
        $u = $this->investor();
        $u->forceFill(['status' => 'frozen'])->save();
        $this->as('POST', "/api/admin/users/{$u->id}/activate")->assertStatus(422);
        $this->assertSame('frozen', $u->fresh()->status);
    }

    public function test_unfreeze_refuses_a_suspended_account(): void
    {
        $u = $this->investor();
        $u->forceFill(['status' => 'suspended'])->save();
        $this->as('POST', "/api/admin/users/{$u->id}/unfreeze")->assertStatus(422);
        $this->assertSame('suspended', $u->fresh()->status);
    }
}
