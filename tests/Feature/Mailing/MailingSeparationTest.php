<?php
// TASK 3 — public / investor mailing separation and verified-investor eligibility. WRITTEN, NOT EXECUTED in the build environment.
namespace Tests\Feature\Mailing;

use App\Mail\MailingMail;
use App\Models\Mailing\MailMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailingSeparationTest extends TestCase
{
    use RefreshDatabase, MailingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_public_campaign_never_reaches_verified_investors(): void
    {
        $admin = $this->admin();
        $investor = $this->eligibleInvestor(['email' => 'inv@example.com']);
        $this->grantSub('inv@example.com', 'public_newsletter');           // subscribed publicly, but is now a verified investor
        $this->grantSub('visitor@example.com', 'public_newsletter');

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload())->assertCreated()->json('campaign.id');
        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/public/campaigns/$id/send")->assertStatus(202);

        Mail::assertSent(MailingMail::class, 1);
        Mail::assertSent(MailingMail::class, fn ($m) => $m->hasTo('visitor@example.com'));
        Mail::assertNotSent(MailingMail::class, fn ($m) => $m->hasTo('inv@example.com'));
    }

    public function test_investor_audience_contains_only_verified_eligible_investors(): void
    {
        $admin = $this->admin();
        $ok        = $this->eligibleInvestor(['email' => 'ok@example.com']);
        $noKyc     = $this->eligibleInvestor(['email' => 'nokyc@example.com', 'kyc_verified' => false]);
        $suspended = $this->eligibleInvestor(['email' => 'susp@example.com', 'status' => 'suspended']);
        $noEmail   = $this->eligibleInvestor(['email' => 'noemail@example.com', 'email_verified_at' => null]);
        foreach ([$ok, $noKyc, $suspended, $noEmail] as $u) $this->grantSub($u->email, 'investor_announcements', $u);

        $p = $this->campaignPayload(['kind' => 'announcement', 'audience_type' => 'verified_investors']);
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/investor/campaigns', $p)->assertCreated()->json('campaign.id');

        $this->actingAs($admin, 'sanctum')->getJson("/api/mailing/investor/campaigns/$id/audience-preview")->assertOk()->assertJson(['recipient_count' => 1]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/mailing/investor/campaigns/$id/send")->assertStatus(202);
        Mail::assertSent(MailingMail::class, 1);
        Mail::assertSent(MailingMail::class, fn ($m) => $m->hasTo('ok@example.com'));
    }

    public function test_audience_types_cannot_cross_environments(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload(['audience_type' => 'verified_investors']))
            ->assertStatus(422)->assertJsonValidationErrors('audience_type');
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/investor/campaigns', $this->campaignPayload(['audience_type' => 'public_subscribers']))
            ->assertStatus(422)->assertJsonValidationErrors('audience_type');
    }

    public function test_rank_audience_with_no_ranks_is_empty_never_everyone(): void
    {
        $admin = $this->admin();
        $this->eligibleInvestor();
        $p = $this->campaignPayload(['kind' => 'essential', 'audience_type' => 'verified_investors_by_rank', 'audience_filters' => ['rank_ids' => []]]);
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/investor/campaigns', $p)->assertCreated()->json('campaign.id');
        $this->actingAs($admin, 'sanctum')->getJson("/api/mailing/investor/campaigns/$id/audience-preview")->assertJson(['recipient_count' => 0]);
    }

    public function test_public_content_cannot_use_investor_merge_tags_or_account_numbers(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload(['body_html' => '<p>Your rank is {{rank}}</p>']))
            ->assertStatus(422)->assertJsonValidationErrors('body_html');
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload(['body_html' => '<p>Account INVSTR-00042</p>']))
            ->assertStatus(422)->assertJsonValidationErrors('body_html');
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload(['body_html' => '<p>Balance {{balance}}</p>']))
            ->assertStatus(422);
    }

    public function test_investor_message_to_unverified_investor_is_rejected(): void
    {
        $admin = $this->admin();
        $unverified = User::factory()->create(['role' => 'investor', 'kyc_verified' => false]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/investor/messages', ['subject' => 'Hi', 'body_html' => '<p>x</p>', 'investor_id' => $unverified->id])
            ->assertStatus(422)->assertJsonValidationErrors('investor_id');
        Mail::assertNothingSent();
    }

    public function test_public_one_to_one_message_to_verified_investor_is_rejected(): void
    {
        $admin = $this->admin();
        $inv = $this->eligibleInvestor(['email' => 'inv2@example.com']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/messages', ['subject' => 'Hi', 'body_html' => '<p>x</p>', 'to_email' => 'inv2@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('to_email');
    }

    public function test_send_time_guard_skips_investor_who_lost_eligibility_after_audience_was_built(): void
    {
        $admin = $this->admin();
        $inv = $this->eligibleInvestor(['email' => 'later@example.com']);
        $m = MailMessage::create([
            'environment' => 'investor', 'direction' => 'outbound', 'kind' => 'essential', 'user_id' => $inv->id,
            'recipient_email' => $inv->email, 'subject' => 'Notice', 'body_html' => '<p>x</p>', 'status' => 'queued',
        ]);
        $inv->forceFill(['status' => 'suspended'])->save();

        $this->assertSame('skipped', app(\App\Services\Mailing\MessageSender::class)->send($m->id));
        $this->assertSame('not_eligible_investor', $m->fresh()->skip_reason);
        Mail::assertNothingSent();
    }

    public function test_investor_can_only_read_their_own_history(): void
    {
        $a = $this->eligibleInvestor(); $b = $this->eligibleInvestor();
        $mine = MailMessage::create(['environment' => 'investor', 'direction' => 'outbound', 'kind' => 'essential', 'user_id' => $a->id, 'recipient_email' => $a->email, 'subject' => 'Mine', 'body_html' => '<p>m</p>', 'status' => 'sent']);
        $theirs = MailMessage::create(['environment' => 'investor', 'direction' => 'outbound', 'kind' => 'essential', 'user_id' => $b->id, 'recipient_email' => $b->email, 'subject' => 'Theirs', 'body_html' => '<p>t</p>', 'status' => 'sent']);

        $this->actingAs($a, 'sanctum')->getJson('/api/investor/mailing/history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->actingAs($a, 'sanctum')->getJson('/api/investor/mailing/history/' . $theirs->id)->assertNotFound();
    }
}
