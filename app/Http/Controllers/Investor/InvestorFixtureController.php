<?php
// LOCATION: app/Http/Controllers/Investor/InvestorFixtureController.php
//
// Gaming & Prediction — investor side. Points only, never wallet/balance.
// See AdminFixtureController's header comment for full scope notes.

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Fixture;
use App\Models\FixtureMarket;
use App\Models\FixturePrediction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvestorFixtureController extends Controller
{
    // GET /investor-investment/fixtures
    public function index()
    {
        $userId = Auth::id();

        // Lazily close markets whose fixture has already kicked off — same
        // enforce-on-read pattern used elsewhere (Tasks, crypto Predictions).
        FixtureMarket::where('status', 'open')
            ->whereHas('fixture', fn ($q) => $q->where('kickoff_at', '<=', now())->where('status', 'scheduled'))
            ->update(['status' => 'closed']);

        $upcoming = Fixture::published()
            ->where('status', 'scheduled')
            ->where('kickoff_at', '>', now())
            ->with(['markets' => fn ($q) => $q->with(['predictions' => fn ($p) => $p->where('user_id', $userId)])])
            ->orderBy('kickoff_at')
            ->get();

        $history = Fixture::published()
            ->where('status', 'finished')
            ->whereHas('markets.predictions', fn ($q) => $q->where('user_id', $userId))
            ->with(['markets' => fn ($q) => $q->with(['predictions' => fn ($p) => $p->where('user_id', $userId)])])
            ->orderByDesc('resolved_at')
            ->limit(20)
            ->get();

        $pointsTotal = FixturePrediction::where('user_id', $userId)
            ->whereNotNull('points_awarded')
            ->sum('points_awarded');

        return response()->json([
            'upcoming'     => $upcoming,
            'history'      => $history,
            'points_total' => (int) $pointsTotal,
            'meta'         => ['label' => 'Gaming & Prediction — points only, no investment required.'],
        ]);
    }

    // POST /investor-investment/fixtures/markets/{market}/predict  { selection: "..." }
    public function predict(Request $request, FixtureMarket $market)
    {
        $allowed = FixtureMarket::SELECTIONS[$market->market_type] ?? [];

        $validated = $request->validate([
            'selection' => ['required', 'in:' . implode(',', $allowed)],
        ]);

        $market->loadMissing('fixture');

        if (!$market->fixture->is_published || $market->status !== 'open' || $market->fixture->status !== 'scheduled' || $market->fixture->kickoff_at->isPast()) {
            return response()->json(['message' => 'This market is no longer accepting predictions.'], 422);
        }

        try {
            $prediction = FixturePrediction::create([
                'fixture_market_id' => $market->id,
                'user_id'           => Auth::id(),
                'selection'         => $validated['selection'],
                'submitted_at'      => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message' => 'You already made a prediction on this market.'], 422);
        }

        return response()->json(['message' => 'Prediction submitted.', 'prediction' => $prediction], 201);
    }
}
