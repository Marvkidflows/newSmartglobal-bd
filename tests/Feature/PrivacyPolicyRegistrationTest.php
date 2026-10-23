<?php
// LOCATION: tests/Feature/PrivacyPolicyRegistrationTest.php
//
// Data Privacy Policy acknowledgement at registration (Stage 1):
//  - public policy metadata endpoint
//  - optional mode (default — Management has not yet decided): recorded when
//    given, not demanded
//  - required mode (PRIVACY_ACKNOWLEDGEMENT_REQUIRED=true): enforced on the
//    SERVER, not just the frontend
//  - version must match the current policy version
//  - privacy columns are not mass-assignable

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyPolicyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const VERSION = 'test-version-1';
    private const EMAIL   = 'privacy.tester@example.com';

    protected array $stage1Payload = [
        'full_name'             => 'Privacy Tester',
        'email'                 => self::EMAIL,
        'country_iso2'          => 'US',
        'phone'                 => '2015550123',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['privacy.version' => self::VERSION]);

        // Never hit the real mail provider.
        $this->app->bind(OtpService::class, fn () => new class extends OtpService {
            public function generateAndSend(User $user): void {}
        });
    }

    private function requireAcknowledgement(bool $required = true): void
    {
        config(['privacy.acknowledgement_required' => $required]);
    }

    private function ack(array $overrides = []): array
    {
        return array_merge($this->stage1Payload, [
            'privacy_policy_acknowledged' => true,
            'privacy_policy_version'      => self::VERSION,
        ], $overrides);
    }

    // ── Public metadata endpoint ────────────────────────────────────────────

    public function test_policy_meta_is_public_and_contains_no_user_data(): void
    {
        $this->requireAcknowledgement(false);

        $res = $this->getJson('/api/privacy-policy');

        $res->assertOk()->assertJson([
            'version'                  => self::VERSION,
            'acknowledgement_required' => false,
        ]);
        $this->assertSame(
            ['acknowledgement_required', 'approved', 'effective_date', 'version'],
            collect($res->json())->keys()->sort()->values()->all()
        );
    }

    public function test_policy_meta_reflects_required_flag(): void
    {
        $this->requireAcknowledgement(true);

        $this->getJson('/api/privacy-policy')->assertOk()->assertJson(['acknowledgement_required' => true]);
    }

    // ── Optional mode (default) ─────────────────────────────────────────────

    public function test_default_configuration_does_not_enforce_acknowledgement(): void
    {
        $this->assertFalse((bool) config('privacy.acknowledgement_required'));
    }

    public function test_optional_mode_registration_without_acknowledgement_still_works_and_records_nothing(): void
    {
        $this->requireAcknowledgement(false);

        $this->postJson('/api/register/stage1', $this->stage1Payload)
            ->assertOk()->assertJson(['success' => true]);

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertNull($user->privacy_policy_version);
        $this->assertNull($user->privacy_policy_acknowledged_at);
    }

    public function test_optional_mode_records_version_and_timestamp_when_acknowledged(): void
    {
        $this->requireAcknowledgement(false);

        $this->postJson('/api/register/stage1', $this->ack())->assertOk();

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(self::VERSION, $user->privacy_policy_version);
        $this->assertNotNull($user->privacy_policy_acknowledged_at);
        $this->assertLessThan(60, abs(now()->timestamp - $user->privacy_policy_acknowledged_at->timestamp));
    }

    public function test_optional_mode_acknowledgement_without_version_is_rejected(): void
    {
        $this->requireAcknowledgement(false);

        $this->postJson('/api/register/stage1', array_merge($this->stage1Payload, [
            'privacy_policy_acknowledged' => true,
        ]))->assertStatus(422)->assertJsonValidationErrors(['privacy_policy_version']);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_acknowledgement_naming_a_stale_version_is_rejected(): void
    {
        $this->requireAcknowledgement(false);

        $this->postJson('/api/register/stage1', $this->ack(['privacy_policy_version' => 'old-version']))
            ->assertStatus(422)->assertJsonValidationErrors(['privacy_policy_version']);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    // ── Required mode: enforced on the server ───────────────────────────────

    public function test_required_mode_rejects_registration_with_no_acknowledgement(): void
    {
        $this->requireAcknowledgement(true);

        $this->postJson('/api/register/stage1', $this->stage1Payload)
            ->assertStatus(422)->assertJsonValidationErrors(['privacy_policy_acknowledged']);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_required_mode_rejects_explicit_false(): void
    {
        $this->requireAcknowledgement(true);

        $this->postJson('/api/register/stage1', $this->ack(['privacy_policy_acknowledged' => false]))
            ->assertStatus(422)->assertJsonValidationErrors(['privacy_policy_acknowledged']);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_required_mode_rejects_missing_version(): void
    {
        $this->requireAcknowledgement(true);

        $this->postJson('/api/register/stage1', array_merge($this->stage1Payload, [
            'privacy_policy_acknowledged' => true,
        ]))->assertStatus(422)->assertJsonValidationErrors(['privacy_policy_version']);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_required_mode_rejects_wrong_version(): void
    {
        $this->requireAcknowledgement(true);

        $this->postJson('/api/register/stage1', $this->ack(['privacy_policy_version' => 'something-else']))
            ->assertStatus(422)->assertJsonValidationErrors(['privacy_policy_version']);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_required_mode_accepts_valid_acknowledgement_and_records_it(): void
    {
        $this->requireAcknowledgement(true);

        $this->postJson('/api/register/stage1', $this->ack())
            ->assertOk()->assertJson(['success' => true]);

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(self::VERSION, $user->privacy_policy_version);
        $this->assertNotNull($user->privacy_policy_acknowledged_at);
    }

    // ── Resume + safety ─────────────────────────────────────────────────────

    public function test_resuming_an_incomplete_registration_records_the_acknowledgement(): void
    {
        // First attempt (e.g. before this feature, or abandoned)
        $this->requireAcknowledgement(false);
        $this->postJson('/api/register/stage1', $this->stage1Payload)->assertOk();
        $this->assertNull(User::where('email', self::EMAIL)->first()->privacy_policy_version);

        // Same email returns, now with acknowledgement
        $this->requireAcknowledgement(true);
        $this->postJson('/api/register/stage1', $this->ack())->assertOk();

        $this->assertSame(self::VERSION, User::where('email', self::EMAIL)->first()->privacy_policy_version);
        $this->assertSame(1, User::where('email', self::EMAIL)->count());
    }

    public function test_privacy_columns_are_not_mass_assignable(): void
    {
        $fillable = (new User())->getFillable();

        $this->assertNotContains('privacy_policy_version', $fillable);
        $this->assertNotContains('privacy_policy_acknowledged_at', $fillable);
    }

    public function test_existing_registration_validation_still_applies(): void
    {
        $this->requireAcknowledgement(true);

        $this->postJson('/api/register/stage1', $this->ack(['password_confirmation' => 'different']))
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }
}
