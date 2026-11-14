<?php
// TASK 3 — delivery success/failure, duplicate-send prevention, scheduling, header injection, rate limiting, history, mailboxes, webhooks.
// WRITTEN, NOT EXECUTED in the build environment.
namespace Tests\Feature\Mailing;

use App\Mail\MailingMail;
use App\Models\Mailing\MailCampaign;
use App\Models\Mailing\MailContact;
use App\Models\Mailing\MailDepartment;
use App\Models\Mailing\MailMailbox;
use App\Models\Mailing\MailMessage;
use App\Services\Mailing\MailHeaderGuard;
use App\Services\Mailing\MessageSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailingDeliveryTest extends TestCase
{
    use RefreshDatabase, MailingTestHelpers;

    private function queuedMessage(array $o = []): MailMessage
    {
        return MailMessage::create($o + ['environment' => 'public', 'direction' => 'outbound', 'kind' => 'transactional', 'recipient_email' => 'to@example.com',
            'subject' => 'S', 'body_html' => '<p>b</p>', 'status' => 'queued']);
    }

    public function test_successful_delivery_marks_the_message_sent(): void
    {
        Mail::fake();
        $m = $this->queuedMessage();
        $this->assertSame('sent', app(MessageSender::class)->send($m->id));
        $m->refresh();
        $this->assertSame('sent', $m->status);
        $this->assertNotNull($m->sent_at);
        $this->assertSame(1, $m->attempts);
        Mail::assertSent(MailingMail::class, 1);
    }

    public function test_failed_delivery_is_recorded_without_leaking_secrets_and_shows_in_failures(): void
    {
        config(['mailing.max_attempts' => 1]);
        Mail::shouldReceive('mailer')->andReturnSelf();
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('Auth failed api-key: xkeysib-abcdefabcdefabcdefabcdefabcdefabcdef1234'));

        $m = $this->queuedMessage();
        $this->assertSame('failed', app(MessageSender::class)->send($m->id));
        $m->refresh();
        $this->assertSame('failed', $m->status);
        $this->assertSame('send_failed', $m->error_code);
        $this->assertStringNotContainsString('xkeysib', (string) $m->error_message);
        $this->assertNotNull($m->failed_at);

        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/mailing/public/failures')->assertOk()->assertJsonPath('data.0.id', $m->id);
    }

    public function test_transient_failure_is_requeued_until_the_attempt_cap(): void
    {
        config(['mailing.max_attempts' => 2]);
        Mail::shouldReceive('mailer')->andReturnSelf();
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('temporary'));

        $m = $this->queuedMessage();
        $sender = app(MessageSender::class);
        $this->assertSame('retry', $sender->send($m->id));
        $this->assertSame('queued', $m->fresh()->status);
        $this->assertSame('failed', $sender->send($m->id));
        $this->assertSame(2, $m->fresh()->attempts);
    }

    public function test_failed_message_can_be_retried_once_by_authorised_staff(): void
    {
        Mail::fake();
        $m = $this->queuedMessage(['status' => 'failed', 'error_code' => 'send_failed', 'attempts' => 3]);
        $viewer = $this->staff(['public.view']);
        $this->actingAs($viewer, 'sanctum')->postJson("/api/mailing/public/messages/{$m->id}/retry")->assertForbidden();
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/mailing/public/messages/{$m->id}/retry")->assertOk();
        $this->assertSame('sent', $m->fresh()->status);
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/mailing/public/messages/{$m->id}/retry")->assertStatus(409);   // no longer failed
        Mail::assertSent(MailingMail::class, 1);
    }

    public function test_the_same_message_can_never_be_sent_twice(): void
    {
        Mail::fake();
        $m = $this->queuedMessage();
        $sender = app(MessageSender::class);
        $this->assertSame('sent', $sender->send($m->id));
        $this->assertSame('noop', $sender->send($m->id));
        $this->assertSame('noop', $sender->send($m->id));
        Mail::assertSent(MailingMail::class, 1);
    }

    public function test_a_campaign_cannot_be_started_twice_and_recipients_are_not_duplicated(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->grantSub('a@example.com', 'public_newsletter');
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload())->json('campaign.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/public/campaigns/$id/send")->assertStatus(202);
        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/public/campaigns/$id/send")->assertStatus(409);
        app(\App\Services\Mailing\CampaignService::class)->expand($id);                // even a forced re-expansion adds nothing
        $this->assertSame(1, MailMessage::where('campaign_id', $id)->count());
        Mail::assertSent(MailingMail::class, 1);
        $this->assertSame('sent', MailCampaign::find($id)->status);
        $this->assertSame(1, MailCampaign::find($id)->sent_count);
    }

    public function test_double_submitted_create_returns_the_same_campaign(): void
    {
        $admin = $this->admin();
        $p = $this->campaignPayload(['client_token' => 'tok-123']);
        $a = $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $p)->json('campaign.id');
        $b = $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $p)->json('campaign.id');
        $this->assertSame($a, $b);
        $this->assertSame(1, MailCampaign::count());
    }

    public function test_scheduled_campaign_waits_until_due_then_sends_once(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->grantSub('s@example.com', 'public_newsletter');
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload())->json('campaign.id');

        $when = now()->addHour();
        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/public/campaigns/$id/schedule", ['scheduled_at' => $when->toIso8601String()])->assertOk();
        $this->assertSame('scheduled', MailCampaign::find($id)->status);

        $this->artisan('mailing:dispatch-scheduled')->assertExitCode(0);
        Mail::assertNothingSent();                                                      // not due yet

        $this->travelTo($when->copy()->addMinute());
        $this->artisan('mailing:dispatch-scheduled')->assertExitCode(0);
        $this->artisan('mailing:dispatch-scheduled')->assertExitCode(0);                // a second scheduler tick must not resend
        Mail::assertSent(MailingMail::class, 1);
        $this->assertSame('sent', MailCampaign::find($id)->status);
    }

    public function test_scheduling_in_the_past_is_rejected_and_cancel_stops_a_scheduled_campaign(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->grantSub('c@example.com', 'public_newsletter');
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload())->json('campaign.id');
        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/public/campaigns/$id/schedule", ['scheduled_at' => now()->subDay()->toIso8601String()])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/public/campaigns/$id/schedule", ['scheduled_at' => now()->addHour()->toIso8601String()])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/public/campaigns/$id/cancel")->assertOk();
        $this->travelTo(now()->addHours(2));
        $this->artisan('mailing:dispatch-scheduled');
        Mail::assertNothingSent();
        $this->actingAs($admin, 'sanctum')->putJson("/api/mailing/public/campaigns/$id", $this->campaignPayload())->assertStatus(422);   // cancelled → not editable
    }

    public function test_header_injection_is_rejected_everywhere_it_could_reach_a_header(): void
    {
        $admin = $this->admin();
        $bad = "Hello\r\nBcc: victim@example.com";
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload(['subject' => $bad]))->assertStatus(422)->assertJsonValidationErrors('subject');
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload(['name' => "x\nBcc: a@b.c"]))->assertStatus(422);
        $this->postJson('/api/public/mailing/contact', ['name' => "Eve\r\nBcc: v@example.com", 'email' => 'e@example.com', 'message' => 'A long enough message.'])->assertStatus(422);
        $this->postJson('/api/public/mailing/contact', ['name' => 'Eve', 'email' => "e@example.com\r\nBcc: v@example.com", 'message' => 'A long enough message.'])->assertStatus(422);
        $this->postJson('/api/public/mailing/contact', ['name' => 'Eve', 'email' => 'e@example.com', 'subject' => "Hi%0d%0aBcc: v@example.com", 'message' => 'A long enough message.'])->assertStatus(422);
        $this->assertSame(0, MailMessage::count());
    }

    public function test_header_guard_unit_behaviour(): void
    {
        $this->assertTrue(MailHeaderGuard::hasInjection("a\r\nBcc: x@y.z"));
        $this->assertTrue(MailHeaderGuard::hasInjection('a%0ABcc: x@y.z'));
        $this->assertFalse(MailHeaderGuard::hasInjection('Normal subject: 50% off'));
        $this->assertSame('Hello Bcc: x', MailHeaderGuard::text("Hello\r\nBcc: x"));
        $this->assertNull(MailHeaderGuard::address("a@b.co\r\nBcc: c@d.ef"));
        $this->assertNull(MailHeaderGuard::address('a@b.co, c@d.ef'));
        $this->assertNull(MailHeaderGuard::address('Name <a@b.co>'));
        $this->assertSame('a@b.co', MailHeaderGuard::address('  A@B.co '));
    }

    public function test_public_contact_form_is_rate_limited(): void
    {
        Mail::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/public/mailing/contact', ['name' => 'Visitor', 'email' => "v$i@example.com", 'message' => "Question number $i about SSI."])->assertStatus(202);
        }
        $this->postJson('/api/public/mailing/contact', ['name' => 'Visitor', 'email' => 'v9@example.com', 'message' => 'One question too many.'])->assertStatus(429);
    }

    public function test_contact_form_stores_the_enquiry_in_the_general_enquiries_department_and_acknowledges_once(): void
    {
        Mail::fake();
        $payload = ['name' => 'Ada Lovelace', 'email' => 'Ada@Example.com', 'subject' => 'Plans', 'message' => "<b>Hello</b>\nWhat plans exist?"];
        $this->postJson('/api/public/mailing/contact', $payload)->assertStatus(202);
        $this->postJson('/api/public/mailing/contact', $payload)->assertStatus(202);          // identical resubmission

        $in = MailMessage::where('direction', 'inbound')->get();
        $this->assertCount(1, $in);
        $this->assertSame('public', $in[0]->environment);
        $this->assertSame(MailDepartment::where('slug', 'general-enquiries')->value('id'), $in[0]->department_id);
        $this->assertSame('ada@example.com', $in[0]->from_address);
        $this->assertStringNotContainsString('<b>', $in[0]->body_html);                       // markup is neutralised
        $this->assertSame(1, MailMessage::where('direction', 'outbound')->where('kind', 'transactional')->count());   // one acknowledgement only
        Mail::assertSent(MailingMail::class, 1);
    }

    public function test_contact_form_honeypot_stores_nothing(): void
    {
        Mail::fake();
        $this->postJson('/api/public/mailing/contact', ['name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'Buy cheap things now!!', 'website' => 'http://spam'])->assertStatus(202);
        $this->assertSame(0, MailMessage::count());
        $this->assertSame(0, MailContact::count());
        Mail::assertNothingSent();
    }

    public function test_communication_history_supports_filtering_and_search(): void
    {
        $admin = $this->admin();
        $this->queuedMessage(['subject' => 'Alpha welcome', 'status' => 'sent']);
        $this->queuedMessage(['subject' => 'Beta notice', 'status' => 'failed']);
        $this->queuedMessage(['subject' => 'Gamma', 'direction' => 'inbound', 'status' => 'received']);
        $this->queuedMessage(['environment' => 'investor', 'subject' => 'Investor only', 'status' => 'sent']);

        $r = $this->actingAs($admin, 'sanctum');
        $this->assertCount(3, $r->getJson('/api/mailing/public/messages')->json('data'));          // investor row never appears in the public history
        $this->assertCount(1, $r->getJson('/api/mailing/public/messages?status=failed')->json('data'));
        $this->assertCount(1, $r->getJson('/api/mailing/public/messages?direction=inbound')->json('data'));
        $this->assertCount(1, $r->getJson('/api/mailing/public/messages?q=alpha')->json('data'));
        $this->assertCount(1, $r->getJson('/api/mailing/investor/messages')->json('data'));
    }

    public function test_mailbox_is_never_connected_on_creation_and_only_after_a_provider_check(): void
    {
        $admin = $this->admin();
        $member = $this->staff();
        $dept = MailDepartment::where('slug', 'investor-support')->first();

        $res = $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/departments/{$dept->id}/mailboxes", [
            'address' => 'jane@ssi.example', 'display_name' => 'Jane (Support)', 'user_id' => $member->id, 'provider' => 'brevo',
        ])->assertCreated();
        $this->assertSame('not_connected', $res->json('mailbox.connection_status'));
        $id = $res->json('mailbox.id');

        // No API key configured → still not connected, with an honest reason.
        config(['mailing.brevo_api_key' => null]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/mailboxes/$id/check")->assertOk()->assertJsonPath('mailbox.connection_status', 'not_connected');

        // Provider lists the sender as active → connected; then no longer listed → not connected.
        config(['mailing.brevo_api_key' => 'test-key']);
        Http::fake(['api.brevo.com/*' => Http::sequence()
            ->push(['senders' => [['email' => 'jane@ssi.example', 'active' => true]]], 200)
            ->push(['senders' => []], 200)]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/mailboxes/$id/check")->assertJsonPath('mailbox.connection_status', 'connected');
        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/mailboxes/$id/check")->assertJsonPath('mailbox.connection_status', 'not_connected');
    }

    public function test_mailbox_validation_rejects_pasted_secrets_shared_users_and_investor_owners(): void
    {
        $admin = $this->admin();
        $member = $this->staff();
        $dept = MailDepartment::first();
        $url = "/api/mailing/departments/{$dept->id}/mailboxes";

        $this->actingAs($admin, 'sanctum')->postJson($url, ['address' => 'a@ssi.example', 'display_name' => 'A', 'credential_ref' => 'hunter2 secret pass'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson($url, ['address' => 'a@ssi.example', 'display_name' => 'A', 'user_id' => $member->id])->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson($url, ['address' => 'b@ssi.example', 'display_name' => 'B', 'user_id' => $member->id])->assertStatus(422);   // one personal mailbox per person
        $this->actingAs($admin, 'sanctum')->postJson($url, ['address' => 'a@ssi.example', 'display_name' => 'Dup'])->assertStatus(422);
        $inv = $this->eligibleInvestor();
        $this->actingAs($admin, 'sanctum')->postJson($url, ['address' => 'c@ssi.example', 'display_name' => 'C', 'user_id' => $inv->id])->assertStatus(422);
        $this->assertArrayNotHasKey('password', MailMailbox::first()->getAttributes());
    }

    public function test_webhooks_require_the_shared_secret_and_apply_bounces_and_complaints(): void
    {
        config(['mailing.webhook_secret' => 'whsec_test']);
        $this->grantSub('b@example.com', 'public_newsletter');
        $m = $this->queuedMessage(['recipient_email' => 'b@example.com', 'status' => 'sent', 'provider_message_id' => 'abc123@mail']);

        $this->postJson('/api/mailing/webhooks/delivery', ['event' => 'hard_bounce', 'email' => 'b@example.com', 'message-id' => '<abc123@mail>'])->assertUnauthorized();
        $this->postJson('/api/mailing/webhooks/delivery', ['event' => 'hard_bounce', 'email' => 'b@example.com', 'message-id' => '<abc123@mail>'], ['X-SSI-Webhook-Secret' => 'wrong'])->assertUnauthorized();

        $h = ['X-SSI-Webhook-Secret' => 'whsec_test'];
        $this->postJson('/api/mailing/webhooks/delivery', ['event' => 'hard_bounce', 'email' => 'b@example.com', 'message-id' => '<abc123@mail>'], $h)->assertOk();
        $this->assertSame('bounced', $m->fresh()->status);
        $this->assertSame('bounced', MailContact::where('email', 'b@example.com')->value('status'));

        $this->postJson('/api/mailing/webhooks/delivery', ['event' => 'spam', 'email' => 'b@example.com'], $h)->assertOk();
        $this->assertSame('withdrawn', \App\Models\Mailing\MailSubscription::where('email', 'b@example.com')->value('status'));
    }

    public function test_webhooks_are_disabled_when_no_secret_is_configured(): void
    {
        config(['mailing.webhook_secret' => null]);
        $this->postJson('/api/mailing/webhooks/delivery', ['event' => 'delivered'], ['X-SSI-Webhook-Secret' => ''])->assertUnauthorized();
    }

    public function test_inbound_webhook_routes_mail_to_the_matching_mailbox_only(): void
    {
        config(['mailing.webhook_secret' => 's3']);
        $dept = MailDepartment::where('slug', 'registration')->first();
        $box = MailMailbox::create(['department_id' => $dept->id, 'address' => 'reg@ssi.example', 'display_name' => 'Registration', 'is_active' => true]);
        $payload = ['items' => [
            ['From' => ['Address' => 'Pat@Example.com', 'Name' => 'Pat'], 'To' => [['Address' => 'reg@ssi.example']], 'Subject' => "Hi\r\nBcc: x@y.z", 'RawTextBody' => 'Hello <script>x</script>', 'MessageId' => 'm1'],
            ['From' => ['Address' => 'Pat@Example.com'], 'To' => [['Address' => 'unknown@ssi.example']], 'Subject' => 'Nope', 'RawTextBody' => 'x', 'MessageId' => 'm2'],
        ]];
        $this->postJson('/api/mailing/webhooks/inbound', $payload, ['X-SSI-Webhook-Secret' => 's3'])->assertOk()->assertJson(['stored' => 1]);
        $this->postJson('/api/mailing/webhooks/inbound', $payload, ['X-SSI-Webhook-Secret' => 's3'])->assertJson(['stored' => 0]);   // idempotent

        $row = MailMessage::where('direction', 'inbound')->first();
        $this->assertSame($box->id, $row->mailbox_id);
        $this->assertStringNotContainsString("\n", $row->subject);
        $this->assertStringNotContainsString('<script', $row->body_html);
    }
}
