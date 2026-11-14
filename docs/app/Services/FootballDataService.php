<?php
// LOCATION: app/Services/FootballDataService.php
//
// Gaming & Prediction — optional automatic fixture source. Wraps
// football-data.org's v4 API (verified against their current published
// docs, not guessed): base URL https://api.football-data.org/v4, auth
// via X-Auth-Token header, one endpoint per competition:
// /competitions/{code}/matches. Free tier: 10 requests/minute, and
// covers exactly 12 competitions total — see CATALOG below, which is
// the full list the free tier actually supports, not an arbitrary
// expansion of it.
//
// ADMIN LEAGUE SELECTION (this revision): which of those 12
// competitions are actually fetched is no longer a hardcoded constant.
// It's controlled by the `competitions` table (see Competition model
// and AdminCompetitionController) — an admin enables/disables
// individually, and only enabled ones are requested here. CATALOG below
// is still the fixed list of what the provider supports (used to seed
// that table and to validate against); it is not itself the "enabled"
// list.
//
// FAILURE HANDLING: this is explicitly an OPTIONAL source. Manual
// fixture entry (AdminFixtureController::store) is completely
// independent of this class and never touches it. If the API key is
// missing, no competitions are enabled, the rate limit is hit, or the
// service is down, this returns a clean {ok: false, error: ...} — it
// never fabricates a fixture, and the admin UI simply shows that error
// with manual entry sitting right there as an unaffected fallback.

namespace App\Services;

use App\Models\Competition;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FootballDataService
{
    // The full set of competitions football-data.org's free tier
    // supports (verified against their current published plan — 12
    // competitions, 10 req/min, delayed scores). This is a superset of
    // what used to be Fixture::LEAGUES; the original six are marked
    // enabled_by_default so existing installs behave the same after
    // migrating, and the other six free-tier competitions are seeded
    // disabled — available for an admin to switch on, not silently
    // active.
    public const CATALOG = [
        ['name' => 'Premier League',        'code' => 'PL',  'enabled_by_default' => true],
        ['name' => 'La Liga',               'code' => 'PD',  'enabled_by_default' => true],
        ['name' => 'Serie A',               'code' => 'SA',  'enabled_by_default' => true],
        ['name' => 'Bundesliga',            'code' => 'BL1', 'enabled_by_default' => true],
        ['name' => 'Ligue 1',               'code' => 'FL1', 'enabled_by_default' => true],
        ['name' => 'Champions League',      'code' => 'CL',  'enabled_by_default' => true],
        ['name' => 'Eredivisie',            'code' => 'DED', 'enabled_by_default' => false],
        ['name' => 'Primeira Liga',         'code' => 'PPL', 'enabled_by_default' => false],
        ['name' => 'Championship',          'code' => 'ELC', 'enabled_by_default' => false],
        ['name' => 'Brazilian Serie A',     'code' => 'BSA', 'enabled_by_default' => false],
        ['name' => 'FIFA World Cup',        'code' => 'WC',  'enabled_by_default' => false],
        ['name' => 'European Championship', 'code' => 'EC',  'enabled_by_default' => false],
    ];

    protected ?string $apiKey;
    protected string $baseUrl = 'https://api.football-data.org/v4';

    public function __construct()
    {
        $this->apiKey = config('services.football_data.api_key');
    }

    // Maps football-data.org's raw match status to Fixture::STATUSES.
    // IN_PLAY/PAUSED both mean "the match is currently being played" —
    // we don't distinguish half-time from live play at the fixture
    // level (markets are closed the moment kickoff passes regardless).
    // AWARDED (a very rare "result awarded without playing", e.g. a
    // forfeit) is treated as finished so it can still be resolved with
    // whatever score the provider supplies.
    public const STATUS_MAP = [
        'SCHEDULED' => 'scheduled',
        'TIMED'     => 'scheduled',
        'IN_PLAY'   => 'live',
        'PAUSED'    => 'live',
        'FINISHED'  => 'finished',
        'AWARDED'   => 'finished',
        'POSTPONED' => 'postponed',
        'SUSPENDED' => 'postponed',
        'CANCELLED' => 'cancelled',
    ];

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Single shared fetch: every match football-data.org currently has
     * on record for each enabled competition, unfiltered by status.
     * Both importing new fixtures and updating existing ones read from
     * this same result set, so a full sync never issues more than one
     * HTTP request per enabled competition.
     *
     * @return array{ok: bool, matches: array, competitions_checked: int, error: ?string}
     */
    public function fetchAllMatches(): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'matches' => [],
                'competitions_checked' => 0,
                'error' => 'No football-data.org API key is configured. Add FOOTBALL_DATA_API_KEY to .env, or continue using manual fixture entry.',
            ];
        }

        $enabled = Competition::enabled()->orderBy('sort_order')->get(['name', 'provider_code']);

        if ($enabled->isEmpty()) {
            return [
                'ok' => false,
                'matches' => [],
                'competitions_checked' => 0,
                'error' => 'No competitions are enabled. Enable at least one competition below, then fetch again.',
            ];
        }

        // Requests fire concurrently via Http::pool() rather than one
        // after another. Sequentially, 6 enabled competitions at up to
        // 10s each could take up to 60s total; concurrently, the whole
        // batch takes roughly as long as the SLOWEST single request —
        // usually a few seconds. This also reduces (though doesn't
        // eliminate) the odds of hitting PHP's execution time limit.
        //
        // Worth knowing: on a normal connection, football-data.org
        // typically responds in well under a second per competition —
        // if fetches are consistently slow, that points to the network
        // path itself (a local firewall/antivirus/VPN, or general
        // connection quality) rather than anything about the code or
        // whether the app is running locally vs. deployed. A deployed
        // server usually has a faster, more direct route to the
        // provider than a home/office connection does.
        // Only relevant to a web request: PHP-FPM/Apache's own
        // max_execution_time (commonly 30s) would otherwise kill an
        // admin's "Sync Now" click partway through a multi-league fetch.
        // In a console context (the scheduled command, or `php artisan
        // gaming:sync-fixtures` run by hand) PHP's CLI SAPI already
        // defaults max_execution_time to unlimited — imposing a 120s cap
        // there does the opposite of what's intended and can kill an
        // otherwise-healthy sync partway through, mid-database-write,
        // once enough fixtures have accumulated to process. Consecutive
        // legitimately concurrent runs are separately guarded against by
        // FixtureSyncService's cache lock, not by a time limit here.
        if (!app()->runningInConsole() && function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $matches = [];
        $errors = [];

        $responses = Http::pool(fn ($pool) => $enabled->map(
            fn ($competition) => $pool->as($competition->provider_code)
                ->withHeaders(['X-Auth-Token' => $this->apiKey])
                ->timeout(10)
                ->get("{$this->baseUrl}/competitions/{$competition->provider_code}/matches")
        )->all());

        foreach ($enabled as $competition) {
            $league = $competition->name;
            $code = $competition->provider_code;
            $response = $responses[$code] ?? null;

            // Http::pool() catches connection-level failures (timeouts,
            // DNS errors, etc.) per-request and hands back the exception
            // object instead of throwing — so this replaces the old
            // try/catch around each individual request.
            if ($response instanceof \Throwable) {
                Log::warning('FootballDataService fetch failed', ['league' => $league, 'error' => $response->getMessage()]);
                $errors[] = "Could not reach football-data.org for {$league}.";
                continue;
            }

            if ($response->status() === 429) {
                $errors[] = "Rate limit reached while fetching {$league} — try again in a minute.";
                continue;
            }
            if (!$response->successful()) {
                $errors[] = "Could not fetch {$league} (HTTP {$response->status()}).";
                continue;
            }

            foreach (($response->json('matches') ?? []) as $m) {
                $rawStatus = $m['status'] ?? '';
                $matches[] = [
                    'external_id'     => (string) ($m['id'] ?? ''),
                    'league'          => $league,
                    'home_team'       => $m['homeTeam']['name'] ?? 'TBD',
                    'away_team'       => $m['awayTeam']['name'] ?? 'TBD',
                    'kickoff_at'      => $m['utcDate'] ?? null,
                    'provider_status' => $rawStatus,
                    'status'          => self::STATUS_MAP[$rawStatus] ?? null,
                    'home_score'      => $m['score']['fullTime']['home'] ?? null,
                    'away_score'      => $m['score']['fullTime']['away'] ?? null,
                ];
            }
        }

        if (empty($matches) && !empty($errors)) {
            return ['ok' => false, 'matches' => [], 'competitions_checked' => $enabled->count(), 'error' => implode(' ', $errors)];
        }

        return [
            'ok' => true,
            'matches' => $matches,
            'competitions_checked' => $enabled->count(),
            'error' => empty($errors) ? null : implode(' ', $errors),
        ];
    }

    /**
     * Fetch upcoming scheduled fixtures across whichever competitions
     * the admin currently has enabled in the `competitions` table.
     * Thin filter over fetchAllMatches() — kept as its own method since
     * "fixtures worth importing" (not yet kicked off) is a narrower
     * question than "everything the provider knows about".
     *
     * @return array{ok: bool, fixtures: array, error: ?string}
     */
    public function fetchUpcomingFixtures(): array
    {
        $result = $this->fetchAllMatches();

        $fixtures = array_values(array_filter(array_map(function ($m) {
            if (!in_array($m['provider_status'], ['SCHEDULED', 'TIMED'])) return null;
            return [
                'external_id' => $m['external_id'],
                'league'      => $m['league'],
                'home_team'   => $m['home_team'],
                'away_team'   => $m['away_team'],
                'kickoff_at'  => $m['kickoff_at'],
            ];
        }, $result['matches'])));

        return ['ok' => $result['ok'], 'fixtures' => $fixtures, 'error' => $result['error']];
    }

    /**
     * The list of competition names football-data.org's free tier
     * supports at all (enabled or not) — used to validate/seed against,
     * never to decide what gets fetched.
     */
    public static function catalogNames(): array
    {
        return array_column(self::CATALOG, 'name');
    }

    /**
     * Refresh the `competitions` table from football-data.org's own
     * /v4/competitions list, per the "prefer retrieving available
     * competitions from the provider over a hardcoded list" requirement.
     * Existing rows (matched by provider_code) keep their current
     * is_enabled state and sort_order untouched — this only adds
     * competitions the provider newly exposes (disabled by default,
     * same convention as the initial seed) and refreshes display names.
     * Never removes a competition an admin may have fixtures against.
     *
     * @return array{ok: bool, added: int, updated: int, error: ?string}
     */
    public function syncCompetitionsFromProvider(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'added' => 0, 'updated' => 0, 'error' => 'No football-data.org API key is configured.'];
        }

        try {
            $response = Http::withHeaders(['X-Auth-Token' => $this->apiKey])
                ->timeout(15)
                ->get("{$this->baseUrl}/competitions");
        } catch (\Throwable $e) {
            Log::warning('FootballDataService competitions sync failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'added' => 0, 'updated' => 0, 'error' => 'Could not reach football-data.org.'];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'added' => 0, 'updated' => 0, 'error' => "Could not fetch competitions (HTTP {$response->status()})."];
        }

        $provided = collect($response->json('competitions') ?? [])
            // Free tier only ever has access to TIER_ONE competitions —
            // filtering here avoids seeding leagues the configured key
            // can never actually fetch matches for.
            ->filter(fn ($c) => ($c['plan'] ?? null) === 'TIER_ONE' && !empty($c['code']));

        $added = 0;
        $updated = 0;
        $maxSort = Competition::max('sort_order') ?? 0;

        foreach ($provided as $c) {
            $existing = Competition::where('provider_code', $c['code'])->first();
            if ($existing) {
                if ($existing->name !== $c['name']) {
                    $existing->update(['name' => $c['name']]);
                    $updated++;
                }
                continue;
            }

            Competition::create([
                'name'          => $c['name'],
                'provider_code' => $c['code'],
                'is_enabled'    => false, // present, not silently active — same convention as the initial seed
                'sort_order'    => ++$maxSort,
            ]);
            $added++;
        }

        return ['ok' => true, 'added' => $added, 'updated' => $updated, 'error' => null];
    }
}
