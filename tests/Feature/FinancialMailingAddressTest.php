<?php
// LOCATION: tests/Feature/FinancialMailingAddressTest.php
//
// Covers the Investor Mailing / Postal Information brief: reuse of
// existing address fields (residential_address/city/state/postal_code),
// the new address_line_2 field, the registration Stage 2 persistence bug
// this work fixed (residential_address was missing from User::$fillable),
// investor self-service view/update, Financial Team read access, the
// audited correction workflow, and the privacy/security boundaries around
// all of it.

namespace Tests\Feature;

use App\Models\FinancialAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialMailingAddressTest extends TestCase
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
        $this->investor  = User::factory()->create([
            'role' => 'investor', 'name' => 'Ivy Investor',
            'residential_address' => '123 Main Street', 'address_line_2' => 'Apt 4B',
            'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '100001', 'country' => 'Nigeria',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // REGRESSION: residential_address now actually persists
    // ═══════════════════════════════════════════════════════════════

    public function test_registration_stage2_now_persists_residential_address_and_line_2(): void
    {
        $user = User::factory()->create([
            'role' => 'investor', 'email_verified_at' => now(), 'registration_stage' => 1,
            'residential_address' => null, 'address_line_2' => null,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/register/stage2', [
            'date_of_birth' => '1990-01-01',
            'residential_address' => '45 Independence Ave',
            'address_line_2' => 'Suite 2',
            'city' => 'Abuja', 'state' => 'FCT', 'postal_code' => '900001',
        ])->assertOk();

        $user->refresh();
        // Before this fix, residential_address was missing from
        // User::$fillable and Eloquent silently dropped it here.
        $this->assertSame('45 Independence Ave', $user->residential_address);
        $this->assertSame('Suite 2', $user->address_line_2);
        $this->assertSame('Abuja', $user->city);
        $this->assertSame(2, $user->registration_stage);
    }

    // ═══════════════════════════════════════════════════════════════
    // INVESTOR SELF-SERVICE
    // ═══════════════════════════════════════════════════════════════

    public function test_investor_can_view_and_update_own_mailing_information(): void
    {
        $as = $this->actingAs($this->investor, 'sanctum');

        $res = $as->getJson('/api/investor-investment/investor/profile')->assertOk();
        $res->assertJsonPath('user.address_line_1', '123 Main Street');
        $res->assertJsonPath('user.address_line_2', 'Apt 4B');
        $res->assertJsonPath('user.postal_code', '100001');

        $as->putJson('/api/investor-investment/investor/profile', [
            'address_line_1' => '99 New Road', 'address_line_2' => 'Unit 3',
            'city' => 'Kano', 'state' => 'Kano', 'postal_code' => '700001',
        ])->assertOk();

        $this->investor->refresh();
        $this->assertSame('99 New Road', $this->investor->residential_address);
        $this->assertSame('Unit 3', $this->investor->address_line_2);
        $this->assertSame('Kano', $this->investor->city);
    }

    public function test_mailing_address_update_is_validated(): void
    {
        $as = $this->actingAs($this->investor, 'sanctum');
        $as->putJson('/api/investor-investment/investor/profile', ['address_line_1' => str_repeat('x', 501)])->assertStatus(422);
        $as->putJson('/api/investor-investment/investor/profile', ['postal_code' => str_repeat('1', 21)])->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // FINANCIAL TEAM ACCESS
    // ═══════════════════════════════════════════════════════════════

    public function test_financial_can_view_investor_mailing_information(): void
    {
        $res = $this->actingAs($this->financial, 'sanctum')
            ->getJson("/api/financial/investors/{$this->investor->id}")->assertOk();

        $res->assertJsonPath('mailing_address.address_line_1', '123 Main Street');
        $res->assertJsonPath('mailing_address.address_line_2', 'Apt 4B');
        $res->assertJsonPath('mailing_address.city', 'Lagos');
        $res->assertJsonPath('mailing_address.postal_code', '100001');
        $res->assertJsonPath('mailing_address.country', 'Nigeria');
    }

    public function test_financial_correction_is_authorized_validated_and_audited(): void
    {
        $fin = $this->actingAs($this->financial, 'sanctum');

        $res = $fin->postJson("/api/financial/investors/{$this->investor->id}/mailing-address", [
            'address_line_1' => '77 Corrected Street', 'address_line_2' => null,
            'city' => 'Ibadan', 'state' => 'Oyo', 'postal_code' => '200001',
            'reason' => 'Investor called to report a data entry error at registration.',
        ])->assertOk();

        $this->investor->refresh();
        $this->assertSame('77 Corrected Street', $this->investor->residential_address);
        $this->assertNull($this->investor->address_line_2);
        $this->assertSame('Ibadan', $this->investor->city);

        $log = FinancialAuditLog::where('action', 'investor.mailing_address_corrected')->firstOrFail();
        $this->assertSame($res->json('audit_reference'), $log->reference);
        $this->assertSame($this->financial->id, $log->actor_id);
        $this->assertSame('financial', $log->actor_role);
        $this->assertSame($this->investor->id, $log->investor_id);
        $this->assertSame('123 Main Street', $log->previous_value['address_line_1']);
        $this->assertSame('77 Corrected Street', $log->new_value['address_line_1']);
        $this->assertSame('Lagos', $log->previous_value['city']);
        $this->assertSame('Ibadan', $log->new_value['city']);
        $this->assertStringContainsString('data entry error', $log->reason);
        $this->assertNotNull($log->created_at);
    }

    public function test_correction_requires_reason_and_validates_fields(): void
    {
        $fin = $this->actingAs($this->financial, 'sanctum');
        $url = "/api/financial/investors/{$this->investor->id}/mailing-address";

        $fin->postJson($url, ['address_line_1' => 'X', 'city' => 'Y'])->assertStatus(422); // no reason
        $fin->postJson($url, ['address_line_1' => 'X', 'city' => 'Y', 'reason' => 'short'])->assertStatus(422); // reason too short
        $fin->postJson($url, ['city' => 'Y', 'reason' => 'Missing the address line entirely here.'])->assertStatus(422); // address_line_1 required
        $fin->postJson($url, ['address_line_1' => 'X', 'reason' => 'Missing the city entirely here.'])->assertStatus(422); // city required
        $fin->postJson($url, [
            'address_line_1' => '123 Main Street', 'address_line_2' => 'Apt 4B',
            'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '100001',
            'reason' => 'Identical values submitted as a correction.',
        ])->assertStatus(422); // no-op

        $this->investor->refresh();
        $this->assertSame('123 Main Street', $this->investor->residential_address); // unchanged
        $this->assertSame(0, FinancialAuditLog::count());
    }

    public function test_financial_cannot_correct_non_investor_accounts(): void
    {
        $this->actingAs($this->financial, 'sanctum')
            ->postJson("/api/financial/investors/{$this->admin->id}/mailing-address", [
                'address_line_1' => 'X', 'city' => 'Y', 'reason' => 'Attempting to target a staff account.',
            ])->assertStatus(404);

        $this->actingAs($this->financial, 'sanctum')
            ->getJson("/api/financial/investors/{$this->admin->id}")->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVACY & SECURITY
    // ═══════════════════════════════════════════════════════════════

    public function test_investor_cannot_view_or_correct_another_investors_mailing_information(): void
    {
        $other = User::factory()->create(['role' => 'investor', 'residential_address' => 'Secret Street']);

        // The investor's own profile endpoint only ever exposes Auth::user() —
        // there is no id-parameterised investor profile route reachable by investors.
        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/financial/investors/{$other->id}/mailing-address", [
                'address_line_1' => 'X', 'city' => 'Y', 'reason' => 'Investor attempting a financial-only action.',
            ])->assertStatus(403);

        $this->actingAs($this->investor, 'sanctum')
            ->getJson("/api/financial/investors/{$other->id}")->assertStatus(403);
    }

    public function test_other_staff_roles_and_guests_are_rejected(): void
    {
        $marv = User::factory()->create(['role' => 'marvflow_member']);
        $this->actingAs($marv, 'sanctum')
            ->postJson("/api/financial/investors/{$this->investor->id}/mailing-address", [
                'address_line_1' => 'X', 'city' => 'Y', 'reason' => 'Unauthorized role attempting a correction.',
            ])->assertStatus(403);

        $this->postJson("/api/financial/investors/{$this->investor->id}/mailing-address", [
            'address_line_1' => 'X', 'city' => 'Y', 'reason' => 'Guest attempting a correction.',
        ])->assertStatus(401);

        $this->investor->refresh();
        $this->assertSame('123 Main Street', $this->investor->residential_address);
    }

    public function test_mailing_address_is_not_exposed_through_public_endpoints(): void
    {
        $res = $this->postJson('/api/public/contact-support', [
            'name' => 'Anon', 'email' => 'anon@example.com', 'subject' => 'Hi', 'message' => 'Test message here.',
        ]);
        $flat = json_encode($res->json());
        $this->assertStringNotContainsString('123 Main Street', $flat);
        $this->assertStringNotContainsString('residential_address', $flat);
    }

    public function test_admin_kyc_review_reads_the_correct_address_field(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/kyc/{$this->investor->id}")->assertOk();
        // Regression: this previously read the separate, unrelated `address`
        // column (always empty) instead of the actual KYC-submitted address.
        $this->assertSame('123 Main Street', $res->json('address'));
        $this->assertSame('Apt 4B', $res->json('address_line_2'));
        $this->assertSame('100001', $res->json('postal_code'));
    }
}
