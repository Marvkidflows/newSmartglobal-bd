<?php
// TASK 3 — consent, double opt-in, unsubscribe, essential vs marketing. WRITTEN, NOT EXECUTED in the build environment.
namespace Tests\Feature\Mailing;

use App\Mail\MailingMail;
use App\Models\Mailing\MailConsent;
use App\Models\Mailing\MailContact;
use App\Models\Mailing\MailMessage;
use App\Models\Mailing\MailSubscription;
use App\Services\Mailing\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MailingConsentTest extends TestCase
{
    use RefreshDatabase, MailingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_subscribe_is_double_opt_in_and_records_purpose_and_timestamp(): void
    {
        $this->postJson('/api/public/mailing/subscribe', ['email' => 'New@Example.com', 'purposes' => ['public_newsletter']])->assertStatus(202);

        $sub = MailSubscription::where('email', 'new@example.com')->where('purpose', 'public_newsletter')->first();
        $this->assertSame('pending', $sub->status);                         // NOT subscribed yet
        $this->assertSame('requested', MailConsent::where('email', 'new@example.com')->first()->action);
        Mail::assertSent(MailingMail::class, fn ($m) => $m->hasTo('new@example.com'));   // confirmation message

        $contact = MailContact::where('email', 'new@example.com')->first();
        $url = app(ConsentService::class)->confirmUrl($contact, 'public_newsletter');
        $this->get($url)->assertOk();                                       // GET changes nothing
        $this->assertSame('pending', $sub->fresh()->status);
        $this->post($url)->assertOk();                                      // POST confirms
        $this->assertSame('granted', $sub->fresh()->status);

        $ledger = MailConsent::where('email', 'new@example.com')->orderBy('id')->get();
        $this->assertSame(['requested', 'granted'], $ledger->pluck('action')->all());
        $this->assertNotNull($ledger->last()->created_at);
        $this->assertSame('public_newsletter', $ledger->last()->purpose);
        $this->assertSame('double_opt_in', $ledger->last()->source);
    }

    public function test_unsubscribe_link_requires_valid_signature_and_post_to_change_state(): void
    {
        $this->grantSub('u@example.com', 'public_newsletter');
        $contact = MailContact::where('email', 'u@example.com')->first();
        $signed = app(ConsentService::class)->unsubscribeUrl($contact, null, 'public_newsletter');

        $this->get($signed)->assertOk();
        $this->assertSame('granted', MailSubscription::where('email', 'u@example.com')->first()->status);   // GET is harmless

        $tampered = str_replace('public_newsletter', 'public_announcements', $signed);
        $this->post($tampered)->assertForbidden();

        $this->post($signed)->assertOk();
        $this->assertSame('withdrawn', MailSubscription::where('email', 'u@example.com')->first()->status);
        $this->assertTrue(MailConsent::where('email', 'u@example.com')->where('action', 'withdrawn')->where('source', 'unsubscribe_link')->exists());
    }

    public function test_unsubscribed_person_is_skipped_by_later_campaigns(): void
    {
        $admin = $this->admin();
        $this->grantSub('gone@example.com', 'public_newsletter');
        $this->grantSub('stay@example.com', 'public_newsletter');
        MailSubscription::where('email', 'gone@example.com')->update(['status' => 'withdrawn']);

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload())->json('campaign.id');
        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/public/campaigns/$id/send")->assertStatus(202);
        Mail::assertSent(MailingMail::class, 1);
        Mail::assertNotSent(MailingMail::class, fn ($m) => $m->hasTo('gone@example.com'));
    }

    public function test_marketing_message_without_consent_is_skipped_but_essential_is_delivered(): void
    {
        $inv = $this->eligibleInvestor(['email' => 'e@example.com']);       // no investor_newsletter consent at all
        $marketing = MailMessage::create(['environment' => 'investor', 'direction' => 'outbound', 'kind' => 'newsletter', 'purpose' => 'investor_newsletter', 'user_id' => $inv->id,
            'recipient_email' => $inv->email, 'subject' => 'News', 'body_html' => '<p>n</p>', 'status' => 'queued']);
        $essential = MailMessage::create(['environment' => 'investor', 'direction' => 'outbound', 'kind' => 'essential', 'user_id' => $inv->id,
            'recipient_email' => $inv->email, 'subject' => 'Security notice', 'body_html' => '<p>s</p>', 'status' => 'queued']);

        $sender = app(\App\Services\Mailing\MessageSender::class);
        $this->assertSame('skipped', $sender->send($marketing->id));
        $this->assertSame('no_consent', $marketing->fresh()->skip_reason);
        $this->assertSame('sent', $sender->send($essential->id));
    }

    public function test_unsubscribing_from_optional_mail_does_not_stop_essential_notices(): void
    {
        $inv = $this->eligibleInvestor(['email' => 'both@example.com']);
        $this->actingAs($inv, 'sanctum')->putJson('/api/investor/mailing/preferences', ['investor_newsletter' => true])->assertOk();
        $this->actingAs($inv, 'sanctum')->putJson('/api/investor/mailing/preferences', ['investor_newsletter' => false, 'investor_announcements' => false])->assertOk();

        $essential = MailMessage::create(['environment' => 'investor', 'direction' => 'outbound', 'kind' => 'essential', 'user_id' => $inv->id,
            'recipient_email' => $inv->email, 'subject' => 'Account notice', 'body_html' => '<p>a</p>', 'status' => 'queued']);
        $this->assertSame('sent', app(\App\Services\Mailing\MessageSender::class)->send($essential->id));
    }

    public function test_consent_purposes_are_environment_bound(): void
    {
        $inv = $this->eligibleInvestor(['email' => 'x@example.com']);
        $this->grantSub('x@example.com', 'public_newsletter');                // a PUBLIC consent must not authorise an INVESTOR message
        $m = MailMessage::create(['environment' => 'investor', 'direction' => 'outbound', 'kind' => 'newsletter', 'purpose' => 'public_newsletter', 'user_id' => $inv->id,
            'recipient_email' => $inv->email, 'subject' => 'S', 'body_html' => '<p>b</p>', 'status' => 'queued']);
        $this->assertSame('skipped', app(\App\Services\Mailing\MessageSender::class)->send($m->id));
        $this->assertSame('purpose_environment_mismatch', $m->fresh()->skip_reason);
    }

    public function test_subscribe_response_is_identical_for_new_and_existing_addresses(): void
    {
        $this->grantSub('known@example.com', 'public_newsletter');
        $a = $this->postJson('/api/public/mailing/subscribe', ['email' => 'known@example.com', 'purposes' => ['public_newsletter']]);
        $b = $this->postJson('/api/public/mailing/subscribe', ['email' => 'unknown@example.com', 'purposes' => ['public_newsletter']]);
        $this->assertSame($a->status(), $b->status());
        $this->assertSame($a->json(), $b->json());
        $this->assertSame('granted', MailSubscription::where('email', 'known@example.com')->first()->status);   // existing grant not downgraded to pending
    }

    public function test_investor_purposes_cannot_be_subscribed_from_the_public_form(): void
    {
        $this->postJson('/api/public/mailing/subscribe', ['email' => 'a@example.com', 'purposes' => ['investor_newsletter']])->assertStatus(422);
    }
}
