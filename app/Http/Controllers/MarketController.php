<?php
// LOCATION: app/Http/Controllers/MarketController.php
//
// Phase 3 — Live Trading / Market Interface.
// MARKET INFORMATION ONLY. No endpoint here executes a trade, moves a
// balance, or creates any financial record. See MarketDataService for the
// provider integration and config/services.php for provider config.
//
// Public (unauthenticated) on purpose — this is read-only market
// information, shown on both the public landing page and the investor
// area per the approved requirement, and carries no user-specific data.

namespace App\Http\Controllers;

use App\Services\MarketDataService;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    protected MarketDataService $market;

    public function __construct(MarketDataService $market)
    {
        $this->market = $market;
    }

    // GET /api/market/assets?search=&limit=
    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:50'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $this->market->listAssets(
            (int) $request->input('limit', 50),
            $request->input('search')
        );

        if (!$result['ok']) {
            return response()->json([
                'message' => $result['error'],
                'assets'  => [],
            ], $result['status'] ?? 503);
        }

        return response()->json([
            'assets' => $result['data'],
            'count'  => count($result['data']),
            'stale'  => $result['stale'] ?? false,
            'meta'   => [
                'label' => 'Market information — not a trading execution venue.',
            ],
        ]);
    }

    // GET /api/market/assets/{asset}?interval=24h|7d|30d|1y
    public function show(Request $request, string $asset)
    {
        $request->validate([
            'interval' => ['nullable', 'in:1h,24h,7d,30d,1y'],
        ]);

        $result = $this->market->assetDetail($asset, $request->input('interval', '24h'));

        if (!$result['ok']) {
            return response()->json(['message' => $result['error']], $result['status'] ?? 503);
        }

        if (!$result['data']) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        return response()->json(['asset' => $result['data'], 'stale' => $result['stale'] ?? false]);
    }
}
