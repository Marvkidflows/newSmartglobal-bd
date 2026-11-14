<?php
// TASK 4 — contributor submissions, moderation, publishing, permissions, audit. WRITTEN, NOT EXECUTED in the build environment.
namespace Tests\Feature\News;

use App\Models\News\NewsArticle;
use App\Models\News\NewsAuditLog;
use App\Models\News\NewsCategory;
use App\Models\News\NewsMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsWorkflowTest extends TestCase
{
    use RefreshDatabase, NewsTestHelpers;

    private function payload(array $o = []): array
    {
        return $o + ['title' => 'My piece', 'summary' => 'Sum', 'body' => '<p>Hello</p>', 'content_type' => 'article', 'visibility' => 'public', 'category_id' => NewsCategory::first()->id];
    }

    public function test_contributor_submission_must_be_reviewed_by_someone_else_before_a_publisher_can_publish_it(): void
    {
        $contrib = $this->staff(['news.contribute'], 'marvflow_member');
        $reviewer = $this->staff(['news.review']);
        $publisher = $this->staff(['news.publish'], 'marvflow_lead');

        $id = $this->actingAs($contrib, 'sanctum')->postJson('/api/news-admin/articles', $this->payload())->assertCreated()->json('article.id');
        $this->actingAs($contrib, 'sanctum')->postJson("/api/news-admin/articles/$id/publish")->assertForbidden();           // contributors never publish
        $this->actingAs($publisher, 'sanctum')->postJson("/api/news-admin/articles/$id/publish")->assertStatus(422);         // not approved yet
        $this->actingAs($contrib, 'sanctum')->postJson("/api/news-admin/articles/$id/submit")->assertOk()->assertJsonPath('article.status', 'submitted');
        $this->actingAs($contrib, 'sanctum')->postJson("/api/news-admin/articles/$id/approve")->assertForbidden();           // no review permission
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/news-admin/articles/$id/approve", ['note' => 'ok'])->assertOk()->assertJsonPath('article.status', 'approved');
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/news-admin/articles/$id/approve")->assertStatus(422);          // double-approve refused

        $this->assertCount(0, $this->getJson('/api/public/news')->json('data'));                                              // approved ≠ live
        $this->actingAs($publisher, 'sanctum')->postJson("/api/news-admin/articles/$id/publish")->assertOk()->assertJsonPath('article.status', 'published');
        $this->assertCount(1, $this->getJson('/api/public/news')->json('data'));
        foreach (['article.created', 'article.submitted', 'article.approved', 'article.published'] as $a) $this->assertTrue(NewsAuditLog::where('action', $a)->exists(), $a);
    }

    public function test_nobody_can_review_their_own_submission(): void
    {
        $both = $this->staff(['news.contribute', 'news.review']);
        $id = $this->actingAs($both, 'sanctum')->postJson('/api/news-admin/articles', $this->payload())->json('article.id');
        $this->actingAs($both, 'sanctum')->postJson("/api/news-admin/articles/$id/submit")->assertOk();
        $this->actingAs($both, 'sanctum')->postJson("/api/news-admin/articles/$id/approve")->assertStatus(422);
    }

    public function test_rejection_needs_a_note_and_the_author_can_fix_and_resubmit(): void
    {
        $contrib = $this->staff(['news.contribute']); $reviewer = $this->staff(['news.review']);
        $id = $this->actingAs($contrib, 'sanctum')->postJson('/api/news-admin/articles', $this->payload())->json('article.id');
        $this->actingAs($contrib, 'sanctum')->postJson("/api/news-admin/articles/$id/submit");
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/news-admin/articles/$id/reject")->assertStatus(422);
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/news-admin/articles/$id/reject", ['note' => 'Add a source'])->assertOk()->assertJsonPath('article.review_note', 'Add a source');
        $this->actingAs($contrib, 'sanctum')->putJson("/api/news-admin/articles/$id", ['title' => 'Better'])->assertOk();
        $this->actingAs($contrib, 'sanctum')->postJson("/api/news-admin/articles/$id/submit")->assertOk()->assertJsonPath('article.status', 'submitted');
        $this->actingAs($contrib, 'sanctum')->putJson("/api/news-admin/articles/$id", ['title' => 'Sneaky edit'])->assertForbidden();   // locked while under review
    }

    public function test_contributors_only_see_and_touch_their_own_articles(): void
    {
        $a = $this->staff(['news.contribute']); $b = $this->staff(['news.contribute']);
        $mine = $this->actingAs($a, 'sanctum')->postJson('/api/news-admin/articles', $this->payload())->json('article.id');
        $this->assertCount(1, $this->actingAs($a, 'sanctum')->getJson('/api/news-admin/articles')->json('data'));
        $this->assertCount(0, $this->actingAs($b, 'sanctum')->getJson('/api/news-admin/articles')->json('data'));
        $this->actingAs($b, 'sanctum')->getJson("/api/news-admin/articles/$mine")->assertNotFound();
        $this->actingAs($b, 'sanctum')->putJson("/api/news-admin/articles/$mine", ['title' => 'x'])->assertNotFound();
        $this->actingAs($b, 'sanctum')->deleteJson("/api/news-admin/articles/$mine")->assertNotFound();
    }

    public function test_publisher_can_publish_their_own_draft_directly_and_schedule_future_items(): void
    {
        $pub = $this->staff(['news.contribute', 'news.publish']);
        $id = $this->actingAs($pub, 'sanctum')->postJson('/api/news-admin/articles', $this->payload())->json('article.id');
        $when = now()->addDays(2);
        $this->actingAs($pub, 'sanctum')->postJson("/api/news-admin/articles/$id/publish", ['scheduled_at' => $when->toIso8601String()])->assertOk()->assertJsonPath('article.status', 'scheduled');
        $this->assertCount(0, $this->getJson('/api/public/news')->json('data'));
        $this->actingAs($pub, 'sanctum')->postJson("/api/news-admin/articles/$id/publish")->assertOk()->assertJsonPath('article.status', 'published');
        $this->actingAs($pub, 'sanctum')->postJson("/api/news-admin/articles/$id/unpublish")->assertOk()->assertJsonPath('article.status', 'unpublished');
        $this->assertCount(0, $this->getJson('/api/public/news')->json('data'));
        $this->actingAs($pub, 'sanctum')->postJson("/api/news-admin/articles/$id/unpublish")->assertStatus(422);
    }

    public function test_publishing_requires_a_category_and_approved_media(): void
    {
        $admin = $this->admin();
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/news-admin/articles', $this->payload(['category_id' => null]))->json('article.id');
        $this->actingAs($admin, 'sanctum')->postJson("/api/news-admin/articles/$id/publish")->assertStatus(422)->assertJsonValidationErrors('category_id');

        $m = NewsMedia::create(['kind' => 'image', 'disk' => 'local', 'path' => 'x.png', 'mime' => 'image/png', 'status' => 'pending', 'uploaded_by' => $admin->id]);
        $this->actingAs($admin, 'sanctum')->putJson("/api/news-admin/articles/$id", ['category_id' => NewsCategory::first()->id, 'cover_media_id' => $m->id])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/news-admin/articles/$id/publish")->assertStatus(422)->assertJsonValidationErrors('media');
        $m->forceFill(['status' => 'approved'])->save();
        $this->actingAs($admin, 'sanctum')->postJson("/api/news-admin/articles/$id/publish")->assertOk();
    }

    public function test_approving_a_submission_approves_its_pending_media(): void
    {
        $contrib = $this->staff(['news.contribute']); $reviewer = $this->staff(['news.review']);
        $m = NewsMedia::create(['kind' => 'image', 'disk' => 'local', 'path' => 'x.png', 'mime' => 'image/png', 'status' => 'pending', 'uploaded_by' => $contrib->id]);
        $id = $this->actingAs($contrib, 'sanctum')->postJson('/api/news-admin/articles', $this->payload(['cover_media_id' => $m->id]))->assertCreated()->json('article.id');
        $this->actingAs($contrib, 'sanctum')->postJson("/api/news-admin/articles/$id/submit");
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/news-admin/articles/$id/approve")->assertOk();
        $this->assertSame('approved', $m->fresh()->status);
    }

    public function test_contributor_cannot_attach_someone_elses_media(): void
    {
        $a = $this->staff(['news.contribute']); $b = $this->staff(['news.contribute']);
        $m = NewsMedia::create(['kind' => 'image', 'disk' => 'local', 'path' => 'x.png', 'mime' => 'image/png', 'status' => 'approved', 'uploaded_by' => $b->id]);
        $this->actingAs($a, 'sanctum')->postJson('/api/news-admin/articles', $this->payload(['cover_media_id' => $m->id]))->assertStatus(422)->assertJsonValidationErrors('cover_media_id');
    }

    public function test_article_html_is_sanitised_and_workflow_fields_cannot_be_mass_assigned(): void
    {
        $contrib = $this->staff(['news.contribute']);
        $res = $this->actingAs($contrib, 'sanctum')->postJson('/api/news-admin/articles', $this->payload([
            'body' => '<p>Hi</p><script>alert(1)</script><a href="javascript:alert(1)" onclick="x()">x</a>', 'status' => 'published', 'author_id' => 999, 'is_featured' => true,
        ]))->assertCreated();
        $a = NewsArticle::find($res->json('article.id'));
        $this->assertSame('draft', $a->status);
        $this->assertSame($contrib->id, $a->author_id);
        $this->assertFalse($a->is_featured);                                                                                      // only publishers can feature
        $this->assertStringNotContainsString('<script', strtolower($a->body));
        $this->assertStringNotContainsString('javascript:', strtolower($a->body));
        $this->assertStringNotContainsString('onclick', strtolower($a->body));
    }

    public function test_removal_is_a_soft_delete_restricted_to_managers_or_own_drafts(): void
    {
        $contrib = $this->staff(['news.contribute']); $mgr = $this->staff(['news.manage']);
        $draft = $this->actingAs($contrib, 'sanctum')->postJson('/api/news-admin/articles', $this->payload())->json('article.id');
        $live = $this->article(['title' => 'Live']);
        $this->actingAs($contrib, 'sanctum')->deleteJson("/api/news-admin/articles/$draft")->assertOk();
        $this->actingAs($contrib, 'sanctum')->deleteJson("/api/news-admin/articles/{$live->id}")->assertNotFound();      // not theirs → invisible
        $this->actingAs($mgr, 'sanctum')->deleteJson("/api/news-admin/articles/{$live->id}")->assertOk();
        $this->assertSoftDeleted('news_articles', ['id' => $live->id]);
    }

    public function test_access_control(): void
    {
        $this->getJson('/api/news-admin/articles')->assertUnauthorized();
        $this->actingAs($this->verifiedInvestor(), 'sanctum')->getJson('/api/news-admin/articles')->assertForbidden();
        $this->actingAs($this->staff(), 'sanctum')->getJson('/api/news-admin/articles')->assertForbidden();                   // staff without any grant
        $this->actingAs($this->staff(['news.contribute']), 'sanctum')->getJson('/api/news-admin/audit')->assertForbidden();
        $this->actingAs($this->staff(['news.manage']), 'sanctum')->getJson('/api/news-admin/audit')->assertOk();

        $admin = $this->admin(); $s = $this->staff();
        $this->actingAs($s, 'sanctum')->putJson("/api/news-admin/permissions/{$s->id}", ['permissions' => ['news.publish']])->assertForbidden();    // self-grant refused
        $this->actingAs($admin, 'sanctum')->putJson("/api/news-admin/permissions/{$s->id}", ['permissions' => ['news.publish']])->assertOk();
        $this->actingAs($admin, 'sanctum')->putJson("/api/news-admin/permissions/{$s->id}", ['permissions' => ['news.god']])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson("/api/news-admin/permissions/{$this->verifiedInvestor()->id}", ['permissions' => ['news.publish']])->assertStatus(422);
        $this->assertDatabaseHas('news_permissions', ['user_id' => $s->id, 'permission' => 'news.publish', 'granted_by' => $admin->id]);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/news-admin/articles', [])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson('/api/news-admin/articles', $this->payload(['visibility' => 'secret']))->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson('/api/news-admin/articles', $this->payload(['content_type' => 'malware']))->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->getJson('/api/news-admin/articles/999999')->assertNotFound();
        $this->actingAs($admin, 'sanctum')->postJson('/api/news-admin/articles/999999/publish')->assertNotFound();
    }
}
