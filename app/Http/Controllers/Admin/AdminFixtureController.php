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
use App\Models\FixturePrediction;
use App\Models\FixtureSyncLog;
use App\Services\FixtureSyncService;
use App\Services\FootballDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminFixtureController extends Controller
{
    protected int $pointsPerCorrectPrediction = 10;
    protected FootballDataService $footballData;
    protected FixtureSyncService $sync;

    public function __construct(FootballDataService $footballData, FixtureSyncService $sync)
    {
        $this->footballData = $footballData;
        $this->sync = $sync;
    }

    // GET /admin/fixtures
    // Filters: status, source, published, league (competition), search
    // (home/away team, case-insensitive), date_from / date_to (kickoff
    // range, inclusive) — everything the "Gaming → Fixtures" admin list
    // needs to narrow down a large fixture set without extra pages.
    public function index(Request $request)
    {
        $query = Fixture::with('markets')->withCount(['markets', 'predictions']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('published')) {
            $query->where('is_published', (bool) $request->published);
        }
        if ($request->filled('league')) {
            $query->where('league', $request->league);
        }
        if ($request->filled('search')) {
            $term = $request->string('search')->trim();
            $query->where(function ($q) use ($term) {
                $q->where('home_team', 'like', "%{$term}%")
                  ->orWhere('away_team', 'like', "%{$term}%");
            });
        }
        if ($request->filled('date_from')) {
            $query->whereDate('kickoff_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('kickoff_at', '<=', $request->date('date_to'));
        }

        return response()->json([
            'fixtures' => $query->orderByDesc('kickoff_at')->paginate(20)->withQueryString(),
            'leagues'  => Fixture::leagues(),
            'football_data_configured' => $this->footballData->isConfigured(),
        ]);
    }

    /**
     * GET /admin/fixtures/overview
     * Cards + recent activity for the Gaming & Prediction admin
     * landing view — "what needs my attention right now".
     */
    public function overview()
    {
        $counts = [
            'upcoming'        => Fixture::published()->where('status', 'scheduled')->where('kickoff_at', '>', now())->count(),
            'pending_review'  => Fixture::pendingReview()->count(),
            'published'       => Fixture::published()->count(),
            'live'            => Fixture::where('status', 'live')->count(),
            'completed'       => Fixture::where('status', 'finished')->count(),
            'postponed'       => Fixture::where('status', 'postponed')->count(),
            'cancelled'       => Fixture::where('status', 'cancelled')->count(),
            'predictions'     => FixturePrediction::count(),
        ];

        $recentActivity = Fixture::orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'league', 'home_team', 'away_team', 'status', 'is_published', 'source', 'updated_at']);

        return response()->json([
            'counts'           => $counts,
            'recent_activity'  => $recentActivity,
        ]);
    }

    /**
     * GET /admin/fixtures/sync-status
     * Powers the "Last Sync / Sync Status / Next Sync" panel plus a
     * short recent-run history.
     */
    public function syncStatus()
    {
        $last = FixtureSyncLog::latest('started_at')->first();
        $recent = FixtureSyncLog::latest('started_at')->limit(5)->get();

        return response()->json([
            'football_data_configured' => $this->footballData->isConfigured(),
            'sync_in_progress'         => $this->sync->isRunning(),
            // Matches the schedule registered in App\Console\Kernel —
            // if that interval changes, update this alongside it.
            'sync_interval_minutes'    => 30,
            'last_sync'                => $last,
            'recent_syncs'             => $recent,
        ]);
    }

    /**
     * GET /admin/fixtures/{fixture}/predictions
     * "Where does admin see the predictions taken" — every submission on
     * every market for this fixture, grouped by market, with who picked
     * what and (once resolved) whether they were right. This is the
     * detail view behind the predictions count shown in the fixture list
     * and the overview dashboard's total.
     */
    public function predictions(Fixture $fixture)
    {
        $markets = $fixture->markets()
            ->with(['predictions' => fn ($q) => $q->with('user:id,name,full_name,email')->orderByDesc('submitted_at')])
            ->get();

        return response()->json([
            'fixture' => $fixture->only(['id', 'league', 'home_team', 'away_team', 'kickoff_at', 'status']),
            'markets' => $markets->map(fn (FixtureMarket $m) => [
                'id'          => $m->id,
                'market_type' => $m->market_type,
                'line'        => $m->line,
                'status'      => $m->status,
                'predictions' => $m->predictions->map(fn (FixturePrediction $p) => [
                    'id'             => $p->id,
                    'user_name'      => $p->user->name ?? $p->user->full_name ?? 'Unknown',
                    'user_email'     => $p->user->email ?? null,
                    'selection'      => $p->selection,
                    'is_correct'     => $p->is_correct,
                    'points_awarded' => $p->points_awarded,
                    'submitted_at'   => $p->submitted_at,
                ]),
            ]),
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
     * Full synchronization pass, same one FixtureSyncService::runFullSync()
     * runs on schedule: imports any new fixtures (unpublished, pending
     * review) AND refreshes status/kickoff/score on fixtures already
     * imported — all from a single request per enabled competition.
     * Never touches manual fixtures. Never publishes anything
     * automatically. This is the backing action for the admin's
     * "Sync Now" button.
     */
    public function fetchFromApi()
    {
        $log = $this->sync->runFullSync('manual', Auth::id());

        if ($log->status === 'failed') {
            return response()->json(['message' => $log->error], 422);
        }

        return response()->json([
            'message'  => $log->message,
            'imported' => $log->fixtures_imported,
            'updated'  => $log->fixtures_updated,
            'warning'  => $log->error, // e.g. one league's request failed but others succeeded
        ]);
    }

    // PATCH /admin/fixtures/{fixture} — correct details on a fixture
    // before (or shortly after) publishing it. Kept deliberately narrow:
    // team names, league, and kickoff time only — never status, score,
    // or publish state, which each have their own dedicated action so
    // the audit trail (and the UI) stays unambiguous about what
    // happened.
    public function update(Request $request, Fixture $fixture)
    {
        if (!$fixture->is_editable) {
            return response()->json(['message' => 'This fixture has already been resolved or cancelled and can no longer be edited.'], 422);
        }

        $validated = $request->validate([
            'league'     => ['sometimes', 'required', 'string', 'in:' . implode(',', Fixture::leagues())],
            'home_team'  => ['sometimes', 'required', 'string', 'max:100'],
            'away_team'  => ['sometimes', 'required', 'string', 'max:100', 'different:home_team'],
            'kickoff_at' => ['sometimes', 'required', 'date'],
        ]);

        $fixture->update($validated);

        return response()->json(['message' => 'Fixture updated.', 'fixture' => $fixture]);
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

    // PATCH /admin/fixtures/{fixture}/postpone — manual equivalent of what
    // automatic sync does when the provider reports POSTPONED/SUSPENDED.
    // Markets stay intact (unlike cancel, which is meant to be final) so
    // the fixture can simply be edited to a new kickoff time and resume
    // once the real date is known.
    public function postpone(Fixture $fixture)
    {
        if (in_array($fixture->status, ['finished', 'cancelled'])) {
            return response()->json(['message' => 'This fixture has already been resolved or cancelled.'], 422);
        }
        $fixture->update(['status' => 'postponed']);
        return response()->json(['message' => 'Fixture marked as postponed.', 'fixture' => $fixture]);
    }

    // PATCH /admin/fixtures/{fixture}/reinstate — reverses a postponement
    // once a new kickoff time is confirmed. Pair with `update()` to set
    // the corrected kickoff_at first.
    public function reinstate(Fixture $fixture)
    {
        if ($fixture->status !== 'postponed') {
            return response()->json(['message' => 'Only a postponed fixture can be reinstated.'], 422);
        }
        $fixture->update(['status' => 'scheduled']);
        return response()->json(['message' => 'Fixture reinstated as scheduled.', 'fixture' => $fixture]);
    }

    // POST /admin/fixtures/bulk-publish  { ids: [1,2,3] }
    // The only bulk action offered — deliberately. Bulk-cancelling or
    // bulk-resolving fixtures each carry real consequences (settling
    // predictions, permanently closing a fixture) that deserve a
    // one-at-a-time decision; publishing a batch of already-reviewed
    // pending fixtures does not.
    public function bulkPublish(Request $request)
    {
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $fixtures = Fixture::whereIn('id', $validated['ids'])->where('is_published', false)->get();
        $fixtures->each(fn (Fixture $f) => $f->update(['is_published' => true]));

        return response()->json([
            'message'   => "{$fixtures->count()} fixture(s) published.",
            'published' => $fixtures->pluck('id'),
        ]);
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
