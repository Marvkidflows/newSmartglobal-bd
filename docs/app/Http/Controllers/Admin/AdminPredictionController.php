<?php
// LOCATION: app/Http/Controllers/Admin/AdminPredictionController.php
//
// Phase 4 — Gaming & Prediction Functionality.
//
// SCOPE OF WHAT THIS IS: a non-wagering, points-based prediction activity.
// Investors spend no real money and receive no real money — "points"
// tracked here never touch the wallet/balance system anywhere in this
// controller or PredictionEntry/PredictionRound. This was built as the
// safe default because no gaming/wagering provider, real-money business
// rules, or legal/compliance sign-off were supplied.
//
// IF THE ACTUAL REQUIREMENT IS REAL-MONEY WAGERING: do not repurpose this
// module by wiring points_stake/points_payout to the wallet. That would
// require, at minimum: a licensed gaming/wagering provider or explicit
// legal approval for in-house wagering, KYC/AML rules specific to
// wagering (separate from investment KYC), responsible-gambling controls,
// and jurisdictional restrictions. None of that exists in this codebase.
// Flag this to the technical team explicitly rather than silently
// expanding this module's scope.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PredictionRound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminPredictionController extends Controller
{
    // GET /admin/predictions
    public function index(Request $request)
    {
        $query = PredictionRound::withCount('entries')->with('creator:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $rounds = $query->orderByDesc('created_at')->paginate(20);

        return response()->json($rounds);
    }

    // POST /admin/predictions
    public function store(Request $request)
    {
        $validated = $request->validate([
            'asset_symbol'     => ['required', 'string', 'max:20'],
            'question'         => ['required', 'string', 'max:255'],
            'opens_at'         => ['nullable', 'date'],
            'closes_at'        => ['required', 'date', 'after:now'],
            'resolves_at'      => ['nullable', 'date', 'after_or_equal:closes_at'],
            'reference_price'  => ['nullable', 'numeric', 'min:0'],
            'points_stake'     => ['required', 'integer', 'min:1', 'max:10000'],
            'points_payout'    => ['required', 'integer', 'min:1', 'max:20000'],
        ]);

        $round = PredictionRound::create([
            ...$validated,
            'status'     => 'open',
            'opens_at'   => $validated['opens_at'] ?? now(),
            'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Prediction round created.', 'round' => $round], 201);
    }

    // GET /admin/predictions/{round}
    public function show(PredictionRound $round)
    {
        $round->load(['entries.user:id,name,email', 'creator:id,name', 'resolver:id,name']);
        return response()->json(['round' => $round]);
    }

    // PATCH /admin/predictions/{round}/close
    public function close(PredictionRound $round)
    {
        if ($round->status !== 'open') {
            return response()->json(['message' => 'Only an open round can be closed.'], 422);
        }
        $round->update(['status' => 'closed']);
        return response()->json(['message' => 'Round closed to new entries.', 'round' => $round]);
    }

    // PATCH /admin/predictions/{round}/cancel
    public function cancel(PredictionRound $round)
    {
        if (in_array($round->status, ['resolved', 'cancelled'])) {
            return response()->json(['message' => 'This round can no longer be cancelled.'], 422);
        }
        $round->update(['status' => 'cancelled']);
        return response()->json(['message' => 'Round cancelled. No points were awarded or deducted.', 'round' => $round]);
    }

    /**
     * PATCH /admin/predictions/{round}/resolve
     * Admin records the real outcome (e.g. from the Market Information
     * module or any other source they trust) and every entry is settled
     * in one transaction. This is the ONLY place points_awarded is ever
     * set — never fabricated, never defaulted to a "win" state.
     */
    public function resolve(Request $request, PredictionRound $round)
    {
        if (!in_array($round->status, ['open', 'closed'])) {
            return response()->json(['message' => 'This round has already been resolved or cancelled.'], 422);
        }

        $validated = $request->validate([
            'outcome'          => ['required', 'in:up,down,flat'],
            'resolution_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($round, $validated) {
            $round->entries()->chunkById(200, function ($entries) use ($round, $validated) {
                foreach ($entries as $entry) {
                    $isCorrect = $entry->choice === $validated['outcome'];
                    $entry->update([
                        'is_correct'     => $isCorrect,
                        'points_awarded' => $isCorrect ? $round->points_payout : 0,
                    ]);
                }
            });

            $round->update([
                'status'            => 'resolved',
                'outcome'           => $validated['outcome'],
                'resolution_price'  => $validated['resolution_price'] ?? null,
                'resolved_by'       => Auth::id(),
                'resolved_at'       => Carbon::now(),
            ]);
        });

        return response()->json(['message' => 'Round resolved and entries settled.', 'round' => $round->fresh('entries')]);
    }
}
