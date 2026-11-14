<?php
// LOCATION: tests/Feature/RegistrationRoleSecurityTest.php
//
// Management clarification 1 and 6 (7 Nov 2026): public registration ALWAYS creates an investor and can never produce, or take over, an admin
// or any other staff account, whatever the request contains.
//
//   * role, status, balance, KYC and rank fields in the request are ignored;
//   * the "resume an unfinished registration" branch is for INVESTORS only: a staff account whose registration flag is unset (the default for
//     accounts made by a seeder or by hand) used to be resumable, which overwrote its password and returned a login token (an account takeover).
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php -d memory_limit=-1 vendor\bin\pest --filter=RegistrationRoleSecurityTest

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RegistersWithEmailCode;
use Tests\TestCase;

class RegistrationRoleSecurityTest extends TestCase
{
    use RefreshDatabase;
    use RegistersWithEmailCode;   // CONTRACT CHANGE (enumeration closure): accounts are created at /register/verify, after the emailed code

    private array $payload = [
        'full_name'             => 'Role Tester',
        'email'                 => 'role.tester@example.com',
        'country_iso2'          => 'US',
        'phone'                 => '2015550123',
        'password'              => 'NewPassw0rd!',
        'password_confirmation' => 'NewPassw0rd!',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(OtpService::class, fn () => new class extends OtpService {
            public function generateAndSend(User $user): void {}
        });
    }

    public function test_public_registration_always_creates_an_active_investor_whatever_the_request_says(): void
    {
        $inject = [
            'role' => 'admin', 'is_admin' => true, 'status' => 'suspended', 'balance' => 999999, 'kyc_status' => 'approved', 'kyc_verified' => true,
            'investor_rank_id' => 6, 'rank_status' => 'revoked', 'registration_completed' => true, 'tier' => 'elite', 'abilities' => ['*'],
        ];
        $this->registerViaCode($this->payload, $inject, $inject)->assertSuccessful();   // injected into BOTH stage 1 and verify

        $u = User::where('email', $this->payload['email'])->firstOrFail();
        $this->assertSame('investor', $u->role);
        $this->assertSame('active', $u->status);
        $this->assertEquals(0, $u->balance);
        $this->assertNull($u->investor_rank_id);
        $this->assertFalse((bool) $u->registration_completed);
        $this->assertNotSame('approved', $u->kyc_status);
        $this->assertFalse((bool) $u->kyc_verified);
        $this->assertNotSame('elite', $u->tier);
    }

    public function test_privileged_columns_are_not_mass_assignable(): void
    {
        $fillable = (new User())->getFillable();
        foreach (['role', 'status', 'balance', 'investor_rank_id', 'rank_status', 'kyc_status', 'kyc_verified', 'withdrawal_pin', 'tier'] as $col) {
            $this->assertNotContains($col, $fillable, "{$col} must not be mass-assignable");
        }
    }

    public function test_an_unfinished_staff_account_can_never_be_taken_over_through_registration(): void
    {
        foreach (['admin', 'financial', 'marvflow_member', 'marvflow_lead'] as $role) {
            $email = "staff-{$role}@example.com";
            $staff = User::factory()->create([
                'role' => $role, 'status' => 'active', 'email' => $email, 'registration_completed' => false,
                'password' => Hash::make('Original#Pass1'),
            ]);

            $attacker = array_merge($this->payload, ['email' => $email, 'password' => 'Attacker#Pass9', 'password_confirmation' => 'Attacker#Pass9']);
            $res = $this->stage1($attacker);

            // CONTRACT CHANGE: stage 1 is generic (200) for every email — the refusal now happens at verify, and nothing is ever emailed.
            $res->assertOk();
            $this->assertArrayNotHasKey('token', $res->json() ?? [], "{$role}: no login token may be issued");
            Mail::assertNothingSent();
            $this->verifyWith($attacker, '123456')->assertStatus(422)->assertJsonMissingPath('token');
            $staff->refresh();
            $this->assertTrue(Hash::check('Original#Pass1', $staff->password), "{$role}: the password must not change");
            $this->assertFalse(Hash::check('Attacker#Pass9', $staff->password));
            $this->assertSame($role, $staff->role);
            $this->assertSame(0, $staff->tokens()->count(), "{$role}: no token created");
        }
    }

    public function test_an_unfinished_investor_registration_can_still_be_resumed(): void
    {
        $inv = User::factory()->create([
            'role' => 'investor', 'status' => 'active', 'email' => 'resume@example.com', 'registration_completed' => false,
            'password' => Hash::make('Original#Pass1'),
        ]);

        $payload = array_merge($this->payload, ['email' => 'resume@example.com']);
        $this->stage1($payload)->assertOk()->assertJsonMissingPath('token');
        $this->assertTrue(Hash::check('Original#Pass1', $inv->fresh()->password), 'unchanged until the code is entered');
        $res = $this->verifyWith($payload, $this->emailedCode());

        $res->assertSuccessful();
        $this->assertNotEmpty($res->json('token'));
        $inv->refresh();
        $this->assertTrue(Hash::check('NewPassw0rd!', $inv->password));
        $this->assertSame('investor', $inv->role);
    }

    public function test_a_completed_account_is_still_refused(): void
    {
        User::factory()->create(['role' => 'investor', 'status' => 'active', 'email' => 'done@example.com', 'registration_completed' => true, 'password' => Hash::make('Original#Pass1')]);

        $payload = array_merge($this->payload, ['email' => 'done@example.com']);
        $this->stage1($payload)->assertOk();   // generic: no oracle
        Mail::assertNothingSent();
        $this->verifyWith($payload, '123456')->assertStatus(422);
    }
}
