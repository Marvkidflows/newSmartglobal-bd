<?php
// LOCATION: tests/Feature/AdminCompetitionTest.php
//
// Gaming & Prediction — admin league selection. Covers the new
// competitions table end to end: seeding, access control, toggling,
// and that FootballDataService only ever fetches enabled competitions.

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\User;
use App\Services\FootballDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminCompetitionTest extends TestCase
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

    public function test_migration_seeds_all_twelve_catalog_competitions(): void
    {
        $this->assertSame(12, Competition::count());
        $this->assertEqualsCanonicalizing(
            FootballDataService::catalogNames(),
            Competition::pluck('name')->all()
        );
    }

    public function test_original_six_leagues_are_enabled_by_default_others_are_not(): void
    {
        $enabledNames = Competition::enabled()->pluck('name')->all();

        $this->assertEqualsCanonicalizing([
            'Premier League', 'La Liga', 'Serie A', 'Bundesliga', 'Ligue 1', 'Champions League',
        ], $enabledNames);

        $this->assertFalse(Competition::where('name', 'Eredivisie')->first()->is_enabled);
    }

    public function test_investor_cannot_access_admin_competition_endpoints(): void
    {
        $this->actingAs($this->investor, 'sanctum')
            ->getJson('/api/admin/competitions')
            ->assertStatus(403);
    }

    public function test_guest_cannot_access_admin_competition_endpoints(): void
    {
        $this->getJson('/api/admin/competitions')->assertStatus(401);
    }

    public function test_admin_can_view_competitions(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/competitions');

        $response->assertStatus(200)
            ->assertJsonCount(12, 'competitions')
            ->assertJsonStructure(['competitions' => [['id', 'name', 'provider_code', 'is_enabled']], 'football_data_configured']);
    }

    public function test_admin_can_enable_a_disabled_competition(): void
    {
        $eredivisie = Competition::where('name', 'Eredivisie')->first();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/competitions/{$eredivisie->id}/toggle", ['is_enabled' => true]);

        $response->assertStatus(200);
        $this->assertTrue($eredivisie->fresh()->is_enabled);
    }

    public function test_admin_can_disable_an_enabled_competition(): void
    {
        $premierLeague = Competition::where('name', 'Premier League')->first();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/competitions/{$premierLeague->id}/toggle", ['is_enabled' => false]);

        $response->assertStatus(200);
        $this->assertFalse($premierLeague->fresh()->is_enabled);
    }

    public function test_disabling_a_competition_does_not_delete_or_unpublish_its_existing_fixtures(): void
    {
        $premierLeague = Competition::where('name', 'Premier League')->first();

        $fixture = \App\Models\Fixture::create([
            'league' => 'Premier League', 'home_team' => 'Team A', 'away_team' => 'Team B',
            'kickoff_at' => now()->addDay(), 'status' => 'scheduled', 'source' => 'manual',
            'is_published' => true, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/competitions/{$premierLeague->id}/toggle", ['is_enabled' => false])
            ->assertStatus(200);

        $this->assertDatabaseHas('fixtures', ['id' => $fixture->id, 'is_published' => true]);
    }

    public function test_fetch_from_api_only_requests_enabled_competitions(): void
    {
        config(['services.football_data.api_key' => 'test-key']);

        // Disable everything except Premier League and La Liga.
        Competition::query()->update(['is_enabled' => false]);
        Competition::whereIn('name', ['Premier League', 'La Liga'])->update(['is_enabled' => true]);

        Http::fake([
            'api.football-data.org/*' => Http::response(['matches' => []], 200),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/fixtures/fetch-api')
            ->assertStatus(200);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/competitions/PL/matches'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/competitions/PD/matches'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/competitions/SA/matches'));
    }

    public function test_fetch_from_api_fails_cleanly_when_no_competitions_are_enabled(): void
    {
        config(['services.football_data.api_key' => 'test-key']);
        Competition::query()->update(['is_enabled' => false]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/fixtures/fetch-api');

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'No competitions are enabled. Enable at least one competition below, then fetch again.']);
    }

    public function test_manual_fixture_creation_still_accepts_any_catalog_league_regardless_of_enabled_state(): void
    {
        // Manual entry is independent of the API enable/disable toggle —
        // an admin can still manually log a fixture from a currently
        // disabled competition (e.g. Eredivisie).
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/fixtures', [
            'league' => 'Eredivisie', 'home_team' => 'Ajax', 'away_team' => 'PSV',
            'kickoff_at' => now()->addDay()->toDateTimeString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('fixtures', ['league' => 'Eredivisie', 'source' => 'manual', 'is_published' => true]);
    }

    public function test_manual_fixture_creation_rejects_a_league_outside_the_catalog(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/fixtures', [
            'league' => 'Some Made Up League', 'home_team' => 'A', 'away_team' => 'B',
            'kickoff_at' => now()->addDay()->toDateTimeString(),
        ]);

        $response->assertStatus(422);
    }
}
