<?php
// LOCATION: tests/Feature/NewsCentreTest.php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsCentreTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $investor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->investor = User::factory()->create(['role' => 'investor']);
    }

    /** Draft content must never reach the investor feed. */
    public function test_draft_announcement_is_not_visible_to_investors(): void
    {
        Announcement::create([
            'title' => 'Internal Draft', 'content' => 'not ready', 'status' => 'draft',
        ]);

        $response = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/announcements');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'announcements');
    }

    /** Unpublished (previously published, then pulled) content is hidden too. */
    public function test_unpublished_announcement_is_not_visible_to_investors(): void
    {
        Announcement::create([
            'title' => 'Pulled Notice', 'content' => 'x', 'status' => 'unpublished',
        ]);

        $response = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/announcements');

        $response->assertJsonCount(0, 'announcements');
    }

    /** A scheduled item whose time hasn't arrived yet is hidden. */
    public function test_future_scheduled_announcement_is_not_visible_to_investors(): void
    {
        Announcement::create([
            'title' => 'Future News', 'content' => 'x', 'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/announcements');

        $response->assertJsonCount(0, 'announcements');
    }

    /** A scheduled item whose time HAS arrived becomes visible (lazy sync). */
    public function test_due_scheduled_announcement_becomes_visible(): void
    {
        $a = Announcement::create([
            'title' => 'Due News', 'content' => 'x', 'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/announcements');

        $response->assertJsonCount(1, 'announcements');
        $this->assertDatabaseHas('announcements', ['id' => $a->id, 'status' => 'published']);
    }

    /** Published content is visible and fetchable by detail slug. */
    public function test_published_announcement_is_visible_and_has_detail_view(): void
    {
        $a = Announcement::create([
            'title' => 'Platform Update', 'content' => 'Full body text here', 'status' => 'published',
            'category' => 'platform_update', 'published_at' => now(),
        ]);

        $listResponse = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/announcements');
        $listResponse->assertJsonCount(1, 'announcements');

        $detailResponse = $this->actingAs($this->investor, 'sanctum')
            ->getJson("/api/investor-investment/announcements/{$a->slug}");
        $detailResponse->assertStatus(200);
        $detailResponse->assertJsonPath('announcement.title', 'Platform Update');
    }

    /** A draft's slug returns 404 to investors — no content leak via direct URL guess. */
    public function test_draft_announcement_detail_is_not_accessible_by_slug(): void
    {
        $a = Announcement::create(['title' => 'Secret Draft', 'content' => 'x', 'status' => 'draft']);

        $response = $this->actingAs($this->investor, 'sanctum')
            ->getJson("/api/investor-investment/announcements/{$a->slug}");

        $response->assertStatus(404);
    }

    /** Only admins can create/publish/unpublish news. */
    public function test_investor_cannot_manage_news(): void
    {
        $response = $this->actingAs($this->investor, 'sanctum')->postJson('/api/admin/announcements', [
            'title' => 'x', 'content' => 'y',
        ]);
        $response->assertStatus(403);
    }

    /** Admin can create a draft, then explicitly publish it. */
    public function test_admin_can_create_draft_then_publish(): void
    {
        $createResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/announcements', [
            'title' => 'New Notice', 'content' => 'Body', 'status' => 'draft', 'category' => 'important_notice',
        ]);
        $createResponse->assertStatus(201);
        $id = $createResponse->json('announcement.id');

        $this->assertDatabaseHas('announcements', ['id' => $id, 'status' => 'draft']);

        $publishResponse = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/announcements/{$id}/publish");
        $publishResponse->assertStatus(200);
        $this->assertDatabaseHas('announcements', ['id' => $id, 'status' => 'published', 'is_active' => 1]);
    }

    /** Existing bell-dropdown / dashboard code reading is_active directly
     *  still works unchanged against newly created published content. */
    public function test_is_active_stays_in_sync_for_backward_compatibility(): void
    {
        $a = Announcement::create(['title' => 'x', 'content' => 'y', 'status' => 'published']);
        $this->assertTrue($a->fresh()->is_active);

        $a->update(['status' => 'unpublished']);
        $this->assertFalse($a->fresh()->is_active);
    }
}
