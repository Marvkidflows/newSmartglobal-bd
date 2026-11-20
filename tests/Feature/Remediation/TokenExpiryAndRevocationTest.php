<?php
// LOCATION: tests/Feature/Remediation/TokenExpiryAndRevocationTest.php   (NEW FILE — D-02)
//
// Proves: API tokens now expire (per role, plus a hard ceiling that also covers tokens issued before this change); an expired token is refused with 401;
// and tokens are revoked on password reset, on staff/investor password change (other sessions only), and on an admin changing someone's sign-in e-mail
// or deactivating them. Logging in and out still works.
// WRITTEN, NOT EXECUTED by its author (no vendor/ directory, no package install possible) — run before trusting:
//     php artisan test --filter=TokenExpiryAndRevocationTest
// All users are throw-away factory users with random passwords in the isolated in-memory database. Real bearer tokens are used (not actingAs) so the
// Sanctum guard, including its expiry check, is exercised. Time is moved with Carbon::setTestNow and always restored.

namespace Tests\Feature\Remediation;

use App\Models\User;
use App\Support\AuthTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TokenExpiryAndRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ssi_auth.staff_token_lifetime_minutes' => 480, 'ssi_auth.investor_token_lifetime_minutes' => 10080, 'ssi_auth.token_ceiling_minutes' => 10080, 'sanctum.expiration' => 10080]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────────────────────

    private function makeUser(string $role, string $password): User
    {
        return User::factory()->create([
            'email'                  => Str::lower(Str::random(10)) . '@ssi-test.test',
            'password'               => Hash::make($password),
            'role'                   => $role,
            'status'                 => 'active',
            'registration_completed' => true,
            'email_verified_at'      => now(),
        ]);
    }

    private function pw(): string
    {
        return 'Pw-' . Str::random(20);
    }

    private function loginToken(User $u, string $password): string
    {
        $this->app['auth']->forgetGuards();
        $res = $this->postJson('/api/login', ['email' => $u->email, 'password' => $password]);
        $res->assertOk();
        $token = $res->json('token');
        $this->assertNotEmpty($token);
        return $token;
    }

    private function callAs(string $token, string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();   // the test app caches the resolved user per guard; each call must be authenticated afresh
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])->json($method, $uri, $data);
    }

    private function tokenRow(string $plain): ?PersonalAccessToken
    {
        return PersonalAccessToken::findToken($plain);
    }

    // ── expiry ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_login_issues_expiring_tokens_with_a_shorter_lifetime_for_staff(): void
    {
        foreach (['admin' => 480, 'financial' => 480, 'marvflow_member' => 480, 'registration_member' => 480, 'investor' => 10080] as $role => $minutes) {
            $pw = $this->pw();
            $u  = $this->makeUser($role, $pw);
            $row = $this->tokenRow($this->loginToken($u, $pw));

            $this->assertNotNull($row->expires_at, "{$role}: the token must carry an expiry");
            $this->assertEqualsWithDelta($minutes * 60, now()->diffInSeconds($row->expires_at, false), 5, "{$role}: lifetime should be {$minutes} minutes");
        }
    }

    public function test_lifetime_settings_can_never_mean_no_expiry_and_never_exceed_the_ceiling(): void
    {
        $staff = $this->makeUser('admin', $this->pw());
        $inv   = $this->makeUser('investor', $this->pw());

        config(['ssi_auth.staff_token_lifetime_minutes' => 0, 'ssi_auth.investor_token_lifetime_minutes' => -5]);
        $this->assertSame(480, AuthTokens::lifetimeMinutes($staff), '0 falls back to the default, not to "never"');
        $this->assertSame(10080, AuthTokens::lifetimeMinutes($inv));

        config(['ssi_auth.staff_token_lifetime_minutes' => 999999, 'ssi_auth.token_ceiling_minutes' => 600]);
        $this->assertSame(600, AuthTokens::lifetimeMinutes($staff), 'a lifetime above the ceiling is capped');
    }

    public function test_a_token_works_until_it_expires_and_is_refused_with_401_afterwards(): void
    {
        $pw = $this->pw();
        $u  = $this->makeUser('admin', $pw);
        $t  = $this->loginToken($u, $pw);

        $this->callAs($t, 'GET', '/api/user')->assertOk();

        Carbon::setTestNow(now()->addMinutes(479));
        $this->callAs($t, 'GET', '/api/user')->assertOk();

        Carbon::setTestNow(now()->addMinutes(2));   // 481 minutes after login
        $this->callAs($t, 'GET', '/api/user')->assertStatus(401);
    }

    public function test_investor_token_outlives_the_staff_lifetime_but_not_its_own(): void
    {
        $pw = $this->pw();
        $u  = $this->makeUser('investor', $pw);
        $t  = $this->loginToken($u, $pw);

        Carbon::setTestNow(now()->addMinutes(600));
        $this->callAs($t, 'GET', '/api/user')->assertOk();

        Carbon::setTestNow(now()->addMinutes(10080));
        $this->callAs($t, 'GET', '/api/user')->assertStatus(401);
    }

    public function test_a_legacy_token_with_no_expiry_of_its_own_is_ended_by_the_ceiling(): void
    {
        $u = $this->makeUser('admin', $this->pw());
        $legacy = $u->createToken('issued-before-d02');                       // expires_at is NULL, exactly like every token issued before this fix
        $this->assertNull($legacy->accessToken->expires_at);

        $this->callAs($legacy->plainTextToken, 'GET', '/api/user')->assertOk();

        DB::table('personal_access_tokens')->where('id', $legacy->accessToken->id)->update(['created_at' => now()->subMinutes(10081)]);
        $this->callAs($legacy->plainTextToken, 'GET', '/api/user')->assertStatus(401);
    }

    public function test_login_and_logout_still_work_and_logout_revokes_only_that_token(): void
    {
        $pw = $this->pw();
        $u  = $this->makeUser('admin', $pw);
        $a  = $this->loginToken($u, $pw);
        $b  = $this->loginToken($u, $pw);

        $this->callAs($a, 'POST', '/api/logout')->assertOk();
        $this->assertNull($this->tokenRow($a));
        $this->callAs($b, 'GET', '/api/user')->assertOk();
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'wrong-' . Str::random(8)])->assertStatus(401);
    }

    // ── revocation ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_password_reset_revokes_every_existing_token_and_the_new_password_works(): void
    {
        $old = $this->pw();
        $new = $this->pw();
        $u   = $this->makeUser('investor', $old);
        $t1  = $this->loginToken($u, $old);
        $t2  = $this->loginToken($u, $old);

        $this->postJson('/api/reset-password', [
            'email' => $u->email, 'token' => Password::createToken($u), 'password' => $new, 'password_confirmation' => $new,
        ])->assertOk();

        $this->assertSame(0, $u->tokens()->count(), 'all sessions end on reset');
        $this->callAs($t1, 'GET', '/api/user')->assertStatus(401);
        $this->callAs($t2, 'GET', '/api/user')->assertStatus(401);

        $this->postJson('/api/login', ['email' => $u->email, 'password' => $old])->assertStatus(401);
        $this->assertNotEmpty($this->loginToken($u, $new));
    }

    public function test_a_failed_reset_with_a_bad_token_revokes_nothing(): void
    {
        $pw = $this->pw();
        $u  = $this->makeUser('investor', $pw);
        $t  = $this->loginToken($u, $pw);

        $np = $this->pw();
        $this->postJson('/api/reset-password', ['email' => $u->email, 'token' => 'not-a-real-token', 'password' => $np, 'password_confirmation' => $np])->assertStatus(400);

        $this->assertSame(1, $u->tokens()->count());
        $this->callAs($t, 'GET', '/api/user')->assertOk();
    }

    public function test_financial_and_marvflow_password_change_signs_out_other_sessions_only(): void
    {
        foreach (['financial' => '/api/financial/profile/password', 'marvflow_member' => '/api/marvflow/profile/password'] as $role => $uri) {
            $old = $this->pw();
            $new = $this->pw();
            $u   = $this->makeUser($role, $old);
            $current = $this->loginToken($u, $old);
            $other   = $this->loginToken($u, $old);

            $this->callAs($current, 'POST', $uri, ['current_password' => 'wrong-' . Str::random(6), 'new_password' => $new, 'new_password_confirmation' => $new])->assertStatus(422);
            $this->assertSame(2, $u->tokens()->count(), "{$role}: a rejected change must revoke nothing");

            $this->callAs($current, 'POST', $uri, ['current_password' => $old, 'new_password' => $new, 'new_password_confirmation' => $new])->assertOk();

            $this->assertNotNull($this->tokenRow($current), "{$role}: the session that made the change stays signed in");
            $this->assertNull($this->tokenRow($other), "{$role}: every other session is signed out");
            $this->callAs($other, 'GET', '/api/user')->assertStatus(401);
            $this->callAs($current, 'GET', '/api/user')->assertOk();
        }
    }

    public function test_investor_password_change_signs_out_other_sessions_but_a_plain_profile_edit_does_not(): void
    {
        $old = $this->pw();
        $new = $this->pw();
        $u   = $this->makeUser('investor', $old);
        $current = $this->loginToken($u, $old);
        $other   = $this->loginToken($u, $old);

        $this->callAs($current, 'PUT', '/api/investor-investment/investor/profile', ['name' => 'Renamed Person'])->assertOk();
        $this->assertSame(2, $u->tokens()->count(), 'a non-security edit must not sign anyone out');

        $this->callAs($current, 'PUT', '/api/investor-investment/investor/profile', ['current_password' => $old, 'new_password' => $new, 'new_password_confirmation' => $new])->assertOk();
        $this->assertNotNull($this->tokenRow($current));
        $this->assertNull($this->tokenRow($other));
        $this->assertTrue(Hash::check($new, $u->fresh()->password));
    }

    public function test_investor_email_change_signs_out_other_sessions(): void
    {
        $pw = $this->pw();
        $u  = $this->makeUser('investor', $pw);
        $current = $this->loginToken($u, $pw);
        $other   = $this->loginToken($u, $pw);

        $this->callAs($current, 'PUT', '/api/investor-investment/investor/profile', ['email' => 'changed.' . Str::lower(Str::random(6)) . '@ssi-test.test'])->assertOk();

        $this->assertNotNull($this->tokenRow($current));
        $this->assertNull($this->tokenRow($other));
    }

    public function test_admin_changing_a_users_email_or_deactivating_them_ends_that_users_sessions_not_the_admins(): void
    {
        $apw = $this->pw();
        $ipw = $this->pw();
        $admin    = $this->makeUser('admin', $apw);
        $investor = $this->makeUser('investor', $ipw);
        $adminTok = $this->loginToken($admin, $apw);
        $invTok1  = $this->loginToken($investor, $ipw);

        // A name-only edit leaves the investor signed in.
        $this->callAs($adminTok, 'PUT', "/api/admin/users/{$investor->id}", ['name' => 'Edited By Admin'])->assertOk();
        $this->assertSame(1, $investor->tokens()->count());

        // An e-mail change ends the investor's sessions.
        $this->callAs($adminTok, 'PUT', "/api/admin/users/{$investor->id}", ['email' => 'moved.' . Str::lower(Str::random(6)) . '@ssi-test.test'])->assertOk();
        $this->assertSame(0, $investor->tokens()->count());
        $this->callAs($invTok1, 'GET', '/api/user')->assertStatus(401);

        // Deactivation through the generic update also ends sessions.
        $inv2 = $this->makeUser('investor', $ipw);
        $t2   = $this->loginToken($inv2, $ipw);
        $this->callAs($adminTok, 'PUT', "/api/admin/users/{$inv2->id}", ['status' => 'deactivated'])->assertOk();
        $this->assertSame(0, $inv2->tokens()->count());
        $this->callAs($t2, 'GET', '/api/user')->assertStatus(401);

        // The administrator's own session was never touched.
        $this->assertNotNull($this->tokenRow($adminTok));
        $this->callAs($adminTok, 'GET', '/api/user')->assertOk();
    }

    public function test_existing_administrator_can_still_log_in_and_keeps_role_and_password(): void
    {
        $pw    = $this->pw();
        $admin = $this->makeUser('admin', $pw);
        $hash  = $admin->password;

        $token = $this->loginToken($admin, $pw);
        $this->callAs($token, 'GET', '/api/user')->assertOk()->assertJsonPath('role', 'admin');

        $fresh = $admin->fresh();
        $this->assertSame($hash, $fresh->password, 'issuing a token must not touch the password');
        $this->assertSame('admin', $fresh->role);
    }

    // ── static guard ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_no_controller_mints_a_token_outside_the_expiring_helper(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php' && str_contains(file_get_contents($file->getPathname()), '->createToken(')) {
                $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders, 'issue tokens through App\Support\AuthTokens::issue() so they always expire');
    }
}
