<?php
// LOCATION: app/Services/MarketDataService.php
//
// Phase 3 — Live Trading / Market Interface.
//
// IMPORTANT DISTINCTION (per project requirements): this service, and
// everything downstream of it, provides MARKET INFORMATION only — asset
// prices, price changes, market status. It does NOT execute trades and
// never touches a user's balance/wallet.
//
// PROVIDER FIX (this revision): originally built against CoinGecko's
// fully keyless public tier. That tier is real but "heavily
// rate-limited" per CoinGecko's own current guidance, and in practice
// requests from a shared server IP were being throttled — which is why
// the market page showed nothing. Switched to CoinPaprika's free plan
// (https://api.coinpaprika.com/v1), which is genuinely keyless, allows
// commercial use, and has a real monthly quota (20,000 calls) rather
// than an unpublished/aggressive keyless throttle. Verified against
// CoinPaprika's current published API reference before switching — not
// guessed.
//
// Trade-off, stated plainly rather than hidden: CoinPaprika's free tier
// does not reliably include historical OHLCV data (that's a paid-tier
// feature per their own docs), so the price-history chart on the asset
// detail view is not populated by this service. Rather than fabricate
// history or silently guess at a paid endpoint, assetDetail() returns an
// empty price_history — the frontend already has a graceful "not enough
// history to chart yet" empty state for that, per the no-fabrication
// requirement. Real-time price/change/market-cap data — the core of
// "market information" — works fully.
//
// Provider is still swappable: everything CoinPaprika-specific lives in
// this one class (buildHeaders/mapAssetList/mapAssetDetail) — if a paid
// plan or different provider is configured later via config/services.php,
// only this file needs to change.

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MarketDataService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected int $cacheTtl;

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('services.market_data.base_url'), '/');
        $this->apiKey   = config('services.market_data.api_key');
        $this->cacheTtl = (int) config('services.market_data.cache_ttl', 60);
    }

    /**
     * Top assets by market-cap rank, with current price + 24h change.
     * CoinPaprika's /tickers already returns coins sorted by rank, so
     * $limit is just a slice — no extra query params needed on the
     * free tier (it doesn't support server-side pagination).
     *
     * @return array{ok: bool, data: array, error: ?string}
     */
    public function listAssets(int $limit = 50, ?string $search = null): array
    {
        $cacheKey = "market_data:assets:all";

        $result = $this->cachedFetch($cacheKey, function () {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(15)
                ->get("{$this->baseUrl}/tickers");

            if (!$response->successful()) {
                throw new \RuntimeException("Provider returned HTTP {$response->status()}");
            }

            return $this->mapAssetList($response->json());
        });

        if (!$result['ok']) {
            return $result;
        }

        $assets = $result['data'];

        if ($search) {
            $needle = strtolower($search);
            $assets = array_values(array_filter($assets, function ($a) use ($needle) {
                return str_contains(strtolower($a['name']), $needle)
                    || str_contains(strtolower($a['symbol']), $needle);
            }));
        }

        $result['data'] = array_slice($assets, 0, $limit);
        return $result;
    }

    /**
     * Single asset detail. price_history is intentionally empty — see
     * this file's header comment on the CoinPaprika free-tier trade-off.
     *
     * @return array{ok: bool, data: ?array, error: ?string}
     */
    public function assetDetail(string $assetId, string $interval = '24h'): array
    {
        $cacheKey = "market_data:asset:{$assetId}";

        return $this->cachedFetch($cacheKey, function () use ($assetId) {
            $response = Http::withHeaders($this->buildHeaders())->timeout(15)
                ->get("{$this->baseUrl}/tickers/{$assetId}");

            if ($response->status() === 404) {
                throw new \DomainException('Asset not found.');
            }
            if (!$response->successful()) {
                throw new \RuntimeException("Provider returned HTTP {$response->status()}");
            }

            return $this->mapAssetDetail($response->json());
        });
    }

    /**
     * Wraps a provider call with caching + uniform error handling, so
     * every endpoint above returns the same {ok, data, error} shape
     * regardless of what actually went wrong upstream (timeout, rate
     * limit, malformed response, asset not found, etc). The controller
     * turns this into loading/error/empty states — it never sees a raw
     * exception or raw provider payload.
     */
    protected function cachedFetch(string $cacheKey, callable $fetch): array
    {
        try {
            $data = Cache::remember($cacheKey, $this->cacheTtl, function () use ($fetch) {
                return $fetch();
            });

            // Keep a much longer-lived copy purely as a fallback for when
            // the provider is down and the short-TTL cache above has
            // already expired — separate key so it doesn't affect normal
            // freshness.
            Cache::put($cacheKey . ':stale', $data, now()->addHours(6));

            return ['ok' => true, 'data' => $data, 'error' => null];
        } catch (\DomainException $e) {
            // Not found — don't cache, don't log as an error (expected case)
            return ['ok' => false, 'data' => null, 'error' => $e->getMessage(), 'status' => 404];
        } catch (\Throwable $e) {
            Log::warning('MarketDataService fetch failed', [
                'cache_key' => $cacheKey,
                'error'     => $e->getMessage(),
            ]);

            // Serve a stale cached value if we have one rather than a hard
            // failure — market data a few minutes old is still useful;
            // an empty/broken page is not.
            $stale = Cache::get($cacheKey . ':stale');
            if ($stale !== null) {
                return ['ok' => true, 'data' => $stale, 'error' => null, 'stale' => true];
            }

            return ['ok' => false, 'data' => null, 'error' => 'Market data is temporarily unavailable.', 'status' => 503];
        }
    }

    protected function buildHeaders(): array
    {
        // CoinPaprika's free tier needs no auth header at all. If a paid
        // plan is configured later via MARKET_DATA_API_KEY, it goes in
        // the Authorization header as the raw key value — CoinPaprika
        // does NOT use a "Bearer " prefix (their docs are explicit about
        // this being a common integration mistake).
        return $this->apiKey ? ['Authorization' => $this->apiKey] : [];
    }

    protected function mapAssetList(?array $raw): array
    {
        if (!is_array($raw)) return [];

        return array_values(array_filter(array_map(fn ($c) => $this->mapTicker($c), $raw)));
    }

    protected function mapAssetDetail(?array $raw): ?array
    {
        return $this->mapTicker($raw, includeHistory: true);
    }

    protected function mapTicker(?array $c, bool $includeHistory = false): ?array
    {
        if (!is_array($c) || !isset($c['id'])) return null;

        $usd = $c['quotes']['USD'] ?? [];

        $mapped = [
            'id'             => $c['id'],
            'symbol'         => strtoupper($c['symbol'] ?? ''),
            'name'           => $c['name'] ?? '',
            'image'          => null, // CoinPaprika's ticker endpoint doesn't include a logo URL
            'price_usd'      => $usd['price'] ?? null,
            'change_24h_pct' => $usd['percent_change_24h'] ?? null,
            'market_cap_usd' => $usd['market_cap'] ?? null,
            'volume_24h_usd' => $usd['volume_24h'] ?? null,
        ];

        if ($includeHistory) {
            $mapped['high_24h_usd']    = null; // not provided by the free tier
            $mapped['low_24h_usd']     = null;
            $mapped['market_cap_rank'] = $c['rank'] ?? null;
            // Intentionally empty — see this file's header comment.
            // Never fabricated; the frontend shows an honest empty state.
            $mapped['price_history']  = [];
        }

        return $mapped;
    }
}

