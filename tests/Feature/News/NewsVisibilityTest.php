<?php
// TASK 4 — public / members / investor visibility, scheduling, hidden states. WRITTEN, NOT EXECUTED in the build environment.
namespace Tests\Feature\News;

use App\Models\News\NewsArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsVisibilityTest extends TestCase
{
    use RefreshDatabase, NewsTestHelpers;

    public function test_anonymous_visitors_only_see_live_public_articles(): void
    {
        $pub = $this->article(['title' => 'Public live']);
        $this->article(['title' => 'Members', 'visibility' => 'members']);
        $this->article(['title' => 'Investors', 'visibility' => 'investor']);
        foreach (['draft', 'submitted', 'approved', 'rejected', 'unpublished'] as $s) $this->article(['title' => "Hidden $s", 'status' => $s]);
        $this->article(['title' => 'Future scheduled', 'status' => 'scheduled', 'published_at' => null, 'scheduled_at' => now()->addDay()]);

        $res = $this->getJson('/api/public/news')->assertOk();
        $this->assertSame(['Public live'], collect($res->json('data'))->pluck('title')->all());
        $this->getJson('/api/public/news/' . $pub->slug)->assertOk()->assertJsonPath('article.title', 'Public live');
        $this->getJson('/api/public/news/' . NewsArticle::where('title', 'Members')->value('slug'))->assertNotFound();
        $this->getJson('/api/public/news/' . NewsArticle::where('title', 'Hidden draft')->value('slug'))->assertNotFound();
    }

    public function test_applicants_see_public_and_members_but_not_investor_only(): void
    {
        $this->article(['title' => 'P']); $this->article(['title' => 'M', 'visibility' => 'members']); $inv = $this->article(['title' => 'I', 'visibility' => 'investor']);
        $titles = collect($this->actingAs($this->applicant(), 'sanctum')->getJson('/api/investor/news')->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['M', 'P'], $titles);
        $this->actingAs($this->applicant(), 'sanctum')->getJson('/api/investor/news/' . $inv->slug)->assertNotFound();
    }

    public function test_verified_investors_see_all_three_levels(): void
    {
        $this->article(['title' => 'P']); $this->article(['title' => 'M', 'visibility' => 'members']); $inv = $this->article(['title' => 'I', 'visibility' => 'investor']);
        $u = $this->verifiedInvestor();
        $this->assertCount(3, $this->actingAs($u, 'sanctum')->getJson('/api/investor/news')->json('data'));
        $this->actingAs($u, 'sanctum')->getJson('/api/investor/news/' . $inv->slug)->assertOk();
    }

    public function test_suspended_or_unconfirmed_investor_does_not_get_investor_only_content(): void
    {
        $this->article(['title' => 'I', 'visibility' => 'investor']);
        $u = $this->verifiedInvestor();
        $u->forceFill(['email_verified_at' => null])->save();
        $this->assertCount(0, $this->actingAs($u, 'sanctum')->getJson('/api/investor/news')->json('data'));
    }

    public function test_investor_feed_requires_login_and_investor_role(): void
    {
        $this->getJson('/api/investor/news')->assertUnauthorized();
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/investor/news')->assertForbidden();
    }

    public function test_due_scheduled_articles_go_live_on_read_and_via_command_but_future_ones_do_not(): void
    {
        $due = $this->article(['title' => 'Due', 'status' => 'scheduled', 'published_at' => null, 'scheduled_at' => now()->subMinute()]);
        $this->article(['title' => 'Later', 'status' => 'scheduled', 'published_at' => null, 'scheduled_at' => now()->addHour()]);
        $this->assertSame(['Due'], collect($this->getJson('/api/public/news')->json('data'))->pluck('title')->all());
        $this->assertSame('published', $due->fresh()->status);

        $this->travelTo(now()->addHours(2));
        $this->artisan('news:publish-scheduled')->assertExitCode(0);
        $this->assertCount(2, $this->getJson('/api/public/news')->json('data'));
    }

    public function test_soft_deleted_articles_vanish_and_filters_work(): void
    {
        $a = $this->article(['title' => 'Gone']); $this->article(['title' => 'Alpha market']); $this->article(['title' => 'Beta']);
        $a->delete();
        $this->assertCount(2, $this->getJson('/api/public/news')->json('data'));
        $this->assertCount(1, $this->getJson('/api/public/news?q=alpha')->json('data'));
        $this->assertCount(0, $this->getJson('/api/public/news?category=nope')->json('data'));
    }

    public function test_feed_payload_never_contains_storage_internals_or_workflow_fields(): void
    {
        $this->article();
        $body = $this->getJson('/api/public/news')->json();
        $json = json_encode($body);
        // "path" at the top level is Laravel's paginator URL (e.g. http://host/api/public/news), not a storage path: storage internals are checked inside the ITEMS.
        $items = json_encode($body['data']);
        $this->assertStringNotContainsString('"path"', $items);
        foreach (['"disk"', 'sha256', 'review_note', 'reviewed_by', 'author_id'] as $needle) $this->assertStringNotContainsString($needle, $json);
    }
}
