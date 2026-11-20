<?php
// LOCATION: tests/Feature/Remediation/DemoSeederSafetyTest.php   (NEW FILE — D-01)
//
// Proves that seeding can never create a default administrator / demo account outside local development, and can never overwrite, re-password,
// re-role, delete or otherwise disturb an administrator that already exists (the live administrator account must stay intact and able to log in).
// WRITTEN, NOT EXECUTED by its author (no vendor/ directory and no package install available in that session) — run it before trusting it:
//     php artisan test --filter=DemoSeederSafetyTest
// Everything runs in the isolated per-test in-memory SQLite database (phpunit.xml). No real account, secret or database is touched. The "existing
// administrator" below is a throw-away factory user with a random password that exists only inside the test process.

namespace Tests\Feature\Remediation;

use App\Models\Task;
use App\Models\User;
use Database\Seeders\Concerns\RefusesUnsafeSeeding;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DemoSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** The e-mail the old seeder used for its default administrator. A real administrator may well use it, so the tests put the "live admin" exactly there. */
    private const COLLIDING_ADMIN_EMAIL = 'adminsystem@gmail.com';

    private string $livePassword;
    private User $liveAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->livePassword = 'live-only-' . Str::random(24);
        $this->liveAdmin = User::factory()->create([
            'name'     => 'Existing Administrator',
            'email'    => self::COLLIDING_ADMIN_EMAIL,
            'password' => Hash::make($this->livePassword),
            'role'     => 'admin',
            'status'   => 'active',
        ]);
        $this->liveAdmin->createToken('pre-existing-session');
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────────────────────

    private function adminRow(): array
    {
        return (array) DB::table('users')->where('id', $this->liveAdmin->id)->first();
    }

    private function runSeeder(string $class): array
    {
        try {
            (new $class())->run();
            return [false, ''];
        } catch (RuntimeException $e) {
            return [true, $e->getMessage()];
        }
    }

    /** The administrator that existed before seeding is byte-for-byte unchanged, still the only account at that e-mail, still holds its token, and can still log in. */
    private function assertLiveAdminIntact(array $before): void
    {
        $this->assertSame($before, $this->adminRow(), 'the existing administrator row must not change at all');
        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', [self::COLLIDING_ADMIN_EMAIL])->count());
        $this->assertSame(1, $this->liveAdmin->tokens()->count(), 'existing sessions must not be revoked');
        $this->assertTrue(Hash::check($this->livePassword, $this->adminRow()['password']));

        $login = $this->postJson('/api/login', ['email' => self::COLLIDING_ADMIN_EMAIL, 'password' => $this->livePassword]);
        $login->assertOk()->assertJsonPath('user.role', 'admin');
    }

    // ── refusal outside local development ──────────────────────────────────────────────────────────────────────

    public function test_demo_seeder_refuses_in_production_staging_and_unknown_environments_and_writes_nothing(): void
    {
        $before = $this->adminRow();
        $users  = User::count();

        foreach (['production', 'staging', 'live', ''] as $env) {
            $this->app['env'] = $env;
            [$refused, $message] = $this->runSeeder(DemoDataSeeder::class);

            $this->assertTrue($refused, "must refuse when APP_ENV is '{$env}'");
            $this->assertStringContainsString('local development only', $message);
            $this->assertSame($users, User::count(), "no account may be created when APP_ENV is '{$env}'");
            $this->assertSame(0, DB::table('task_types')->count());
            $this->assertSame(0, Task::count());
        }

        $this->app['env'] = 'testing';
        $this->assertLiveAdminIntact($before);
    }

    public function test_default_seeder_in_production_creates_no_demo_accounts_and_leaves_the_existing_admin_untouched(): void
    {
        $before = $this->adminRow();
        $this->app['env'] = 'production';

        (new DatabaseSeeder())->run();

        $this->assertSame(1, User::count(), 'production seeding must not add any user');
        $this->assertSame(0, User::where('email', 'demo@investor.com')->count());
        $this->assertSame(0, DB::table('investment_plans')->count(), 'sample plans carry profit percentages and are not seeded outside development');
        $this->assertSame(0, Task::count());
        $this->assertGreaterThan(0, DB::table('task_types')->count(), 'reference data (task types) is still seeded');

        $this->app['env'] = 'testing';
        $this->assertLiveAdminIntact($before);
    }

    public function test_artisan_db_seed_in_production_cannot_disturb_the_existing_admin(): void
    {
        $before = $this->adminRow();
        $this->app['env'] = 'production';

        // --force is what an operator must pass in production; it must still be harmless.
        $this->artisan('db:seed', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--force' => true, '--no-interaction' => true])->assertSuccessful();   // and re-running it too

        $this->assertSame(1, User::count());
        $this->app['env'] = 'testing';
        $this->assertLiveAdminIntact($before);
    }

    public function test_demo_seeder_run_directly_in_production_via_artisan_is_refused_and_changes_nothing(): void
    {
        $before = $this->adminRow();
        $this->app['env'] = 'production';

        // The console kernel lets the refusal propagate (it is not swallowed into a success exit code).
        $refused = false;
        try {
            Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true, '--no-interaction' => true]);
        } catch (RuntimeException $e) {
            $refused = true;
            $this->assertStringContainsString('local development only', $e->getMessage());
        }

        $this->assertTrue($refused, 'the seeder must not report success in production');
        $this->assertSame(1, User::count());
        $this->app['env'] = 'testing';
        $this->assertLiveAdminIntact($before);
    }

    public function test_it_refuses_a_remote_database_even_when_app_env_says_local(): void
    {
        $before = $this->adminRow();
        $default = config('database.default');
        $this->app['env'] = 'local';

        try {
            config([
                'database.default' => 'remote_probe',
                'database.connections.remote_probe' => ['driver' => 'mysql', 'host' => 'db-proxy.example-host.net', 'database' => 'x'],
            ]);
            [$refused, $message] = $this->runSeeder(DemoDataSeeder::class);
        } finally {
            config(['database.default' => $default]);
            $this->app['env'] = 'testing';
        }

        $this->assertTrue($refused);
        $this->assertStringContainsString('not on this machine', $message);
        $this->assertSame(1, User::count());
        $this->assertLiveAdminIntact($before);
    }

    public function test_local_database_host_classification(): void
    {
        $host = new class { use RefusesUnsafeSeeding; };
        $is = fn (?string $h) => $host::isLocalDatabaseHost($h);

        foreach (['', null, 'localhost', '127.0.0.1', '::1', 'host.docker.internal', 'mysql', 'db.local', 'ssi.test', '/var/run/mysqld/mysqld.sock'] as $local) {
            $this->assertTrue($is($local), 'should be local: ' . var_export($local, true));
        }
        foreach (['viaduct.proxy.rlwy.net', 'mysql.railway.internal', '203.0.113.7', 'db.example.com'] as $remote) {
            $this->assertFalse($is($remote), "should be remote: {$remote}");
        }
    }

    // ── behaviour in local development ─────────────────────────────────────────────────────────────────────────

    public function test_in_local_development_an_existing_admin_at_the_default_email_is_left_exactly_as_it_is(): void
    {
        $before = $this->adminRow();

        [$refused] = $this->runSeeder(DemoDataSeeder::class);

        $this->assertFalse($refused);
        $this->assertSame(0, Task::count(), 'demo tasks must not be attached to an administrator the seeder did not create');
        $this->assertSame(1, User::where('email', 'demo@investor.com')->count(), 'the demo investor is independent and may still be created');
        $this->assertLiveAdminIntact($before);
    }

    public function test_in_local_development_demo_accounts_get_random_one_time_passwords_and_rerun_is_a_no_op(): void
    {
        $this->liveAdmin->tokens()->delete();
        $this->liveAdmin->delete();        // test-created user only: lets the seeder create its own admin in this isolated database

        Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/adminsystem@gmail\.com\s+One-time password: (\S{16,})/s', $output);
        preg_match('/adminsystem@gmail\.com\s+One-time password: (\S+)/s', $output, $m);
        $printed = $m[1];

        $admin = User::where('email', self::COLLIDING_ADMIN_EMAIL)->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertStringStartsWith('$2y$', $admin->password, 'stored hashed');
        $this->assertTrue(Hash::check($printed, $admin->password), 'the printed one-time password is the real one — there is no fixed default');
        $this->postJson('/api/login', ['email' => self::COLLIDING_ADMIN_EMAIL, 'password' => $printed])->assertOk();

        $this->assertSame(3, DB::table('task_types')->count());
        $this->assertSame(3, Task::count());

        $snapshot = [DB::table('users')->orderBy('id')->get()->all(), Task::count(), DB::table('task_types')->count()];
        Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--no-interaction' => true]);
        Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--no-interaction' => true]);
        $this->assertEquals($snapshot, [DB::table('users')->orderBy('id')->get()->all(), Task::count(), DB::table('task_types')->count()], 're-running changes and adds nothing');
        $this->assertStringNotContainsString('One-time password', Artisan::output());
    }

    public function test_default_seeder_in_local_development_is_idempotent_for_plans_and_task_types(): void
    {
        $this->artisan('db:seed', ['--no-interaction' => true])->assertSuccessful();
        $plans = DB::table('investment_plans')->count();
        $types = DB::table('task_types')->count();
        $this->assertSame(4, $plans);

        $this->artisan('db:seed', ['--no-interaction' => true])->assertSuccessful();
        $this->assertSame($plans, DB::table('investment_plans')->count());
        $this->assertSame($types, DB::table('task_types')->count());
    }

    // ── static guards on the source ────────────────────────────────────────────────────────────────────────────

    public function test_seeders_contain_no_hard_coded_password_and_no_destructive_operation(): void
    {
        foreach (['DemoDataSeeder', 'DatabaseSeeder', 'InvestmentPlanSeeder', 'Concerns/RefusesUnsafeSeeding'] as $file) {
            $source = file_get_contents(database_path("seeders/{$file}.php"));
            $code   = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $source);   // ignore comments

            $this->assertDoesNotMatchRegularExpression('/Hash::make\(\s*[\'"]/', $code, "{$file}: a literal password is passed to Hash::make");
            $this->assertDoesNotMatchRegularExpression('/[\'"]password[\'"]\s*=>\s*[\'"]/', $code, "{$file}: a literal password is assigned");
            $this->assertDoesNotMatchRegularExpression('/->(delete|forceDelete|update|save|fill|forceFill)\(|truncate\(|updateOrCreate\(|DB::(table|statement|unprepared)\(/', $code, "{$file}: it must only insert missing rows");
        }
    }
}
