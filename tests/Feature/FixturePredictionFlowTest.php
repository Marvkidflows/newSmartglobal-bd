<?php
// LOCATION: tests/Feature/FixturePredictionFlowTest.php
//
// Gaming & Prediction — end-to-end coverage of the flows described in
// the project spec: API fixture -> pending review -> admin publishes ->
// investor predicts; manual fixture -> markets -> publish -> predict;
// and every server-side validation rule around submitting a prediction
// (never trust the frontend).

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\FixtureMarket;
use App\Models\FixturePrediction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixturePredictionFlowTest extends TestCase
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

    protected function openFixture(array $overrides = []): Fixture
    {
        return Fixture::create(array_merge([
            'league' => 'Premier League', 'home_team' => 'Arsenal', 'away_team' => 'Chelsea',
            'kickoff_at' => now()->addDay(), 'status' => 'scheduled', 'source' => 'manual',
            'is_published' => true, 'created_by' => $this->admin->id,
        ], $overrides));
    }

    // ── Manual fixture end-to-end ───────────────────────────────────

    public function test_manual_fixture_flow_create_market_publish_and_predict(): void
    {
        $create = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/fixtures', [
            'league' => 'Premier League', 'home_team' => 'Arsenal', 'away_team' => 'Chelsea',
            'kickoff_at' => now()->addDay()->toDateTimeString(),
        ]);
        $create->assertStatus(201);
        $fixtureId = $create->json('fixture.id');

        // Manual fixtures publish immediately.
        $this->assertDatabaseHas('fixtures', ['id' => $fixtureId, 'is_published' => true]);

        $market = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/fixtures/{$fixtureId}/markets", ['market_type' => 'one_x_two'])
            ->assertStatus(201)
            ->json('market');

        $predict = $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market['id']}/predict", ['selection' => 'home']);

        $predict->assertStatus(201)->assertJsonPath('message', 'Prediction submitted.');
        $this->assertDatabaseHas('fixture_predictions', [
            'fixture_market_id' => $market['id'], 'user_id' => $this->investor->id, 'selection' => 'home',
        ]);
    }

    // ── API fixture end-to-end (pending review -> publish) ─────────

    public function test_api_fixture_stays_hidden_from_investor_until_admin_publishes(): void
    {
        $fixture = $this->openFixture([
            'source' => 'api', 'is_published' => false, 'external_id' => 'ext-1',
        ]);
        FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'open']);

        $before = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/fixtures');
        $this->assertCount(0, $before->json('upcoming'));

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/fixtures/{$fixture->id}/publish")
            ->assertStatus(200);

        $after = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/fixtures');
        $this->assertCount(1, $after->json('upcoming'));
    }

    // ── Prediction submission validation (server-side, never trusts frontend) ──

    public function test_guest_cannot_submit_a_prediction(): void
    {
        $fixture = $this->openFixture();
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'open']);

        $this->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'home'])
            ->assertStatus(401);
    }

    public function test_unpublished_fixture_market_rejects_predictions(): void
    {
        $fixture = $this->openFixture(['source' => 'api', 'is_published' => false, 'external_id' => 'ext-2']);
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'open']);

        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'home'])
            ->assertStatus(422);
    }

    public function test_closed_market_rejects_predictions(): void
    {
        $fixture = $this->openFixture();
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'closed']);

        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'home'])
            ->assertStatus(422);
    }

    public function test_fixture_that_has_already_kicked_off_rejects_predictions(): void
    {
        $fixture = $this->openFixture(['kickoff_at' => now()->subMinute()]);
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'open']);

        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'home'])
            ->assertStatus(422);
    }

    public function test_cancelled_fixture_rejects_predictions(): void
    {
        $fixture = $this->openFixture(['status' => 'cancelled']);
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'open']);

        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'home'])
            ->assertStatus(422);
    }

    public function test_postponed_fixture_rejects_predictions(): void
    {
        $fixture = $this->openFixture(['status' => 'postponed']);
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'open']);

        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'home'])
            ->assertStatus(422);
    }

    public function test_invalid_option_for_the_market_type_is_rejected(): void
    {
        $fixture = $this->openFixture();
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'open']);

        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'over'])
            ->assertStatus(422);
    }

    public function test_invalid_market_id_returns_not_found(): void
    {
        $this->actingAs($this->investor, 'sanctum')
            ->postJson('/api/investor-investment/fixtures/markets/999999/predict', ['selection' => 'home'])
            ->assertStatus(404);
    }

    public function test_duplicate_prediction_on_the_same_market_is_rejected(): void
    {
        $fixture = $this->openFixture();
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'open']);

        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'home'])
            ->assertStatus(201);

        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'away'])
            ->assertStatus(422);

        $this->assertSame(1, FixturePrediction::where('fixture_market_id', $market->id)->where('user_id', $this->investor->id)->count());
    }

    public function test_admin_can_view_predictions_taken_on_a_fixture(): void
    {
        $fixture = $this->openFixture();
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'open']);
        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'home'])
            ->assertStatus(201);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/fixtures/{$fixture->id}/predictions");

        $response->assertStatus(200)
            ->assertJsonPath('markets.0.market_type', 'one_x_two')
            ->assertJsonPath('markets.0.predictions.0.selection', 'home')
            ->assertJsonPath('markets.0.predictions.0.user_email', $this->investor->email);
    }

    public function test_investor_cannot_view_predictions_taken_endpoint(): void
    {
        $fixture = $this->openFixture();

        $this->actingAs($this->investor, 'sanctum')
            ->getJson("/api/admin/fixtures/{$fixture->id}/predictions")
            ->assertStatus(403);
    }

    // ── Admin authorization ─────────────────────────────────────────

    public function test_unauthorized_investor_cannot_access_admin_fixture_endpoints(): void
    {
        $this->actingAs($this->investor, 'sanctum')->getJson('/api/admin/fixtures')->assertStatus(403);
        $this->actingAs($this->investor, 'sanctum')->postJson('/api/admin/fixtures/fetch-api')->assertStatus(403);
    }

    public function test_invalid_fixture_id_on_publish_returns_not_found(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/admin/fixtures/999999/publish')
            ->assertStatus(404);
    }

    // ── Admin lifecycle actions ─────────────────────────────────────

    public function test_admin_can_postpone_and_reinstate_a_fixture(): void
    {
        $fixture = $this->openFixture();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/fixtures/{$fixture->id}/postpone")
            ->assertStatus(200);
        $this->assertSame('postponed', $fixture->fresh()->status);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/fixtures/{$fixture->id}/reinstate")
            ->assertStatus(200);
        $this->assertSame('scheduled', $fixture->fresh()->status);
    }

    public function test_finished_fixture_cannot_be_postponed_or_edited(): void
    {
        $fixture = $this->openFixture(['status' => 'finished', 'home_score' => 1, 'away_score' => 0]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/fixtures/{$fixture->id}/postpone")
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/fixtures/{$fixture->id}", ['home_team' => 'Someone Else'])
            ->assertStatus(422);
    }

    public function test_admin_can_bulk_publish_pending_review_fixtures(): void
    {
        $a = $this->openFixture(['source' => 'api', 'is_published' => false, 'external_id' => 'bp-1']);
        $b = $this->openFixture(['source' => 'api', 'is_published' => false, 'external_id' => 'bp-2']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/fixtures/bulk-publish', ['ids' => [$a->id, $b->id]]);

        $response->assertStatus(200);
        $this->assertTrue($a->fresh()->is_published);
        $this->assertTrue($b->fresh()->is_published);
    }

    public function test_resolve_settles_all_markets_and_predictions_for_a_fixture(): void
    {
        $fixture = $this->openFixture();
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'over_under', 'line' => 2.5, 'status' => 'open']);
        $this->actingAs($this->investor, 'sanctum')
            ->postJson("/api/investor-investment/fixtures/markets/{$market->id}/predict", ['selection' => 'over'])
            ->assertStatus(201);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/fixtures/{$fixture->id}/resolve", ['home_score' => 2, 'away_score' => 1])
            ->assertStatus(200);

        $prediction = FixturePrediction::where('fixture_market_id', $market->id)->first();
        $this->assertTrue($prediction->is_correct); // total goals 3 > 2.5 line
        $this->assertSame(10, $prediction->points_awarded);
    }
}
