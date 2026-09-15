<?php
// LOCATION: app/Http/Controllers/Admin/AdminCompetitionController.php
//
// Gaming & Prediction — admin league selection. Lets the admin view the
// competitions football-data.org's free tier supports and enable/
// disable which ones FootballDataService::fetchUpcomingFixtures() will
// actually pull. Does not itself fetch fixtures or touch football-
// data.org — see AdminFixtureController::fetchFromApi() for that, which
// now reads Competition::enabled() instead of a hardcoded list.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\FootballDataService;
use Illuminate\Http\Request;

class AdminCompetitionController extends Controller
{
    // GET /admin/competitions
    public function index(FootballDataService $footballData)
    {
        return response()->json([
            'competitions' => Competition::orderBy('sort_order')->get(),
            'football_data_configured' => $footballData->isConfigured(),
        ]);
    }

    // PATCH /admin/competitions/{competition}/toggle
    // Flips a single competition's enabled state. Never touches
    // fixtures already imported — disabling a competition only stops
    // future fetches from including it.
    public function toggle(Request $request, Competition $competition)
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);

        $competition->update(['is_enabled' => $validated['is_enabled']]);

        return response()->json([
            'message'     => $validated['is_enabled']
                ? "{$competition->name} enabled — included in the next fixture fetch."
                : "{$competition->name} disabled — excluded from future fetches. Existing imported fixtures are unaffected.",
            'competition' => $competition,
        ]);
    }

    // POST /admin/competitions/sync-provider
    // Refreshes the competition catalog from football-data.org's own
    // /v4/competitions list rather than relying solely on the hardcoded
    // CATALOG seed — see FootballDataService::syncCompetitionsFromProvider().
    // Never disables/removes an existing competition; only adds ones the
    // provider newly exposes (disabled by default) or refreshes a name.
    public function syncFromProvider(FootballDataService $footballData)
    {
        $result = $footballData->syncCompetitionsFromProvider();

        if (!$result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'message'      => "Competition catalog synced — {$result['added']} added, {$result['updated']} updated.",
            'added'        => $result['added'],
            'updated'      => $result['updated'],
            'competitions' => Competition::orderBy('sort_order')->get(),
        ]);
    }
}
