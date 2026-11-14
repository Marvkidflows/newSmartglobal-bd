<?php
// LOCATION: tests/Feature/Registration/RegistrationResumeSecurityTest.php
//
// Task 2 security correction — an unauthorised person must not be able to change the password of, or obtain a login token for, an
// unfinished registration just by knowing its email address; and the legitimate owner must still be able to finish registering.
// STATUS: written; see the corrected Task 2 report for execution status.

namespace Tests\Feature\Registration;

use App\Mail\RegistrationResumeCodeMail;
use App\Models\RegistrationEvent;
use App\Models\RegistrationResumeChallenge;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class RegistrationResumeSecurityTest extends RegistrationTestCase
{
    use \Tests\Concerns\RegistersWithEmailCode;   // CONTRACT CHANGE (enumeration closure): new accounts exist only after /register/verify

    private const VICTIM_EMAIL    = 'victim.owner@example.test';
    private const VICTIM_PASSWORD = 'Victim-Original-Pass-1';
    private const ATTACKER_PASS   = 'Attacker-Chosen-Pass-1';
    private const OWNER_NEW_PASS  = 'Owner-New-Pass-9';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();   // nothing in this file may reach a real mail provider
        \Illuminate\Support\Facades\Http::fake();   // ...nor Telegram (new-registration notification)
        $this->app->bind(OtpService::class, fn () => new class extends OtpService {
            public function generateAndSend(User $user): void {}
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function victim(array $over = []): User
    {
        return User::factory()->create($over + [
            'role' => 'investor', 'status' => 'active', 'email' => self::VICTIM_EMAIL, 'name' => 'Victim Owner', 'full_name' => 'Victim Owner',
            'phone' => '+12015550199', 'country_iso2' => 'US', 'password' => bcrypt(self::VICTIM_PASSWORD), 'balance' => 0,
            'registration_stage' => 1, 'registration_completed' => false, 'email_verified_at' => null,
        ]);
    }

    private function attackerPayload(string $email = self::VICTIM_EMAIL, array $over = []): array
    {
        return $over + [
            'full_name' => 'Mallory Attacker', 'email' => $email, 'country_iso2' => 'US', 'phone' => '2015550123',
            'password' => self::ATTACKER_PASS, 'password_confirmation' => self::ATTACKER_PASS,
        ];
    }

    private function requestCode(string $email = self::VICTIM_EMAIL, array $over = [])
    {
        return $this->postJson('/api/register/stage1', $this->attackerPayload($email, $over));
    }

    /** The code most recently emailed (read from the captured mail — it is never returned by the API and is stored only as a hash). */
    private function lastCode(): ?string
    {
        $code = null;
        Mail::assertSent(RegistrationResumeCodeMail::class, function ($m) use (&$code) { $code = $m->code; return true; });
        return $code;
    }

    private function verify(string $code, array $over = [], string $email = self::VICTIM_EMAIL)
    {
        return $this->postJson('/api/register/resume/verify', $over + [
            'email' => $email, 'code' => $code, 'full_name' => 'Verified Owner', 'country_iso2' => 'US', 'phone' => '2015550177',
            'password' => self::OWNER_NEW_PASS, 'password_confirmation' => self::OWNER_NEW_PASS,
        ]);
    }

    private function wrongCodeFor(string $real): string
    {
        return $real === '000000' ? '111111' : '000000';
    }

    private function assertAccountUntouched(User $victim): void
    {
        $fresh = $victim->fresh();
        $this->assertTrue(Hash::check(self::VICTIM_PASSWORD, $fresh->password), 'original password must still work');
        $this->assertFalse(Hash::check(self::ATTACKER_PASS, $fresh->password), 'attacker password must not have been set');
        $this->assertFalse(Hash::check(self::OWNER_NEW_PASS, $fresh->password));
        $this->assertSame('Victim Owner', $fresh->name);
        $this->assertSame('+12015550199', $fresh->phone);
        $this->assertNull($fresh->email_verified_at);
        $this->assertNull($fresh->privacy_policy_version);
    }

    private function bearer(string $token, string $url)
    {
        $this->app['auth']->forgetGuards();
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson($url);
        $this->flushHeaders();
        return $res;
    }

    // ── 2. an unauthorised person gets neither a password change nor a token ─

    public function test_knowing_the_email_changes_nothing_and_returns_no_token(): void
    {
        $victim = $this->victim();

        $res = $this->requestCode()->assertOk();
        $res->assertJson(['success' => true, 'verification_required' => true])->assertJsonMissingPath('token');

        $this->assertAccountUntouched($victim);
        $this->assertSame(0, \DB::table('personal_access_tokens')->count(), 'no session of any kind may be issued');
        Mail::assertSent(RegistrationResumeCodeMail::class, fn ($m) => $m->hasTo(self::VICTIM_EMAIL));
        Mail::assertSent(RegistrationResumeCodeMail::class, 1);
        $this->assertStringNotContainsString($this->lastCode(), $res->getContent(), 'the code is never returned to the caller');
    }

    public function test_the_attacker_cannot_log_in_or_use_any_authenticated_endpoint_afterwards(): void
    {
        $victim = $this->victim();
        $this->requestCode()->assertOk();

        $this->postJson('/api/login', ['email' => self::VICTIM_EMAIL, 'password' => self::ATTACKER_PASS])->assertStatus(401)->assertJsonMissingPath('token');
        $login = $this->postJson('/api/login', ['email' => self::VICTIM_EMAIL, 'password' => self::VICTIM_PASSWORD]);
        $this->assertNotSame(200, $login->getStatusCode(), 'an unfinished registration cannot log in at all');
        $login->assertJsonMissingPath('token');

        $this->getJson('/api/user')->assertStatus(401);
        $this->postJson('/api/register/stage2', ['date_of_birth' => '1990-01-01', 'residential_address' => 'x', 'city' => 'y'])->assertStatus(401);
        $this->assertAccountUntouched($victim);
    }

    public function test_a_wrong_code_gives_no_token_and_changes_nothing(): void
    {
        $victim = $this->victim();
        $this->requestCode()->assertOk();
        $real = $this->lastCode();

        $this->verify($this->wrongCodeFor($real))->assertStatus(422)->assertJsonMissingPath('token');
        $this->assertAccountUntouched($victim);
        $this->assertSame(0, \DB::table('personal_access_tokens')->count());
    }

    public function test_the_code_is_destroyed_after_the_attempt_cap_even_for_the_correct_code(): void
    {
        $victim = $this->victim();
        $this->requestCode()->assertOk();
        $real = $this->lastCode();
        $wrong = $this->wrongCodeFor($real);

        foreach (range(1, (int) config('registration.resume_code_max_attempts')) as $i) {
            $this->verify($wrong)->assertStatus(422);
        }
        $this->verify($real)->assertStatus(422)->assertJsonMissingPath('token');   // too late: guessing destroyed the code

        $this->assertAccountUntouched($victim);
        $this->assertTrue(RegistrationEvent::where('event_type', 'resume_code_locked')->exists(), 'the team can see a lock-out');
    }

    public function test_an_expired_code_is_refused(): void
    {
        $victim = $this->victim();
        $this->requestCode()->assertOk();
        $real = $this->lastCode();
        RegistrationResumeChallenge::query()->update(['expires_at' => now()->subSecond()]);

        $this->verify($real)->assertStatus(422)->assertJsonMissingPath('token');
        $this->assertAccountUntouched($victim);
    }

    public function test_a_code_works_once_only(): void
    {
        $this->victim();
        $this->requestCode()->assertOk();
        $real = $this->lastCode();

        $this->verify($real)->assertOk();
        $this->verify($real, ['password' => 'Second-Use-Pass-1', 'password_confirmation' => 'Second-Use-Pass-1'])->assertStatus(422);

        $user = User::where('email', self::VICTIM_EMAIL)->first();
        $this->assertTrue(Hash::check(self::OWNER_NEW_PASS, $user->password));
        $this->assertFalse(Hash::check('Second-Use-Pass-1', $user->password));
    }

    public function test_a_newly_issued_code_invalidates_the_previous_one(): void
    {
        $victim = $this->victim();
        $this->requestCode()->assertOk();
        $first = $this->lastCode();

        $this->travel(61)->seconds();
        Mail::fake();
        $this->requestCode()->assertOk();
        $second = $this->lastCode();
        if ($first === $second) $this->markTestSkipped('Two identical random codes (1 in 1,000,000).');

        $this->verify($first)->assertStatus(422);
        $this->assertAccountUntouched($victim);
        $this->verify($second)->assertOk();
    }

    public function test_one_accounts_code_does_not_unlock_another_account(): void
    {
        $a = $this->victim();
        $b = $this->victim(['email' => 'second.victim@example.test', 'phone' => '+12015550188']);
        $this->requestCode('second.victim@example.test')->assertOk();
        $codeForB = $this->lastCode();

        $this->verify($codeForB, [], self::VICTIM_EMAIL)->assertStatus(422);   // B's code against A's email
        $this->assertAccountUntouched($a);
        $this->assertTrue(Hash::check(self::VICTIM_PASSWORD, $b->fresh()->password));
    }

    public function test_every_failure_looks_identical_so_the_endpoint_is_not_an_account_oracle(): void
    {
        $this->victim();
        $completed = User::factory()->create(['role' => 'investor', 'status' => 'active', 'email' => 'done@example.test', 'registration_completed' => true]);
        $this->requestCode()->assertOk();
        $wrong = $this->wrongCodeFor($this->lastCode());

        $responses = [
            $this->verify($wrong),                                          // eligible account, wrong code
            $this->verify('123456', [], 'nobody.here@example.test'),        // no such account
            $this->verify('123456', [], 'done@example.test'),               // completed account
        ];
        foreach ($responses as $r) {
            $r->assertStatus(422);
        }
        $this->assertCount(1, array_unique(array_map(fn ($r) => $r->getContent(), $responses)), 'bodies must be byte-identical');
    }

    // ── staff accounts must never be reachable through the resume path ───────

    public function test_staff_accounts_flagged_incomplete_cannot_be_taken_over(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);   // stage1 and verify share ONE 10/min route bucket per IP; this loop makes more calls
        foreach (['admin', 'financial', 'marvflow_member', 'marvflow_lead', 'registration_member', 'registration_lead'] as $role) {
            $email = "{$role}.staff@example.test";
            $staff = User::factory()->create([
                'role' => $role, 'status' => 'active', 'email' => $email, 'password' => bcrypt(self::VICTIM_PASSWORD),
                'registration_completed' => false, 'registration_stage' => 1, 'name' => 'Staff ' . $role,
            ]);

            // CONTRACT CHANGE: stage 1 answers a staff email exactly like any other email (200, generic) — the former 422 was the enumeration oracle.
            $this->requestCode($email)->assertOk()->assertJson(['verification_required' => true])->assertJsonMissingPath('token');
            Mail::assertNothingSent();

            // Even if a challenge existed with a known code (e.g. inserted by an attacker with some other bug), verify must refuse it.
            RegistrationResumeChallenge::create([
                'user_id' => $staff->id, 'code_hash' => RegistrationResumeChallenge::hashCode('123456'), 'attempts' => 0, 'delivery_status' => 'sent',
                'expires_at' => now()->addMinutes(5), 'created_at' => now(),
            ]);
            $this->verify('123456', [], $email)->assertStatus(422)->assertJsonMissingPath('token');

            $fresh = $staff->fresh();
            $this->assertTrue(Hash::check(self::VICTIM_PASSWORD, $fresh->password), "{$role}: password changed!");
            $this->assertSame('Staff ' . $role, $fresh->name, "{$role}: name changed!");
        }
        $this->assertSame(0, \DB::table('personal_access_tokens')->count());
    }

    public function test_a_suspended_investor_cannot_be_resumed(): void
    {
        $victim = $this->victim(['status' => 'suspended']);
        $this->requestCode()->assertOk()->assertJson(['verification_required' => true])->assertJsonMissingPath('token');   // generic, like every email
        Mail::assertNothingSent();
        $this->assertAccountUntouched($victim);
    }

    // ── 3. the legitimate flow still works ───────────────────────────────────

    public function test_a_brand_new_email_creates_no_account_at_stage_one_and_an_account_only_after_the_emailed_code(): void
    {
        // CONTRACT CHANGE (enumeration closure): this used to assert that stage 1 created the account and returned a token.
        $this->requestCode('brand.new@example.test')->assertOk()->assertJson(['success' => true, 'verification_required' => true])->assertJsonMissingPath('token');
        $this->assertDatabaseMissing('users', ['email' => 'brand.new@example.test']);
        Mail::assertSent(\App\Mail\RegistrationEmailCodeMail::class, fn ($m) => $m->hasTo('brand.new@example.test'));

        $this->verifyWith($this->attackerPayload('brand.new@example.test'), $this->emailedCode())->assertOk()->assertJsonStructure(['success', 'token', 'email_verified']);
        $this->assertDatabaseHas('users', ['email' => 'brand.new@example.test', 'role' => 'investor', 'registration_completed' => false]);
        $this->assertNotNull(User::where('email', 'brand.new@example.test')->value('email_verified_at'));
    }

    public function test_the_genuine_owner_can_resume_and_finish_registration(): void
    {
        // 1. Original attempt — the browser later loses the token.
        $first = $this->registerViaCode($this->attackerPayload('owner@example.test', ['full_name' => 'Owner Original', 'phone' => '2015550123']))->assertOk();
        $oldToken = $first->json('token');
        $this->assertNotEmpty($oldToken);
        $owner = User::where('email', 'owner@example.test')->first();

        // 2. Owner returns, types a (new) password: nothing happens yet except a code to their inbox.
        $this->requestCode('owner@example.test', ['password' => self::OWNER_NEW_PASS, 'password_confirmation' => self::OWNER_NEW_PASS, 'phone' => '2015550123'])
            ->assertOk()->assertJson(['verification_required' => true])->assertJsonMissingPath('token');
        $this->assertFalse(Hash::check(self::OWNER_NEW_PASS, $owner->fresh()->password), 'password only changes after the code is entered');

        // 3. Owner enters the code from their email.
        $code = $this->lastCode();
        $res = $this->verify($code, ['email' => 'owner@example.test', 'full_name' => 'Owner Corrected', 'phone' => '2015550123'])
            ->assertOk()->assertJsonStructure(['token', 'registration_stage', 'email_verified']);
        $newToken = $res->json('token');

        $owner->refresh();
        $this->assertTrue(Hash::check(self::OWNER_NEW_PASS, $owner->password));
        $this->assertSame('Owner Corrected', $owner->name);
        $this->assertNotNull($owner->email_verified_at, 'the code proves the mailbox, so the email is now verified');

        // 4. Earlier sessions are dead; the new one works.
        $this->bearer($oldToken, '/api/user')->assertStatus(401);
        $this->bearer($newToken, '/api/user')->assertOk();
        $this->assertSame(1, \DB::table('personal_access_tokens')->where('tokenable_id', $owner->id)->count());

        // 5. The owner completes the remaining stages and can then log in with the new password only.
        $this->app['auth']->forgetGuards();
        $h = ['Authorization' => "Bearer {$newToken}"];
        $this->withHeaders($h)->postJson('/api/register/stage2', ['date_of_birth' => '1990-01-01', 'residential_address' => '1 Test Street', 'city' => 'Lagos'])->assertOk();
        $this->withHeaders($h)->postJson('/api/register/stage3', [
            'employment_status' => 'employed', 'annual_income_range' => '10k-50k', 'source_of_funds' => 'salary',
            'investment_experience' => 'beginner', 'risk_tolerance' => 'low',
        ])->assertOk();
        $this->withHeaders($h)->postJson('/api/register/stage4', [])->assertStatus(201);
        $this->flushHeaders();

        $this->assertTrue((bool) $owner->fresh()->registration_completed);
        $this->postJson('/api/login', ['email' => 'owner@example.test', 'password' => self::OWNER_NEW_PASS])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/login', ['email' => 'owner@example.test', 'password' => self::ATTACKER_PASS])->assertStatus(401);   // the pre-resume password is gone

        // The whole journey is on the audit trail.
        $app = $owner->registrationApplication()->first();
        foreach (['resume_code_requested', 'resume_verified', 'application_submitted'] as $type) {
            $this->assertTrue(RegistrationEvent::where('application_id', $app->id)->where('event_type', $type)->exists(), "missing audit event {$type}");
        }
    }

    public function test_a_session_an_earlier_caller_obtained_is_revoked_when_the_real_owner_resumes(): void
    {
        $victim = $this->victim();
        $stolen = $victim->createToken('registration')->plainTextToken;   // e.g. obtained before this fix, or via a squatted signup
        $this->bearer($stolen, '/api/user')->assertOk();

        $this->requestCode()->assertOk();
        $this->verify($this->lastCode())->assertOk();

        $this->bearer($stolen, '/api/user')->assertStatus(401);
    }

    // ── abuse limits ─────────────────────────────────────────────────────────

    public function test_codes_cannot_be_requested_faster_than_the_cooldown_so_the_owner_inbox_is_not_flooded(): void
    {
        $this->victim();
        foreach (range(1, 4) as $i) $this->requestCode()->assertOk()->assertJson(['verification_required' => true]);
        Mail::assertSent(RegistrationResumeCodeMail::class, 1);
    }

    public function test_hourly_cap_limits_codes_per_account(): void
    {
        config(['registration.resume_requests_per_hour' => 2, 'registration.resume_request_cooldown_sec' => 60]);
        $this->victim();

        foreach (range(1, 4) as $i) { $this->requestCode()->assertOk(); $this->travel(61)->seconds(); }
        Mail::assertSent(RegistrationResumeCodeMail::class, 2);
        $this->assertSame(2, RegistrationResumeChallenge::count());
    }

    // ── round 2, part 1: a failed / ambiguous delivery keeps its rate-limit record and its code is NEVER usable ──

    private function failingMailer(): void
    {
        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('provider down'));
    }

    public function test_a_failed_delivery_is_reported_keeps_its_row_and_marks_it_failed_and_consumed(): void
    {
        $victim = $this->victim();
        $this->failingMailer();

        $this->requestCode()->assertOk()
            ->assertJson(['verification_required' => true, 'email_sent' => true, 'retry_after_seconds' => 60])->assertJsonMissingPath('token');   // CONTRACT CHANGE: identical to a successful send — a different answer would reveal the account exists

        $this->assertSame(1, RegistrationResumeChallenge::count(), 'the row must NOT be deleted — it carries the rate limit');
        $row = RegistrationResumeChallenge::first();
        $this->assertSame('failed', $row->delivery_status);
        $this->assertNotNull($row->consumed_at, 'a failed code is retired at the moment it fails');
        $this->assertAccountUntouched($victim);
    }

    public function test_a_failed_code_can_never_be_used_even_if_it_was_in_fact_delivered(): void
    {
        // Ambiguous delivery: the provider may have accepted the message although our send reported an error. Whatever the owner holds is dead.
        $victim = $this->victim();
        $this->failingMailer();
        $this->requestCode()->assertOk();
        RegistrationResumeChallenge::query()->update(['code_hash' => RegistrationResumeChallenge::hashCode('123456')]);   // pretend '123456' reached them

        $this->verify('123456')->assertStatus(422)->assertJsonMissingPath('token');
        $this->assertAccountUntouched($victim);
        $this->assertSame(0, \DB::table('personal_access_tokens')->count());
    }

    public function test_when_the_status_write_fails_after_a_successful_send_the_code_is_still_unusable(): void
    {
        // The most ambiguous case: the mail really went out, but recording that fact failed. The owner must not be able to use the code.
        $victim = $this->victim();
        RegistrationResumeChallenge::updating(function ($m) {
            if ($m->delivery_status === 'sent') throw new \RuntimeException('database write failed');
        });

        $this->requestCode()->assertOk()->assertJson(['email_sent' => true]);   // generic, whatever happened internally
        $realCode = $this->lastCode();   // it WAS emailed

        $row = RegistrationResumeChallenge::first();
        $this->assertNotSame('sent', $row->delivery_status);
        $this->verify($realCode)->assertStatus(422)->assertJsonMissingPath('token');
        $this->assertAccountUntouched($victim);
    }

    public function test_failed_deliveries_still_enforce_the_per_account_cooldown(): void
    {
        $this->victim();
        Mail::shouldReceive('mailer')->once()->andThrow(new \RuntimeException('provider down'));   // exactly ONE send attempt is allowed

        $first = $this->requestCode()->assertOk();
        $this->assertTrue($first->json('email_sent'), 'the response must not reveal that the send failed (that would reveal that the account exists)');
        foreach (range(1, 4) as $i) {
            $r = $this->requestCode()->assertOk();
            $this->assertSame($first->json(), $r->json(), 'every response is identical');
        }

        $this->assertSame(1, RegistrationResumeChallenge::count(), 'hammering a failing provider must not create more attempts');
    }

    public function test_failed_deliveries_still_count_toward_the_hourly_cap(): void
    {
        config(['registration.resume_requests_per_hour' => 2, 'registration.resume_request_cooldown_sec' => 60]);
        $victim = $this->victim();
        $this->failingMailer();

        foreach (range(1, 4) as $i) { $this->requestCode()->assertOk(); $this->travel(61)->seconds(); }
        $this->assertSame(2, RegistrationResumeChallenge::count(), 'the third and fourth requests were refused by the cap, not sent');

        // Even with a perfectly healthy mailer, the cap still holds until the hour has passed...
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('mail.manager'); app()->forgetInstance('mail.manager');   // drop the failing Mockery mailer first
        Mail::fake();
        $this->requestCode()->assertOk();
        Mail::assertNothingSent();
        $this->assertSame(2, RegistrationResumeChallenge::count());

        // ...and then it lifts.
        $this->travel(61)->minutes();
        $this->requestCode()->assertOk();
        Mail::assertSent(RegistrationResumeCodeMail::class, 1);
        $this->assertAccountUntouched($victim);
    }

    public function test_only_a_sent_unconsumed_unexpired_challenge_is_usable(): void
    {
        $victim = $this->victim();
        $row = fn (string $status, array $over = []) => RegistrationResumeChallenge::create($over + [
            'user_id' => $victim->id, 'code_hash' => RegistrationResumeChallenge::hashCode('123456'), 'attempts' => 0,
            'delivery_status' => $status, 'expires_at' => now()->addMinutes(5), 'created_at' => now(),
        ]);

        foreach (['pending', 'failed', 'something-unknown'] as $bad) {
            RegistrationResumeChallenge::query()->delete();
            $row($bad);
            $this->verify('123456')->assertStatus(422)->assertJsonMissingPath('token');
            $this->assertAccountUntouched($victim);
        }

        RegistrationResumeChallenge::query()->delete();
        $row('sent');   // positive control: the gate is not simply blocking everything
        $this->verify('123456')->assertOk()->assertJsonStructure(['token']);
    }

    // ── round 2, part 2: verification and the credential update are atomic ──

    public function test_if_the_account_update_fails_everything_rolls_back_and_the_code_is_still_valid(): void
    {
        $victim = $this->victim();
        $oldToken = $victim->createToken('registration')->plainTextToken;
        $this->requestCode()->assertOk();
        $code = $this->lastCode();

        $break = true;
        User::updating(function () use (&$break) { if ($break) throw new \RuntimeException('database went away'); });

        $this->verify($code)->assertStatus(503)->assertJsonMissingPath('token');
        $break = false;   // the injected fault is active ONLY for the request above, so nothing below can fail for an unrelated reason

        $challenge = RegistrationResumeChallenge::first();
        $this->assertNull($challenge->consumed_at, 'the code must NOT be consumed by a resume that did not complete');
        $this->assertSame(0, (int) $challenge->attempts, 'a correct code is not counted as a failed attempt');
        $this->assertAccountUntouched($victim);
        $this->assertSame(1, \DB::table('personal_access_tokens')->count(), 'the token revocation was rolled back too');
        $this->bearer($oldToken, '/api/user')->assertOk();

        // The fault has cleared: the SAME code now completes the resume.
        $this->verify($code)->assertOk()->assertJsonStructure(['token']);
        $this->assertTrue(Hash::check(self::OWNER_NEW_PASS, $victim->fresh()->password));
        $this->assertNotNull(RegistrationResumeChallenge::first()->consumed_at);
    }

    public function test_a_phone_conflict_after_a_correct_code_does_not_burn_the_code(): void
    {
        $victim = $this->victim();
        User::factory()->create(['phone' => '+12015550177', 'role' => 'investor', 'email' => 'other.owner@example.test']);   // verify()'s default phone
        $this->requestCode()->assertOk();
        $code = $this->lastCode();

        $this->verify($code)->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->assertNull(RegistrationResumeChallenge::first()->consumed_at);
        $this->assertAccountUntouched($victim);

        $this->verify($code, ['phone' => '2015550166'])->assertOk()->assertJsonStructure(['token']);   // corrected retry, same code
    }

    public function test_a_wrong_guess_is_still_committed_so_the_attempt_cap_cannot_be_dodged(): void
    {
        $this->victim();
        $this->requestCode()->assertOk();
        $wrong = $this->wrongCodeFor($this->lastCode());

        $this->verify($wrong)->assertStatus(422);
        $this->verify($wrong)->assertStatus(422);
        $this->assertSame(2, (int) RegistrationResumeChallenge::first()->attempts);
    }

    public function test_the_service_runs_the_update_only_for_a_correct_code_and_rolls_back_when_it_throws(): void
    {
        $victim = $this->victim();
        $svc = app(\App\Services\Registration\RegistrationResumeService::class);
        $svc->requestCode($victim);
        $code = $this->lastCode();
        $ran = 0;

        $wrong = $svc->verifyAndApply($victim, $this->wrongCodeFor($code), function () use (&$ran) { $ran++; return 'x'; });
        $this->assertFalse($wrong['ok']);
        $this->assertSame(0, $ran, 'the update must never run for a wrong code');

        try {
            $svc->verifyAndApply($victim, $code, function () use (&$ran) { $ran++; throw new \RuntimeException('boom'); });
            $this->fail('the exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertNull(RegistrationResumeChallenge::first()->consumed_at, 'rolled back: the code survives');

        $ok = $svc->verifyAndApply($victim, $code, function () use (&$ran) { $ran++; return 'done'; });
        $this->assertTrue($ok['ok']);
        $this->assertSame('done', $ok['result']);
        $this->assertSame(2, $ran);
        $this->assertNotNull(RegistrationResumeChallenge::first()->consumed_at);
    }

    public function test_eligibility_is_rechecked_on_the_locked_row_not_the_callers_stale_copy(): void
    {
        $victim = $this->victim();
        $svc = app(\App\Services\Registration\RegistrationResumeService::class);
        $svc->requestCode($victim);
        $code = $this->lastCode();

        User::whereKey($victim->id)->update(['status' => 'suspended']);   // changes after the caller loaded its copy
        $ran = false;
        $r = $svc->verifyAndApply($victim, $code, function () use (&$ran) { $ran = true; });

        $this->assertFalse($r['ok']);
        $this->assertFalse($ran, 'a suspended account must not be resumed even with a valid code');
    }


    public function test_only_a_hash_of_the_code_is_stored(): void
    {
        $this->victim();
        $this->requestCode()->assertOk();
        $code = $this->lastCode();

        $row = RegistrationResumeChallenge::first();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertSame(64, strlen($row->code_hash));
        $this->assertSame(RegistrationResumeChallenge::hashCode($code), $row->code_hash);
        $this->assertDatabaseMissing('registration_resume_challenges', ['code_hash' => $code]);
    }

    public function test_verify_request_validation(): void
    {
        $this->victim();
        $this->verify('12345')->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->verify('abcdef')->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->verify('123456', ['password_confirmation' => 'different'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->verify('123456', ['password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_the_phone_duplicate_message_is_not_an_oracle_for_an_unauthenticated_caller_at_verify_time(): void
    {
        $this->victim();
        User::factory()->create(['phone' => '+12015550177', 'role' => 'investor', 'email' => 'someone.else@example.test']);   // number the verify call will try to use

        // Wrong code + a number that belongs to someone else: must be the generic code failure, never the "phone already registered" message.
        $this->requestCode()->assertOk();
        $res = $this->verify($this->wrongCodeFor($this->lastCode()))->assertStatus(422);
        $this->assertStringNotContainsString('phone', strtolower($res->getContent()));
    }
}
