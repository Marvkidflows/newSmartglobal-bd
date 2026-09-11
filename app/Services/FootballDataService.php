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

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Fetch upcoming scheduled fixtures across whichever competitions
     * the admin currently has enabled in the `competitions` table.
     *
     * @return array{ok: bool, fixtures: array, error: ?string}
     */
    public function fetchUpcomingFixtures(): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'fixtures' => [],
                'error' => 'No football-data.org API key is configured. Add FOOTBALL_DATA_API_KEY to .env, or continue using manual fixture entry.',
            ];
        }

        $enabled = Competition::enabled()->orderBy('sort_order')->get(['name', 'provider_code']);

        if ($enabled->isEmpty()) {
            return [
                'ok' => false,
                'fixtures' => [],
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
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $fixtures = [];
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

            $matches = $response->json('matches') ?? [];

            foreach ($matches as $m) {
                // Only fixtures that haven't kicked off yet are worth
                // reviewing for prediction purposes.
                if (!in_array($m['status'] ?? '', ['SCHEDULED', 'TIMED'])) continue;

                $fixtures[] = [
                    'external_id' => (string) ($m['id'] ?? ''),
                    'league'      => $league,
                    'home_team'   => $m['homeTeam']['name'] ?? 'TBD',
                    'away_team'   => $m['awayTeam']['name'] ?? 'TBD',
                    'kickoff_at'  => $m['utcDate'] ?? null,
                ];
            }
        }

        if (empty($fixtures) && !empty($errors)) {
            return ['ok' => false, 'fixtures' => [], 'error' => implode(' ', $errors)];
        }

        return ['ok' => true, 'fixtures' => $fixtures, 'error' => empty($errors) ? null : implode(' ', $errors)];
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
}
