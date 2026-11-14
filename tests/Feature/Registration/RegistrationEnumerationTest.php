<?php
// LOCATION: tests/Feature/Registration/RegistrationEnumerationTest.php
// Registration enumeration closure. WRITTEN, NOT EXECUTED (no PHP runtime where it was authored).
//
// Contract under test:
//   * POST /api/register/stage1 answers every request identically (status, body, message, retry interval) whatever the email or phone is;
//   * it creates no account, issues no token, raises no Telegram alert and sends mail only when a code is due;
//   * the account (or the resume of an unfinished one) happens at POST /api/register/verify after a valid emailed code;
//   * every verify failure is the same 422; the only account-state answer is "phone belongs to someone else" AFTER a correct code, and it is budgeted.

namespace Tests\Feature\Registration;

use App\Mail\RegistrationEmailCodeMail;
use App\Mail\RegistrationResumeCodeMail;
use App\Models\RegistrationEmailChallenge as EmailChallenge;
use App\Models\RegistrationResumeChallenge;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RegistersWithEmailCode;
use Tests\TestCase;

class RegistrationEnumerationTest extends TestCase
{
    use RefreshDatabase;
    use RegistersWithEmailCode;

    private function payload(string $email, string $phone = '2015550123', array $over = []): array
    {
        return $over + ['full_name' => 'Probe Person', 'email' => $email, 'country_iso2' => 'US', 'phone' => $phone,
            'password' => 'Str0ngPassw0rd!', 'password_confirmation' => 'Str0ngPassw0rd!'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);   // the per-IP budget in the controller is what is under test; the route throttle is separate
        Mail::fake();
    }

    private function accountsOfEveryKind(): array
    {
        $mk = fn (array $o) => User::factory()->create($o + ['status' => 'active', 'registration_completed' => false]);
        return [
            'completed'  => $mk(['email' => 'completed@example.test', 'role' => 'investor', 'registration_completed' => true, 'phone' => '+12015550111']),
            'unfinished' => $mk(['email' => 'unfinished@example.test', 'role' => 'investor']),
            'admin'      => $mk(['email' => 'admin@example.test', 'role' => 'admin']),
            'registration' => $mk(['email' => 'regteam@example.test', 'role' => 'registration_member']),
            'suspended'  => $mk(['email' => 'suspended@example.test', 'role' => 'investor', 'status' => 'suspended']),
        ];
    }

    public function test_stage_one_returns_exactly_the_same_response_for_every_kind_of_email(): void
    {
        $this->accountsOfEveryKind();
        $emails = ['new@example.test', 'completed@example.test', 'unfinished@example.test', 'admin@example.test', 'regteam@example.test', 'suspended@example.test'];
        $responses = [];
        foreach ($emails as $e) {
            $r = $this->postJson('/api/register/stage1', $this->payload($e));
            $responses[$e] = [$r->getStatusCode(), $r->json()];
        }
        $first = reset($responses);
        $this->assertSame(200, $first[0]);
        foreach ($responses as $e => $r) {
            $this->assertSame($first, $r, "stage 1 answered differently for {$e}");
        }
        $this->assertArrayNotHasKey('token', $first[1]);
    }

    public function test_the_phone_number_does_not_change_the_stage_one_response(): void
    {
        User::factory()->create(['email' => 'holder@example.test', 'role' => 'investor', 'registration_completed' => true, 'phone' => '+12015550177']);
        $a = $this->postJson('/api/register/stage1', $this->payload('p1@example.test', '2015550177'));   // a number that IS registered
        $b = $this->postJson('/api/register/stage1', $this->payload('p2@example.test', '2015550188'));   // a number that is not
        $this->assertSame(200, $a->getStatusCode());
        $this->assertSame($a->json(), $b->json());
    }

    public function test_stage_one_never_creates_an_account_or_a_token(): void
    {
        $before = User::count();
        $this->postJson('/api/register/stage1', $this->payload('nobody@example.test'))->assertOk();
        $this->assertSame($before, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.test']);
        $this->assertSame(0, \DB::table('personal_access_tokens')->count());
    }

    public function test_mail_is_sent_only_when_a_code_is_due_and_never_for_completed_staff_or_inactive_accounts(): void
    {
        $this->accountsOfEveryKind();
        foreach (['new@example.test', 'completed@example.test', 'unfinished@example.test', 'admin@example.test', 'regteam@example.test', 'suspended@example.test'] as $e) {
            $this->postJson('/api/register/stage1', $this->payload($e))->assertOk();
        }
        Mail::assertSent(RegistrationEmailCodeMail::class, 1);
        Mail::assertSent(RegistrationEmailCodeMail::class, fn ($m) => $m->hasTo('new@example.test'));
        Mail::assertSent(RegistrationResumeCodeMail::class, 1);
        Mail::assertSent(RegistrationResumeCodeMail::class, fn ($m) => $m->hasTo('unfinished@example.test'));
    }

    public function test_the_new_email_code_message_contains_no_text_taken_from_the_request(): void
    {
        $this->postJson('/api/register/stage1', $this->payload('victim.inbox@example.test', '2015550123', ['full_name' => 'CLICK http://evil.example to claim your prize']))->assertOk();
        Mail::assertSent(RegistrationEmailCodeMail::class, function ($m) {
            $html = $m->render();
            return !str_contains($html, 'evil.example') && !str_contains($html, 'prize');
        });
    }

    public function test_no_telegram_alert_at_stage_one_and_exactly_one_after_the_email_is_proven(): void
    {
        $this->mock(TelegramService::class, fn ($m) => $m->shouldReceive('newRegistration')->once());
        $this->postJson('/api/register/stage1', $this->payload('tg@example.test'))->assertOk();   // would fail the mock's "once" later if it alerted here
        $this->verifyWith($this->payload('tg@example.test'), $this->emailedCode())->assertOk();
    }

    public function test_probing_many_addresses_cannot_create_accounts_or_telegram_alerts(): void
    {
        $this->mock(TelegramService::class, fn ($m) => $m->shouldNotReceive('newRegistration'));
        config(['registration.stage1_attempts_per_hour' => 50]);
        foreach (range(1, 20) as $i) $this->postJson('/api/register/stage1', $this->payload("probe{$i}@example.test"))->assertOk();
        $this->assertSame(0, User::count());
    }

    public function test_every_verify_failure_is_the_same_422_whatever_the_account_state(): void
    {
        $this->accountsOfEveryKind();
        $unfinished = User::where('email', 'unfinished@example.test')->first();
        RegistrationResumeChallenge::create(['user_id' => $unfinished->id, 'code_hash' => RegistrationResumeChallenge::hashCode('123456'), 'attempts' => 0,
            'delivery_status' => 'sent', 'expires_at' => now()->addMinutes(5), 'created_at' => now()]);
        $seen = [];
        foreach (['ghost@example.test', 'completed@example.test', 'admin@example.test', 'regteam@example.test', 'suspended@example.test'] as $e) {
            $r = $this->verifyWith($this->payload($e), '123456');
            $seen[$e] = [$r->getStatusCode(), $r->json()];
        }
        $r = $this->verifyWith($this->payload('unfinished@example.test'), '999999');   // existing unfinished account, WRONG code
        $seen['unfinished-wrong'] = [$r->getStatusCode(), $r->json()];
        $first = reset($seen);
        $this->assertSame(422, $first[0]);
        foreach ($seen as $k => $v) $this->assertSame($first, $v, "verify answered differently for {$k}");
    }

    public function test_a_wrong_code_with_an_already_registered_phone_still_gets_the_generic_failure_not_a_phone_error(): void
    {
        User::factory()->create(['email' => 'holder@example.test', 'role' => 'investor', 'registration_completed' => true, 'phone' => '+12015550177']);
        $this->postJson('/api/register/stage1', $this->payload('phone.probe@example.test', '2015550177'))->assertOk();
        $wrong = $this->emailedCode() === '000000' ? '111111' : '000000';
        $r = $this->verifyWith($this->payload('phone.probe@example.test', '2015550177'), $wrong)->assertStatus(422);
        $r->assertJsonMissingValidationErrors(['phone']);
        $this->assertStringNotContainsString('phone', strtolower((string) $r->json('message')));
    }

    public function test_duplicate_phone_is_refused_only_after_the_code_is_proven_and_the_code_stays_valid(): void
    {
        User::factory()->create(['email' => 'holder@example.test', 'role' => 'investor', 'registration_completed' => true, 'phone' => '+12015550177']);
        $this->postJson('/api/register/stage1', $this->payload('dup@example.test', '2015550177'))->assertOk();
        $code = $this->emailedCode();

        $this->verifyWith($this->payload('dup@example.test', '2015550177'), $code)->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->assertDatabaseMissing('users', ['email' => 'dup@example.test']);

        $this->verifyWith($this->payload('dup@example.test', '2015550188'), $code)->assertOk();   // same code, corrected phone
        $this->assertDatabaseHas('users', ['email' => 'dup@example.test', 'phone' => '+12015550188']);
    }

    public function test_the_phone_conflict_answer_is_budgeted_per_ip(): void
    {
        User::factory()->create(['email' => 'holder@example.test', 'role' => 'investor', 'registration_completed' => true, 'phone' => '+12015550177']);
        config(['registration.disclosure_limit_per_hour' => 2, 'registration.resume_request_cooldown_sec' => 0, 'registration.resume_requests_per_hour' => 50]);
        $statuses = [];
        foreach (range(1, 4) as $i) {
            $e = "budget{$i}@example.test";
            $this->postJson('/api/register/stage1', $this->payload($e, '2015550177'))->assertOk();
            $statuses[] = $this->verifyWith($this->payload($e, '2015550177'), $this->emailedCode())->getStatusCode();
        }
        $this->assertSame([422, 422, 429, 429], $statuses);
    }

    public function test_a_second_request_inside_the_cooldown_sends_nothing_and_looks_the_same(): void
    {
        $a = $this->postJson('/api/register/stage1', $this->payload('cool@example.test'));
        $b = $this->postJson('/api/register/stage1', $this->payload('cool@example.test'));
        $this->assertSame($a->json(), $b->json());
        Mail::assertSent(RegistrationEmailCodeMail::class, 1);
        $this->assertSame(1, EmailChallenge::where('email', 'cool@example.test')->count());
    }

    public function test_the_hourly_cap_limits_codes_per_address_even_across_ip_addresses(): void
    {
        config(['registration.resume_requests_per_hour' => 2, 'registration.resume_request_cooldown_sec' => 60]);
        foreach (range(1, 4) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->postJson('/api/register/stage1', $this->payload('flood@example.test'))->assertOk();
            $this->travel(61)->seconds();
        }
        Mail::assertSent(RegistrationEmailCodeMail::class, 2);
        $this->assertSame(2, EmailChallenge::where('email', 'flood@example.test')->count());
    }

    public function test_stage_one_has_a_per_ip_budget_that_counts_every_request_and_answers_generically(): void
    {
        config(['registration.stage1_attempts_per_hour' => 3]);
        foreach (range(1, 3) as $i) $this->postJson('/api/register/stage1', $this->payload("b{$i}@example.test"))->assertOk();
        $new      = $this->postJson('/api/register/stage1', $this->payload('another.new@example.test'));
        $existing = $this->postJson('/api/register/stage1', $this->payload('b1@example.test'));
        $new->assertStatus(429);
        $this->assertSame($new->json(), $existing->json(), 'the 429 reveals nothing about the address');
    }

    public function test_five_wrong_guesses_destroy_the_code_for_a_new_email(): void
    {
        $this->postJson('/api/register/stage1', $this->payload('guess@example.test'))->assertOk();
        $real  = $this->emailedCode();
        $wrong = $real === '000000' ? '111111' : '000000';
        foreach (range(1, 5) as $i) $this->verifyWith($this->payload('guess@example.test'), $wrong)->assertStatus(422);
        $this->verifyWith($this->payload('guess@example.test'), $real)->assertStatus(422);   // too late
        $this->assertDatabaseMissing('users', ['email' => 'guess@example.test']);
    }

    public function test_a_code_is_single_use_and_expires(): void
    {
        $this->postJson('/api/register/stage1', $this->payload('once@example.test'))->assertOk();
        $code = $this->emailedCode();
        $this->verifyWith($this->payload('once@example.test'), $code)->assertOk();
        $this->verifyWith($this->payload('once@example.test', '2015550155'), $code)->assertStatus(422);   // account exists now (unfinished) and the code is spent

        Mail::fake();
        $this->postJson('/api/register/stage1', $this->payload('late@example.test'))->assertOk();
        $late = $this->emailedCode();
        $this->travel(11)->minutes();
        $this->verifyWith($this->payload('late@example.test'), $late)->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'late@example.test']);
    }

    public function test_if_creating_the_account_fails_everything_rolls_back_and_the_code_is_still_valid(): void
    {
        $this->postJson('/api/register/stage1', $this->payload('rollback@example.test'))->assertOk();
        $code = $this->emailedCode();
        User::creating(function () { throw new \RuntimeException('database write failed'); });

        $this->verifyWith($this->payload('rollback@example.test'), $code)->assertStatus(503);
        $this->assertNull(EmailChallenge::where('email', 'rollback@example.test')->first()->consumed_at, 'the code was not used up');

        User::flushEventListeners();
        $this->verifyWith($this->payload('rollback@example.test'), $code)->assertOk();
    }

    public function test_an_undelivered_or_failed_code_is_never_usable(): void
    {
        EmailChallenge::create(['email' => 'pending@example.test', 'code_hash' => EmailChallenge::hashCode('123456'), 'attempts' => 0,
            'delivery_status' => 'pending', 'expires_at' => now()->addMinutes(5), 'created_at' => now()]);
        $this->verifyWith($this->payload('pending@example.test'), '123456')->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'pending@example.test']);
    }

    public function test_a_new_email_code_can_never_take_over_a_staff_or_unfinished_existing_account(): void
    {
        $admin = User::factory()->create(['email' => 'boss@example.test', 'role' => 'admin', 'registration_completed' => false, 'password' => bcrypt('Original#Pass1')]);
        $unfinished = User::factory()->create(['email' => 'mid@example.test', 'role' => 'investor', 'registration_completed' => false, 'password' => bcrypt('Original#Pass1')]);
        foreach (['boss@example.test', 'mid@example.test'] as $e) {   // even if a new-email challenge with a known code existed for these addresses
            EmailChallenge::create(['email' => $e, 'code_hash' => EmailChallenge::hashCode('123456'), 'attempts' => 0, 'delivery_status' => 'sent',
                'expires_at' => now()->addMinutes(5), 'created_at' => now()]);
            $this->verifyWith($this->payload($e, '2015550123', ['password' => 'Attacker#Pass9', 'password_confirmation' => 'Attacker#Pass9']), '123456')->assertStatus(422)->assertJsonMissingPath('token');
        }
        $this->assertTrue(\Hash::check('Original#Pass1', $admin->fresh()->password));
        $this->assertTrue(\Hash::check('Original#Pass1', $unfinished->fresh()->password));
        $this->assertSame(0, \DB::table('personal_access_tokens')->count());
    }

    public function test_the_response_time_has_a_floor_so_timing_does_not_distinguish_cases(): void
    {
        config(['registration.min_response_ms' => 300]);
        User::factory()->create(['email' => 'timing@example.test', 'role' => 'investor', 'registration_completed' => true]);
        foreach (['timing@example.test', 'brand.new.timing@example.test'] as $e) {
            $t = microtime(true);
            $this->postJson('/api/register/stage1', $this->payload($e))->assertOk();
            $this->assertGreaterThanOrEqual(0.28, microtime(true) - $t, "{$e}: response returned faster than the floor");
        }
    }

    public function test_public_registration_still_produces_only_an_investor(): void
    {
        $res = $this->registerViaCode($this->payload('only.investor@example.test'), ['role' => 'admin'], ['role' => 'admin', 'is_admin' => true])->assertOk();
        $u = User::where('email', 'only.investor@example.test')->firstOrFail();
        $this->assertSame('investor', $u->role);
        $this->assertFalse((bool) $u->registration_completed);
        $this->assertNotEmpty($res->json('token'));
    }
}
