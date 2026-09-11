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
}
