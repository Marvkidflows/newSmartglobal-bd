<?php
// LOCATION: tests/Feature/Remediation/AdminSuspendNotificationTest.php
//
// Management decision: once an investor's suspension has been saved, a notification delivery failure must not make POST /api/admin/users/{id}/suspend
// return 500 or make the administrator believe the suspension failed. WRITTEN, NOT EXECUTED by its author (no PHP runtime) — run it before trusting it.
//
// Note on the mailer: AccountStatusNotification hard-codes ->mailer('brevo'), which bypasses MAIL_MAILER=array, so every test here controls that mailer
// explicitly (array transport for "delivered", a throwing mailer for "Brevo unavailable"). Nothing here can reach the network.

namespace Tests\Feature\Remediation;

use App\Models\User;
use App\Notifications\AccountStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class AdminSuspendNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $investor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin    = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->investor = User::factory()->create(['role' => 'investor', 'status' => 'active', 'email' => 'person.to.suspend@example.test']);
    }

    private function suspend(?User $actor = null, ?User $target = null)
    {
        return $this->actingAs($actor ?? $this->admin, 'sanctum')->postJson('/api/admin/users/' . ($target ?? $this->investor)->id . '/suspend');
    }

    /** The mail provider is unreachable. Note the secret-looking key and address inside the message: they must never reach the log. */
    private function brevoIsDown(): void
    {
        Mail::shouldReceive('mailer')->andThrow(new TransportException(
            'Could not reach the remote Brevo server. api-key xkeysib-0123456789abcdef0123456789abcdef0123456789abcdef-AbCdEf while sending to person.to.suspend@example.test'
        ));
    }

    // ── delivery succeeds ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_successful_delivery_suspends_notifies_and_reports_delivered(): void
    {
        Notification::fake();

        $this->suspend()->assertOk()->assertExactJson([
            'message'                => 'Investor suspended.',
            'status'                 => 'suspended',
            'notification_delivered' => true,
        ]);

        $this->assertSame('suspended', $this->investor->fresh()->status);
        Notification::assertSentTo($this->investor, AccountStatusNotification::class, function ($n, array $channels) {
            return $channels === ['database', 'mail'];   // unchanged channels and order
        });
        Notification::assertCount(1);   // the investor only: recipients unchanged
    }

    public function test_successful_delivery_stores_the_in_app_notification_and_logs_no_error(): void
    {
        config(['mail.mailers.brevo' => ['transport' => 'array']]);   // real notification, real database channel, no network
        Log::spy();

        $this->suspend()->assertOk()->assertJsonPath('notification_delivered', true);

        $this->assertSame('suspended', $this->investor->fresh()->status);
        $row = $this->investor->notifications()->firstOrFail();
        $this->assertSame(['type' => 'account_status', 'action' => 'suspended'], collect($row->data)->only(['type', 'action'])->all());
        Log::shouldNotHaveReceived('error');
    }

    // ── delivery fails ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_failed_notification_does_not_fail_the_suspension_and_says_so_clearly(): void
    {
        $this->brevoIsDown();

        $response = $this->suspend();

        $response->assertOk()->assertJson(['status' => 'suspended', 'notification_delivered' => false]);   // NOT a 500
        $this->assertStringContainsString('could not be delivered', $response->json('message'));
        $this->assertStringContainsString('Investor suspended', $response->json('message'));
        $this->assertSame('suspended', $this->investor->fresh()->status, 'the investor stays suspended while Brevo is down');
    }

    public function test_a_failed_notification_is_logged_once_without_credentials_addresses_or_a_stack_trace(): void
    {
        $this->brevoIsDown();
        Log::spy();

        $this->suspend()->assertOk();

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
            $flat = json_encode($context);
            return str_contains($message, 'status change itself was saved')
                && $context['user_id'] === $this->investor->id
                && $context['action'] === 'suspended'
                && $context['admin_id'] === $this->admin->id
                && $context['exception'] === TransportException::class
                && !str_contains($flat, 'xkeysib')                      // credential-shaped value redacted
                && !str_contains($flat, 'person.to.suspend@example.test') // recipient address redacted
                && !array_key_exists('trace', $context) && !array_key_exists('exception_object', $context)
                && strlen($context['error']) <= 300;
        });
    }

    public function test_when_only_the_mail_provider_fails_the_in_app_notification_is_still_stored(): void
    {
        $this->brevoIsDown();

        $this->suspend()->assertOk()->assertJsonPath('notification_delivered', false);

        // via() runs 'database' before 'mail', so the in-app notification exists even though the email could not be sent.
        $row = $this->investor->notifications()->first();
        $this->assertNotNull($row, 'the database notification must survive a mail failure');
        $this->assertSame('suspended', $row->data['action']);
    }

    public function test_a_failure_is_never_silent_it_is_in_the_response_and_in_the_log(): void
    {
        $this->brevoIsDown();
        Log::spy();

        $r = $this->suspend()->assertOk();

        $this->assertFalse($r->json('notification_delivered'));
        Log::shouldHaveReceived('error')->once();
    }

    // ── existing behaviour preserved ────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_authorization_and_self_lockout_checks_are_unchanged_and_never_reach_the_notification(): void
    {
        Notification::fake();

        // Unauthenticated FIRST: actingAs() persists for the rest of a test, so it must come before any actor is set.
        $this->postJson("/api/admin/users/{$this->investor->id}/suspend")->assertUnauthorized();
        $this->actingAs($this->investor, 'sanctum')->postJson("/api/admin/users/{$this->investor->id}/suspend")->assertForbidden();
        $financial = User::factory()->create(['role' => 'financial', 'status' => 'active']);
        $this->suspend($financial)->assertForbidden();
        $this->suspend($this->admin, $this->admin)->assertStatus(422);   // an administrator cannot suspend their own account

        $this->assertSame('active', $this->investor->fresh()->status);
        $this->assertSame('active', $this->admin->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_the_balance_is_untouched_by_a_suspension_even_when_the_notification_fails(): void
    {
        $this->investor->forceFill(['balance' => '1234.50'])->save();
        $this->brevoIsDown();

        $this->suspend()->assertOk();

        $fresh = $this->investor->fresh();
        $this->assertSame('1234.50', number_format((float) $fresh->balance, 2, '.', ''));
        $this->assertSame('suspended', $fresh->status);
    }
}
