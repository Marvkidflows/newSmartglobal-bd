<?php
// LOCATION: tests/Feature/FixtureSyncTest.php
//
// Gaming & Prediction — automatic synchronization. Covers importing new
// fixtures, updating existing ones (postponed/cancelled/finished +
// settlement), dedup, concurrency locking, and the admin sync-status/
// overview endpoints. Uses Http::fake() throughout — no real network
// call is ever made to football-data.org.

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Fixture;
use App\Models\FixtureMarket;
use App\Models\FixturePrediction;
use App\Models\FixtureSyncLog;
use App\Models\User;
use App\Services\FixtureSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FixtureSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        config(['services.football_data.api_key' => 'test-key']);

        Competition::query()->update(['is_enabled' => false]);
        Competition::whereIn('name', ['Premier League', 'La Liga'])->update(['is_enabled' => true]);
    }

    protected function matchesResponse(array $matches): array
    {
        return ['matches' => $matches];
    }

    public function test_sync_imports_new_scheduled_fixtures_as_unpublished(): void
    {
        Http::fake([
            '*/competitions/PL/matches' => Http::response($this->matchesResponse([
                [
                    'id' => 5001, 'status' => 'SCHEDULED',
                    'homeTeam' => ['name' => 'Arsenal'], 'awayTeam' => ['name' => 'Chelsea'],
                    'utcDate' => now()->addDays(3)->toIso8601String(),
                ],
            ])),
            '*/competitions/PD/matches' => Http::response($this->matchesResponse([])),
        ]);

        $sync = app(FixtureSyncService::class);
        $log = $sync->runFullSync('scheduler');

        $this->assertSame('success', $log->status);
        $this->assertSame(1, $log->fixtures_imported);
        $this->assertDatabaseHas('fixtures', [
            'external_id' => '5001', 'source' => 'api', 'is_published' => false, 'status' => 'scheduled',
        ]);
    }

    public function test_sync_never_creates_duplicate_fixtures_for_the_same_external_id(): void
    {
        Http::fake([
            '*/competitions/PL/matches' => Http::response($this->matchesResponse([
                [
                    'id' => 5001, 'status' => 'SCHEDULED',
                    'homeTeam' => ['name' => 'Arsenal'], 'awayTeam' => ['name' => 'Chelsea'],
                    'utcDate' => now()->addDays(3)->toIso8601String(),
                ],
            ])),
            '*/competitions/PD/matches' => Http::response($this->matchesResponse([])),
        ]);

        $sync = app(FixtureSyncService::class);
        $sync->runFullSync('scheduler');
        $sync->runFullSync('scheduler'); // repeat fetch — same provider match

        $this->assertSame(1, Fixture::where('external_id', '5001')->count());
    }

    public function test_sync_marks_an_existing_fixture_postponed_when_provider_reports_it(): void
    {
        $fixture = Fixture::create([
            'league' => 'Premier League', 'home_team' => 'Arsenal', 'away_team' => 'Chelsea',
            'kickoff_at' => now()->addDay(), 'status' => 'scheduled', 'source' => 'api',
            'is_published' => true, 'external_id' => '5002', 'created_by' => $this->admin->id,
        ]);

        Http::fake([
            '*/competitions/PL/matches' => Http::response($this->matchesResponse([
                [
                    'id' => 5002, 'status' => 'POSTPONED',
                    'homeTeam' => ['name' => 'Arsenal'], 'awayTeam' => ['name' => 'Chelsea'],
                    'utcDate' => now()->addDay()->toIso8601String(),
                ],
            ])),
            '*/competitions/PD/matches' => Http::response($this->matchesResponse([])),
        ]);

        $log = app(FixtureSyncService::class)->runFullSync('scheduler');

        $this->assertSame(1, $log->fixtures_updated);
        $this->assertSame('postponed', $fixture->fresh()->status);
        $this->assertSame('POSTPONED', $fixture->fresh()->provider_status);
    }

    public function test_sync_marks_an_existing_fixture_cancelled_when_provider_reports_it(): void
    {
        $fixture = Fixture::create([
            'league' => 'Premier League', 'home_team' => 'Arsenal', 'away_team' => 'Chelsea',
            'kickoff_at' => now()->addDay(), 'status' => 'scheduled', 'source' => 'api',
            'is_published' => true, 'external_id' => '5003', 'created_by' => $this->admin->id,
        ]);

        Http::fake([
            '*/competitions/PL/matches' => Http::response($this->matchesResponse([
                [
                    'id' => 5003, 'status' => 'CANCELLED',
                    'homeTeam' => ['name' => 'Arsenal'], 'awayTeam' => ['name' => 'Chelsea'],
                    'utcDate' => now()->addDay()->toIso8601String(),
                ],
            ])),
            '*/competitions/PD/matches' => Http::response($this->matchesResponse([])),
        ]);

        app(FixtureSyncService::class)->runFullSync('scheduler');

        $this->assertSame('cancelled', $fixture->fresh()->status);
    }

    public function test_sync_settles_predictions_automatically_when_provider_reports_final_score(): void
    {
        $fixture = Fixture::create([
            'league' => 'Premier League', 'home_team' => 'Arsenal', 'away_team' => 'Chelsea',
            'kickoff_at' => now()->subHours(2), 'status' => 'scheduled', 'source' => 'api',
            'is_published' => true, 'external_id' => '5004', 'created_by' => $this->admin->id,
        ]);
        $market = FixtureMarket::create(['fixture_id' => $fixture->id, 'market_type' => 'one_x_two', 'status' => 'closed']);
        $investor = User::factory()->create(['role' => 'investor']);
        $prediction = FixturePrediction::create([
            'fixture_market_id' => $market->id, 'user_id' => $investor->id,
            'selection' => 'home', 'submitted_at' => now(),
        ]);

        Http::fake([
            '*/competitions/PL/matches' => Http::response($this->matchesResponse([
                [
                    'id' => 5004, 'status' => 'FINISHED',
                    'homeTeam' => ['name' => 'Arsenal'], 'awayTeam' => ['name' => 'Chelsea'],
                    'utcDate' => now()->subHours(2)->toIso8601String(),
                    'score' => ['fullTime' => ['home' => 2, 'away' => 0]],
                ],
            ])),
            '*/competitions/PD/matches' => Http::response($this->matchesResponse([])),
        ]);

        app(FixtureSyncService::class)->runFullSync('scheduler');

        $fixture->refresh();
        $prediction->refresh();
        $this->assertSame('finished', $fixture->status);
        $this->assertSame(2, $fixture->home_score);
        $this->assertSame(0, $fixture->away_score);
        $this->assertTrue($prediction->is_correct);
        $this->assertSame(10, $prediction->points_awarded);
        $this->assertSame('settled', $market->fresh()->status);
    }

    public function test_sync_never_touches_manual_fixtures(): void
    {
        $manual = Fixture::create([
            'league' => 'Premier League', 'home_team' => 'Man City', 'away_team' => 'Spurs',
            'kickoff_at' => now()->addDay(), 'status' => 'scheduled', 'source' => 'manual',
            'is_published' => true, 'created_by' => $this->admin->id,
        ]);

        Http::fake([
            '*/competitions/PL/matches' => Http::response($this->matchesResponse([])),
            '*/competitions/PD/matches' => Http::response($this->matchesResponse([])),
        ]);

        app(FixtureSyncService::class)->runFullSync('scheduler');

        $this->assertSame('scheduled', $manual->fresh()->status);
    }

    public function test_sync_writes_a_failed_log_when_no_api_key_is_configured(): void
    {
        config(['services.football_data.api_key' => null]);

        $log = app(FixtureSyncService::class)->runFullSync('manual', $this->admin->id);

        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('No football-data.org API key', $log->error);
    }

    public function test_sync_prevents_overlapping_runs(): void
    {
        $lock = Cache::lock(FixtureSyncService::LOCK_KEY, 300);
        $this->assertTrue($lock->get());

        $log = app(FixtureSyncService::class)->runFullSync('scheduler');

        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('already in progress', $log->error);

        $lock->release();
    }

    public function test_admin_sync_status_endpoint_reports_last_run_and_progress_flag(): void
    {
        FixtureSyncLog::create([
            'source' => 'scheduler', 'status' => 'success', 'fixtures_imported' => 3, 'fixtures_updated' => 1,
            'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/fixtures/sync-status');

        $response->assertStatus(200)
            ->assertJsonPath('sync_in_progress', false)
            ->assertJsonPath('last_sync.fixtures_imported', 3);
    }

    public function test_admin_overview_endpoint_returns_expected_counts(): void
    {
        Fixture::create([
            'league' => 'Premier League', 'home_team' => 'A', 'away_team' => 'B',
            'kickoff_at' => now()->addDay(), 'status' => 'scheduled', 'source' => 'api',
            'is_published' => false, 'external_id' => '9001', 'created_by' => $this->admin->id,
        ]);
        Fixture::create([
            'league' => 'Premier League', 'home_team' => 'C', 'away_team' => 'D',
            'kickoff_at' => now()->addDay(), 'status' => 'scheduled', 'source' => 'manual',
            'is_published' => true, 'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/fixtures/overview');

        $response->assertStatus(200)
            ->assertJsonPath('counts.pending_review', 1)
            ->assertJsonPath('counts.published', 1)
            ->assertJsonPath('counts.upcoming', 1);
    }

    public function test_investor_cannot_access_sync_status_or_overview(): void
    {
        $investor = User::factory()->create(['role' => 'investor']);

        $this->actingAs($investor, 'sanctum')->getJson('/api/admin/fixtures/sync-status')->assertStatus(403);
        $this->actingAs($investor, 'sanctum')->getJson('/api/admin/fixtures/overview')->assertStatus(403);
    }
}
