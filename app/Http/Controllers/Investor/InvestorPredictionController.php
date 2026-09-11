<?php
// LOCATION: app/Http/Controllers/Investor/InvestorPredictionController.php
//
// Phase 4 — investor side. Points only, never balance/wallet. See
// AdminPredictionController's header comment for full scope notes.

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\PredictionRound;
use App\Models\PredictionEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvestorPredictionController extends Controller
{
    // GET /predictions — open rounds (with the investor's own entry, if any)
    // + their recently resolved rounds.
    public function index(Request $request)
    {
        $userId = Auth::id();

        // Lazily close any rounds whose window has passed, same
        // enforce-on-read pattern the Task system uses.
        PredictionRound::where('status', 'open')
            ->where('closes_at', '<=', now())
            ->get()
            ->each->syncClose();

        $open = PredictionRound::where('status', 'open')
            ->with(['entries' => fn ($q) => $q->where('user_id', $userId)])
            ->orderBy('closes_at')
            ->get();

        $history = PredictionRound::whereIn('status', ['closed', 'resolved'])
            ->whereHas('entries', fn ($q) => $q->where('user_id', $userId))
            ->with(['entries' => fn ($q) => $q->where('user_id', $userId)])
            ->orderByDesc('resolved_at')
            ->limit(20)
            ->get();

        $pointsTotal = PredictionEntry::where('user_id', $userId)
            ->whereNotNull('points_awarded')
            ->sum('points_awarded');

        return response()->json([
            'open'         => $open,
            'history'      => $history,
            'points_total' => (int) $pointsTotal,
            'meta'         => ['label' => 'Prediction activity — points only, no real-money wagering.'],
        ]);
    }

    // POST /predictions/{round}/enter  { choice: "up"|"down" }
    public function enter(Request $request, PredictionRound $round)
    {
        $validated = $request->validate([
            'choice' => ['required', 'in:up,down'],
        ]);

        $round->syncClose();

        if (!$round->is_open_for_entry) {
            return response()->json(['message' => 'This round is no longer accepting entries.'], 422);
        }

        $existing = PredictionEntry::where('prediction_round_id', $round->id)
            ->where('user_id', Auth::id())
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You already entered this round.'], 422);
        }

        try {
            $entry = PredictionEntry::create([
                'prediction_round_id' => $round->id,
                'user_id'             => Auth::id(),
                'choice'               => $validated['choice'],
                'points_staked'        => $round->points_stake,
                'submitted_at'         => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique (round, user) constraint — belt-and-braces against
            // the race between the check above and this insert.
            return response()->json(['message' => 'You already entered this round.'], 422);
        }

        return response()->json(['message' => 'Prediction submitted.', 'entry' => $entry], 201);
    }
}