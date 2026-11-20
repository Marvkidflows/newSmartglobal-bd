<?php
// LOCATION: tests/Feature/Remediation/CorsAndPasswordSecurityTest.php   (NEW FILE — D-10, D-12)
// D-10: the CORS allow-list is environment driven; production never gets localhost; '*' and malformed entries are ignored; paths are limited to api/*.
// D-12: one password policy (never below 8, bounded above), and the reset endpoint cannot be used to learn which e-mails have accounts.
// WRITTEN, NOT EXECUTED (no vendor/). CorsOrigins::resolve and the policy arithmetic were checked with the standalone harness in verification_harness/.
// Run: php artisan test --filter=CorsAndPasswordSecurityTest

namespace Tests\Feature\Remediation;

use App\Models\User;
use App\Support\CorsOrigins;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CorsAndPasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── D-10 ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_production_allows_only_the_production_site_and_never_localhost(): void
    {
        $o = CorsOrigins::resolve('production', null);
        $this->assertSame(CorsOrigins::PRODUCTION, $o);
        foreach ($o as $origin) $this->assertStringNotContainsString('localhost', $origin);
        $this->assertSame(CorsOrigins::PRODUCTION, CorsOrigins::resolve('', null), 'an empty/unknown APP_ENV is treated as production');
        $this->assertSame(CorsOrigins::PRODUCTION, CorsOrigins::resolve('staging', null));
    }

    public function test_local_development_environments_keep_localhost_access(): void
    {
        foreach (['local', 'development', 'testing', 'LOCAL'] as $env) {
            $o = CorsOrigins::resolve($env, null);
            $this->assertContains('http://localhost:5173', $o, $env);
            $this->assertContains('http://127.0.0.1:5173', $o, $env);
            foreach (CorsOrigins::PRODUCTION as $p) $this->assertContains($p, $o);
        }
    }

    public function test_extra_origins_come_from_the_environment_and_unsafe_entries_are_dropped(): void
    {
        $o = CorsOrigins::resolve('production', ' https://staging.example.test/ , *, javascript:alert(1), ftp://x.test, https://ok.example.test:8443,not a url,,https://staging.example.test');
        $this->assertContains('https://staging.example.test', $o);
        $this->assertContains('https://ok.example.test:8443', $o);
        $this->assertNotContains('*', $o);
        $this->assertSame(1, count(array_keys($o, 'https://staging.example.test', true)), 'no duplicates');
        foreach ($o as $origin) $this->assertMatchesRegularExpression('#^https?://[a-z0-9.-]+(:\d+)?$#i', $origin);
    }

    public function test_cors_config_is_limited_to_api_paths_and_has_no_wildcard_origin(): void
    {
        $c = config('cors');
        $this->assertNotContains('*', $c['paths']);
        $this->assertContains('api/*', $c['paths']);
        $this->assertNotContains('*', $c['allowed_origins']);
        $this->assertTrue($c['supports_credentials']);
    }

    public function test_a_preflight_from_an_unlisted_origin_gets_no_allow_origin_header(): void
    {
        $res = $this->call('OPTIONS', '/api/login', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $this->assertNotSame('https://evil.example.test', $res->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $res->headers->get('Access-Control-Allow-Origin'));
    }

    // ── D-12 ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_password_policy_floor_and_ceiling(): void
    {
        config(['ssi_auth.password_min_length' => 3, 'ssi_auth.password_max_length' => 128, 'ssi_auth.password_require_mixed' => false, 'ssi_auth.password_check_breached' => false]);
        $this->assertSame(8, PasswordPolicy::minLength(), 'the floor of 8 cannot be configured away');

        $v = fn (string $p) => Validator::make(['password' => $p], ['password' => PasswordPolicy::rules()])->passes();
        $this->assertFalse($v(str_repeat('a', 7)));
        $this->assertTrue($v(str_repeat('a', 8)));
        $this->assertTrue($v(str_repeat('a', 128)));
        $this->assertFalse($v(str_repeat('a', 129)), 'bcrypt ignores bytes after 72; an unbounded password is a cheap DoS');
    }

    public function test_mixed_case_and_digit_requirement_applies_only_when_switched_on(): void
    {
        $v = fn (string $p) => Validator::make(['password' => $p], ['password' => PasswordPolicy::rules()])->passes();
        config(['ssi_auth.password_min_length' => 10, 'ssi_auth.password_require_mixed' => false]);
        $this->assertTrue($v('alllowercase'));
        config(['ssi_auth.password_require_mixed' => true]);
        $this->assertFalse($v('alllowercase'));
        $this->assertTrue($v('MixedCase123x'));
    }

    public function test_reset_answers_an_unknown_email_and_a_bad_token_identically(): void
    {
        $real = User::factory()->create(['email' => 'real.person@ssi-test.test', 'password' => Hash::make('Pw-' . bin2hex(random_bytes(8))), 'role' => 'investor']);
        $new  = 'Np-' . bin2hex(random_bytes(10));

        $unknown = $this->postJson('/api/reset-password', ['email' => 'nobody@ssi-test.test', 'token' => 'x', 'password' => $new, 'password_confirmation' => $new]);
        $badTok  = $this->postJson('/api/reset-password', ['email' => $real->email, 'token' => 'x', 'password' => $new, 'password_confirmation' => $new]);

        $this->assertSame($unknown->status(), $badTok->status());
        $this->assertSame($unknown->json(), $badTok->json());
    }

    public function test_the_forgot_password_response_does_not_depend_on_whether_the_account_exists(): void
    {
        $real = User::factory()->create(['email' => 'exists@ssi-test.test', 'role' => 'investor']);
        $a = $this->postJson('/api/forgot-password', ['email' => $real->email]);
        $b = $this->postJson('/api/forgot-password', ['email' => 'absent@ssi-test.test']);
        $this->assertSame($a->status(), $b->status());
        $this->assertSame($a->json(), $b->json());
    }
}
