<?php
// LOCATION: app/Http/Controllers/Admin/AdminFixtureController.php
//
// Gaming & Prediction — FINAL SPEC (sports fixtures). Replaces the earlier
// crypto up/down prediction module as the platform's one Gaming &
// Prediction section, per explicit "do not introduce additional
// gaming/prediction features" instruction. The earlier module's backend
// code (PredictionRound/PredictionEntry) is left in place, untouched and
// unlinked from navigation, rather than deleted — non-destructive in case
// it's wanted again, but no longer the active feature.
//
// SCOPE, exactly as specified — nothing more:
//   - Fixtures from top leagues only (Fixture::LEAGUES, a fixed list —
//     no live fixture provider is configured in this codebase, so
//     fixtures are entered manually by admin; nothing fabricated).
//   - Exactly four market types: 1X2, Double Chance, Over/Under, Handicap.
//   - Points only. No stake, no wallet/balance read or write anywhere in
//     this file or in InvestorFixtureController — verified by inspection,
//     same invariant the crypto module was held to.
//   - No odds, no payout ratios, no betting-style stake amounts: a
//     correct prediction earns a flat point award (see resolve()).
//
// If real-money wagering on these markets is ever the actual requirement,
// that needs a licensed gambling provider and explicit legal sign-off —
// neither exists in this codebase, and this controller does not build
// toward it.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fixture;
use App\Models\FixtureMarket;
use App\Services\FootballDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminFixtureController extends Controller
{
    protected int $pointsPerCorrectPrediction = 10;
    protected FootballDataService $footballData;

    public function __construct(FootballDataService $footballData)
    {
        $this->footballData = $footballData;
    }

    // GET /admin/fixtures
    public function index(Request $request)
    {
        $query = Fixture::with('markets')->withCount('markets');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('published')) {
            $query->where('is_published', (bool) $request->published);
        }

        return response()->json([
            'fixtures' => $query->orderByDesc('kickoff_at')->paginate(20),
            'leagues'  => Fixture::leagues(),
            'football_data_configured' => $this->footballData->isConfigured(),
        ]);
    }

    // POST /admin/fixtures
    public function store(Request $request)
    {
        $validated = $request->validate([
            'league'     => ['required', 'string', 'in:' . implode(',', Fixture::leagues())],
            'home_team'  => ['required', 'string', 'max:100'],
            'away_team'  => ['required', 'string', 'max:100', 'different:home_team'],
            'kickoff_at' => ['required', 'date', 'after:now'],
        ]);

        // Manual fixtures — existing, unchanged behavior: published
        // immediately, since an admin typing it in IS the review step.
        $fixture = Fixture::create([
            ...$validated,
            'status'       => 'scheduled',
            'source'       => 'manual',
            'is_published' => true,
            'created_by'   => Auth::id(),
        ]);

        return response()->json(['message' => 'Fixture created.', 'fixture' => $fixture], 201);
    }

    /**
     * POST /admin/fixtures/fetch-api
     * Pulls upcoming fixtures from football-data.org for the six top
     * leagues and stores any NEW ones (by external_id) as unpublished,
     * pending admin review. Never touches manual fixtures. Never
     * publishes anything automatically.
     */
    public function fetchFromApi()
    {
        $result = $this->footballData->fetchUpcomingFixtures();

        if (!$result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        $imported = 0;
        foreach ($result['fixtures'] as $f) {
            if (empty($f['external_id']) || empty($f['kickoff_at'])) continue;

            // Skip anything already imported — dedup by football-data.org's
            // own match ID, not by team names (which can collide/vary).
            if (Fixture::where('external_id', $f['external_id'])->exists()) continue;

            Fixture::create([
                'league'       => $f['league'],
                'home_team'    => $f['home_team'],
                'away_team'    => $f['away_team'],
                'kickoff_at'   => $f['kickoff_at'],
                'status'       => 'scheduled',
                'source'       => 'api',
                'is_published' => false, // pending review — never auto-exposed
                'external_id'  => $f['external_id'],
                'created_by'   => Auth::id(),
            ]);
            $imported++;
        }

        return response()->json([
            'message'  => $imported > 0
                ? "{$imported} new fixture(s) fetched — review and publish them below."
                : 'No new fixtures found (everything already imported or none upcoming).',
            'imported' => $imported,
            'warning'  => $result['error'], // e.g. one league's request failed but others succeeded
        ]);
    }

    // PATCH /admin/fixtures/{fixture}/publish
    public function publish(Fixture $fixture)
    {
        if ($fixture->is_published) {
            return response()->json(['message' => 'Already published.'], 422);
        }
        $fixture->update(['is_published' => true]);
        return response()->json(['message' => 'Fixture published — now visible in Gaming & Prediction.', 'fixture' => $fixture]);
    }

    // PATCH /admin/fixtures/{fixture}/unpublish
    public function unpublish(Fixture $fixture)
    {
        $fixture->update(['is_published' => false]);
        return response()->json(['message' => 'Fixture unpublished.', 'fixture' => $fixture]);
    }

    // DELETE /admin/fixtures/{fixture} — discard an API-fetched fixture
    // an admin doesn't want (e.g. rejected during review).
    public function destroy(Fixture $fixture)
    {
        if ($fixture->is_published && $fixture->markets()->exists()) {
            return response()->json(['message' => 'Cannot delete a published fixture with markets — cancel it instead.'], 422);
        }
        $fixture->delete();
        return response()->json(['message' => 'Fixture discarded.']);
    }

    /**
     * POST /admin/fixtures/{fixture}/markets
     * Attaches one of the four allowed market types to a fixture.
     */
    public function addMarket(Request $request, Fixture $fixture)
    {
        $validated = $request->validate([
            'market_type' => ['required', 'in:' . implode(',', FixtureMarket::TYPES)],
            // Over/Under and Handicap need a half-integer line so there's
            // never a push/tie to resolve. 1X2 and Double Chance ignore it.
            'line' => ['required_if:market_type,over_under,handicap', 'nullable', 'numeric'],
        ]);

        if (in_array($validated['market_type'], ['over_under', 'handicap']) && isset($validated['line'])) {
            if (fmod(abs((float) $validated['line']), 1.0) !== 0.5) {
                return response()->json(['message' => 'Line must end in .5 (e.g. 2.5 or -1.5) so there is never a tie to resolve.'], 422);
            }
        }

        try {
            $market = $fixture->markets()->create([
                'market_type' => $validated['market_type'],
                'line'        => $validated['line'] ?? null,
                'status'      => 'open',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message' => 'That market type already exists on this fixture.'], 422);
        }

        return response()->json(['message' => 'Market added.', 'market' => $market], 201);
    }

    // DELETE /admin/fixtures/{fixture}/markets/{market}
    public function removeMarket(Fixture $fixture, FixtureMarket $market)
    {
        if ($market->fixture_id !== $fixture->id) {
            return response()->json(['message' => 'Market does not belong to this fixture.'], 404);
        }
        if ($market->status === 'settled') {
            return response()->json(['message' => 'Cannot remove a settled market.'], 422);
        }
        $market->delete();
        return response()->json(['message' => 'Market removed.']);
    }

    // PATCH /admin/fixtures/{fixture}/cancel
    public function cancel(Fixture $fixture)
    {
        if ($fixture->status === 'finished') {
            return response()->json(['message' => 'This fixture has already been resolved.'], 422);
        }
        $fixture->update(['status' => 'cancelled']);
        return response()->json(['message' => 'Fixture cancelled. No points were awarded or deducted.', 'fixture' => $fixture]);
    }

    /**
     * PATCH /admin/fixtures/{fixture}/resolve
     * Admin enters the real final score. Every market attached to this
     * fixture, and every prediction on every one of those markets, is
     * settled in a single transaction from that one score — see
     * Fixture::isSelectionCorrect() for the actual per-market-type logic.
     */
    public function resolve(Request $request, Fixture $fixture)
    {
        if ($fixture->status === 'finished') {
            return response()->json(['message' => 'This fixture has already been resolved.'], 422);
        }
        if ($fixture->status === 'cancelled') {
            return response()->json(['message' => 'This fixture was cancelled.'], 422);
        }

        $validated = $request->validate([
            'home_score' => ['required', 'integer', 'min:0', 'max:50'],
            'away_score' => ['required', 'integer', 'min:0', 'max:50'],
        ]);

        DB::transaction(function () use ($fixture, $validated) {
            $fixture->update($validated);

            $fixture->markets()->with('predictions')->get()->each(function (FixtureMarket $market) use ($fixture) {
                foreach ($market->predictions as $prediction) {
                    $correct = $fixture->isSelectionCorrect($market->market_type, $market->line ? (float) $market->line : null, $prediction->selection);
                    $prediction->update([
                        'is_correct'     => $correct,
                        'points_awarded' => $correct ? $this->pointsPerCorrectPrediction : 0,
                    ]);
                }
                $market->update(['status' => 'settled']);
            });

            $fixture->update([
                'status'      => 'finished',
                'resolved_by' => Auth::id(),
                'resolved_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Fixture resolved and all predictions settled.', 'fixture' => $fixture->fresh('markets.predictions')]);
    }
}
