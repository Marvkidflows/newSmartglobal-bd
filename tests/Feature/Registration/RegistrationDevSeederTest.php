<?php
// LOCATION: tests/Feature/Registration/RegistrationDevSeederTest.php   (NEW FILE)
//
// Covers database/seeders/RegistrationTeamDevSeeder.php: it must be idempotent, must refuse to run outside local development, must never
// create or alter an admin / financial / investor account, and the account it creates must log in and stay inside the Registration area.
// All data lives in the isolated per-test database (in-memory SQLite per phpunit.xml); no real account, balance or credential is used.
// The password below is generated per test run and exists only inside the test process.

namespace Tests\Feature\Registration;

use App\Models\User;
use Database\Seeders\RegistrationTeamDevSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RegistrationDevSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'registration.dev@ssi-dev.test';

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = 'test-only-' . Str::random(20);
        config([
            'registration_dev_seed.email'    => self::EMAIL,
            'registration_dev_seed.name'     => 'Registration Test Member',
            'registration_dev_seed.role'     => 'registration_member',
            'registration_dev_seed.password' => $this->password,
        ]);
    }

    /** Runs the seeder and reports whether it refused (and why), without letting a PHPUnit failure be mistaken for a refusal. */
    private function runSeeder(): array
    {
        $refused = false;
        $message = '';
        try {
            (new RegistrationTeamDevSeeder())->run();
        } catch (RuntimeException $e) {
            $refused = true;
            $message = $e->getMessage();
        }
        return [$refused, $message];
    }

    private function seeded(): ?User
    {
        return User::where('email', self::EMAIL)->first();
    }

    public function test_it_creates_a_single_registration_member_test_account(): void
    {
        [$refused] = $this->runSeeder();

        $this->assertFalse($refused);
        $this->assertSame(1, User::where('email', self::EMAIL)->count());

        $u = $this->seeded();
        $this->assertSame('registration_member', $u->role);
        $this->assertSame('active', $u->status);
        $this->assertNotNull($u->email_verified_at);
        $this->assertTrue((bool) $u->registration_completed);
        $this->assertEquals(0, $u->balance);
        $this->assertTrue(Hash::check($this->password, $u->password));
        $this->assertNotSame($this->password, $u->password, 'the password must be stored hashed');
    }

    public function test_the_lead_role_is_allowed_when_asked_for(): void
    {
        config(['registration_dev_seed.role' => 'registration_lead']);
        [$refused] = $this->runSeeder();

        $this->assertFalse($refused);
        $this->assertSame('registration_lead', $this->seeded()->role);
    }

    public function test_it_is_idempotent_and_never_resets_an_existing_password_or_role(): void
    {
        $this->runSeeder();
        $first = $this->seeded();
        $hash  = $first->password;

        // A second and third run, even with a different password configured, must change nothing and add nothing.
        config(['registration_dev_seed.password' => 'a-different-' . Str::random(20)]);
        [$refused2] = $this->runSeeder();
        [$refused3] = $this->runSeeder();

        $this->assertFalse($refused2);
        $this->assertFalse($refused3);
        $this->assertSame(1, User::where('email', self::EMAIL)->count());
        $this->assertSame($first->id, $this->seeded()->id);
        $this->assertSame($hash, $this->seeded()->password, 'an existing account password must never be reset');
        $this->assertSame('registration_member', $this->seeded()->role);
    }

    public function test_it_refuses_to_run_in_production_staging_or_an_unknown_environment(): void
    {
        foreach (['production', 'staging', 'live', ''] as $env) {
            $this->app['env'] = $env;
            [$refused, $message] = $this->runSeeder();

            $this->assertTrue($refused, "must refuse when APP_ENV is '{$env}'");
            $this->assertStringContainsString('local development only', $message);
            $this->assertSame(0, User::where('email', self::EMAIL)->count(), "no account may be created when APP_ENV is '{$env}'");
        }
    }

    public function test_production_refusal_also_protects_an_account_that_already_exists(): void
    {
        $this->runSeeder();
        $hash = $this->seeded()->password;

        $this->app['env'] = 'production';
        config(['registration_dev_seed.password' => 'changed-' . Str::random(20)]);
        [$refused] = $this->runSeeder();

        $this->assertTrue($refused);
        $this->assertSame($hash, $this->seeded()->password);
    }

    public function test_it_can_only_ever_assign_registration_roles(): void
    {
        foreach (['admin', 'financial', 'marvflow_member', 'marvflow_lead', 'investor', '', 'registration_admin'] as $role) {
            config(['registration_dev_seed.role' => $role]);
            [$refused, $message] = $this->runSeeder();

            $this->assertTrue($refused, "role '{$role}' must be refused");
            $this->assertStringContainsString('registration_member or registration_lead', $message);
            $this->assertNull($this->seeded(), "no account may be created for role '{$role}'");
        }
    }

    public function test_it_requires_a_password_from_the_environment_and_enforces_a_minimum_length(): void
    {
        foreach ([null, '', 'short-pw'] as $bad) {
            config(['registration_dev_seed.password' => $bad]);
            [$refused, $message] = $this->runSeeder();

            $this->assertTrue($refused);
            $this->assertStringContainsString('DEV_REGISTRATION_TEST_PASSWORD', $message);
            $this->assertNull($this->seeded());
        }
    }

    public function test_it_refuses_an_email_that_could_belong_to_a_real_person(): void
    {
        foreach (['someone@gmail.com', 'ceo@smartsysteminvestment.com', 'a@notexample.com', 'not-an-email', ''] as $email) {
            config(['registration_dev_seed.email' => $email]);
            [$refused] = $this->runSeeder();

            $this->assertTrue($refused, "e-mail '{$email}' must be refused");
            $this->assertSame(0, User::count(), "no account may be created for '{$email}'");
        }
    }

    public function test_it_refuses_to_touch_an_existing_admin_financial_or_investor_account(): void
    {
        foreach (['admin', 'financial', 'investor'] as $role) {
            $email = "conflict-{$role}@ssi-dev.test";
            config(['registration_dev_seed.email' => $email]);
            $existing = User::factory()->create(['email' => $email, 'role' => $role, 'status' => 'active', 'password' => bcrypt('original-' . Str::random(12))]);
            $hash = $existing->password;

            [$refused, $message] = $this->runSeeder();

            $this->assertTrue($refused, "must refuse when the address already belongs to a {$role} account");
            $this->assertStringContainsString('non-Registration account', $message);
            $fresh = $existing->fresh();
            $this->assertSame($role, $fresh->role, 'the existing account must keep its role');
            $this->assertSame($hash, $fresh->password, 'the existing account password must not change');
            $this->assertSame(1, User::where('email', $email)->count(), 'no duplicate may be created');
        }
    }

    public function test_the_seeded_account_holds_no_admin_or_financial_ability(): void
    {
        $this->runSeeder();
        $u = $this->seeded();

        $this->assertNotSame('admin', $u->role);
        $this->assertNotSame('financial', $u->role);
        foreach (['financial.review-transactions', 'financial.act-on-transactions', 'financial.adjust-balance', 'financial.correct-records',
                  'financial.manage-positions', 'financial.communicate', 'financial.issue-notices', 'financial.view-audit',
                  'ranks.view', 'ranks.assign', 'loans.decide'] as $ability) {
            $this->assertFalse(Gate::forUser($u)->allows($ability), "the test account must not hold {$ability}");
        }
        $this->assertTrue(Gate::forUser($u)->allows('registration.review'));
    }

    public function test_the_seeded_account_can_log_in_use_the_registration_api_and_nothing_financial(): void
    {
        $this->runSeeder();

        $login = $this->postJson('/api/login', ['email' => self::EMAIL, 'password' => $this->password]);
        $login->assertOk()->assertJsonPath('user.role', 'registration_member');
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $auth = ['Authorization' => 'Bearer ' . $token];
        $this->withHeaders($auth)->getJson('/api/registration/dashboard')->assertOk();
        $this->withHeaders($auth)->getJson('/api/financial/investors')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/admin/withdrawals')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/admin/users')->assertStatus(403);

        // A wrong password still fails: nothing about login was relaxed.
        $this->postJson('/api/login', ['email' => self::EMAIL, 'password' => 'wrong-' . Str::random(12)])->assertStatus(401);
    }

    public function test_it_works_through_the_documented_artisan_command(): void
    {
        $this->artisan('db:seed', ['--class' => RegistrationTeamDevSeeder::class, '--no-interaction' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => RegistrationTeamDevSeeder::class, '--no-interaction' => true])->assertSuccessful();

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
    }

    public function test_it_is_not_wired_into_the_default_seeder_so_it_cannot_run_by_accident(): void
    {
        $default = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
        $this->assertStringNotContainsString('RegistrationTeamDevSeeder', $default);
    }

    public function test_the_repository_contains_no_hard_coded_seeder_password(): void
    {
        $source = file_get_contents(database_path('seeders/RegistrationTeamDevSeeder.php'));
        $config = file_get_contents(config_path('registration_dev_seed.php'));

        $this->assertDoesNotMatchRegularExpression('/[\'"]password[\'"]\s*=>\s*[\'"][^\'"]+[\'"]/', $config, 'the config must not assign a literal password');
        $this->assertStringNotContainsString("'password'               => '", $source);
        $this->assertMatchesRegularExpression("/env\('DEV_REGISTRATION_TEST_PASSWORD'\)/", $config);
    }
}
