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
// PROVIDERS
// ─────────
// Crypto  → CoinPaprika free tier (https://api.coinpaprika.com/v1).
//           Genuinely keyless, allows commercial use, 20,000 calls/mo.
//           Verified against CoinPaprika's current published API
//           reference: the free plan DOES expose real historical ticks
//           at /tickers/{id}/historical — daily-spaced points
//           (24h/7d/30d/365d) for the last 1 year, and hourly-spaced
//           points for the last 1 day. That is what now powers the
//           price-history chart (a previous revision of this file
//           believed history was paid-tier-only and always returned an
//           empty array — that was incorrect and has been fixed here).
//
// Commodities / Indices (Gold, S&P 500, NASDAQ) → optional, via Twelve
//           Data (https://twelvedata.com), configured through
//           TWELVE_DATA_API_KEY. Twelve Data was picked because its
//           free "Basic" plan (no card required) covers indices and
//           commodities in the same /quote and /time_series endpoints
//           crypto exchanges don't provide. CoinPaprika/CoinGecko only
//           cover crypto — there is no keyless real provider for
//           SPX/NASDAQ/Gold, so until TWELVE_DATA_API_KEY is set these
//           assets are reported as "unavailable" (never a fabricated
//           price) with a status the frontend renders explicitly.
//
// Provider logic is isolated per-class-method here so a different
// provider can be swapped in later by editing only this file.

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MarketDataService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected int $cacheTtl;

    protected ?string $tdKey;
    protected string $tdBaseUrl;

    // Spotlight assets shown on the Market Overview cards + used to
    // seed the main chart's default selection. Crypto ids are
    // CoinPaprika coin ids; non-crypto entries are Twelve Data symbols.
    protected const CRYPTO_SPOTLIGHT = [
        'btc-bitcoin'   => 'Bitcoin',
        'eth-ethereum'  => 'Ethereum',
        'sol-solana'    => 'Solana',
    ];

    // Keyed by a URL-safe slug (used as the asset "id" everywhere the
    // frontend/routes see it) — the real provider symbol lives in
    // 'symbol'. Kept separate because Twelve Data's forex-style symbol
    // for gold ("XAU/USD") contains a slash, which would otherwise break
    // the /market/assets/{asset} route's single path segment.
    protected const NON_CRYPTO_SPOTLIGHT = [
        'gold'   => ['name' => 'Gold',    'symbol' => 'XAU/USD', 'category' => 'commodity'],
        'sp500'  => ['name' => 'S&P 500', 'symbol' => 'SPX',     'category' => 'index'],
        'nasdaq' => ['name' => 'NASDAQ',  'symbol' => 'IXIC',    'category' => 'index'],
    ];

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('services.market_data.base_url'), '/');
        $this->apiKey   = config('services.market_data.api_key');
        $this->cacheTtl = (int) config('services.market_data.cache_ttl', 60);

        $this->tdKey     = config('services.twelve_data.api_key');
        $this->tdBaseUrl = rtrim(config('services.twelve_data.base_url', 'https://api.twelvedata.com'), '/');
    }

    /**
     * Top assets by market-cap rank, with current price + 24h change.
     * $category: 'crypto' | 'commodity' | 'index' | null (= crypto, the
     * only category that always has real data; non-crypto is additive
     * and only returned when explicitly asked for or when configured).
     *
     * @return array{ok: bool, data: array, error: ?string, stale?: bool}
     */
    public function listAssets(int $limit = 50, ?string $search = null, ?string $category = null): array
    {
        if ($category === 'commodity' || $category === 'index') {
            return $this->listNonCrypto($category, $search);
        }

        $result = $this->cachedFetch('market_data:assets:all', function () {
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
     * Non-crypto assets (commodities/indices) via Twelve Data. Returns
     * ok=true with each row explicitly flagged 'available' => false when
     * no provider key is configured, rather than a hard error — the
     * crypto side of the page still works fine, and the frontend shows
     * a per-row "not connected yet" state instead of a fake price.
     */
    protected function listNonCrypto(?string $onlyCategory, ?string $search): array
    {
        $wanted = array_filter(self::NON_CRYPTO_SPOTLIGHT, function ($meta) use ($onlyCategory) {
            return !$onlyCategory || $meta['category'] === $onlyCategory;
        });

        if ($search) {
            $needle = strtolower($search);
            $wanted = array_filter($wanted, fn ($m) => str_contains(strtolower($m['name']), $needle) || str_contains(strtolower($m['symbol']), $needle));
        }

        if (!$this->tdKey) {
            return [
                'ok'   => true,
                'data' => array_values(array_map(fn ($m, $slug) => $this->unavailableRow($m, $slug), $wanted, array_keys($wanted))),
                'error' => null,
            ];
        }

        if (empty($wanted)) {
            return ['ok' => true, 'data' => [], 'error' => null];
        }

        $providerSymbols = array_map(fn ($m) => $m['symbol'], $wanted);
        $cacheKey = 'market_data:nc:' . md5(implode(',', $providerSymbols));

        return $this->cachedFetch($cacheKey, function () use ($providerSymbols, $wanted) {
            $response = Http::timeout(15)->get("{$this->tdBaseUrl}/quote", [
                'symbol'  => implode(',', $providerSymbols),
                'apikey'  => $this->tdKey,
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException("Twelve Data returned HTTP {$response->status()}");
            }

            $json = $response->json();
            // Twelve Data returns a single object (1 symbol) or a map
            // keyed by symbol (multiple symbols) — normalize both.
            $rows = isset($json['symbol']) ? [$json['symbol'] => $json] : $json;

            $out = [];
            foreach ($wanted as $slug => $meta) {
                $row = $rows[$meta['symbol']] ?? null;
                $out[] = $this->mapTwelveDataQuote($slug, $meta, $row);
            }
            return $out;
        });
    }

    protected function unavailableRow(array $meta, string $slug): array
    {
        return [
            'id'             => $slug,
            'symbol'         => $meta['symbol'],
            'name'           => $meta['name'],
            'category'       => $meta['category'],
            'image'          => null,
            'price_usd'      => null,
            'change_24h_pct' => null,
            'market_cap_usd' => null,
            'volume_24h_usd' => null,
            'available'      => false,
            'unavailable_reason' => 'Provider not configured — set TWELVE_DATA_API_KEY on the backend.',
        ];
    }

    protected function mapTwelveDataQuote(string $slug, array $meta, ?array $row): array
    {
        if (!$row || isset($row['code']) /* Twelve Data error shape */) {
            return $this->unavailableRow($meta, $slug) + ['unavailable_reason' => 'Provider returned no data for this symbol.'];
        }

        return [
            'id'             => $slug,
            'symbol'         => $meta['symbol'],
            'name'           => $meta['name'],
            'category'       => $meta['category'],
            'image'          => null,
            'price_usd'      => isset($row['close']) ? (float) $row['close'] : null,
            'change_24h_pct' => isset($row['percent_change']) ? (float) $row['percent_change'] : null,
            'market_cap_usd' => null,
            'volume_24h_usd' => isset($row['volume']) ? (float) $row['volume'] : null,
            'available'      => true,
        ];
    }

    /**
     * Spotlight row set for the Market Overview cards: a handful of
     * crypto assets (always real, via CoinPaprika) plus Gold/S&P
     * 500/NASDAQ (real when TWELVE_DATA_API_KEY is set, otherwise an
     * honest "unavailable" card — never a fabricated price). Each
     * crypto row also carries a short sparkline of real recent prices.
     *
     * @return array{ok: bool, data: array, error: ?string}
     */
    public function overview(): array
    {
        $cryptoIds = array_keys(self::CRYPTO_SPOTLIGHT);

        $all = $this->listAssets(500);
        if (!$all['ok']) {
            return $all;
        }

        $byId = [];
        foreach ($all['data'] as $row) {
            $byId[$row['id']] = $row;
        }

        $rows = [];
        foreach ($cryptoIds as $id) {
            if (!isset($byId[$id])) continue;
            $row = $byId[$id];
            $row['category'] = 'crypto';
            $row['available'] = true;

            $history = $this->historicalTicks($id, '24h');
            $row['sparkline'] = $history['ok']
                ? array_map(fn ($p) => $p['price'], $history['data'])
                : [];

            $rows[] = $row;
        }

        $nonCrypto = $this->listNonCrypto(null, null);
        if ($nonCrypto['ok']) {
            foreach ($nonCrypto['data'] as $row) {
                $row['sparkline'] = [];
                $rows[] = $row;
            }
        }

        return ['ok' => true, 'data' => $rows, 'error' => null, 'stale' => $all['stale'] ?? false];
    }

    /**
     * Single asset detail. price_history is now real (see class header
     * comment) rather than always-empty.
     *
     * @return array{ok: bool, data: ?array, error: ?string}
     */
    public function assetDetail(string $assetId, string $interval = '24h'): array
    {
        // Non-crypto (Twelve Data) symbol requested — different code path.
        if (isset(self::NON_CRYPTO_SPOTLIGHT[$assetId])) {
            return $this->nonCryptoDetail($assetId, $interval);
        }

        $cacheKey = "market_data:asset:{$assetId}";

        $result = $this->cachedFetch($cacheKey, function () use ($assetId) {
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

        if (!$result['ok'] || !$result['data']) {
            return $result;
        }

        $history = $this->historicalTicks($assetId, $interval);
        $result['data']['price_history'] = $history['ok'] ? $history['data'] : [];
        $result['data']['history_unavailable'] = !$history['ok'];

        return $result;
    }

    protected function nonCryptoDetail(string $slug, string $interval): array
    {
        $meta = self::NON_CRYPTO_SPOTLIGHT[$slug];
        $quote = $this->listNonCrypto($meta['category'], null);

        if (!$quote['ok']) {
            return $quote;
        }

        $row = collect($quote['data'])->firstWhere('id', $slug);
        if (!$row) {
            return ['ok' => false, 'data' => null, 'error' => 'Asset not found.', 'status' => 404];
        }

        $row['market_cap_rank'] = null;
        $row['high_24h_usd']    = null;
        $row['low_24h_usd']     = null;

        if (($row['available'] ?? false) && $this->tdKey) {
            $history = $this->twelveDataHistory($meta['symbol'], $interval);
            $row['price_history'] = $history['ok'] ? $history['data'] : [];
            $row['history_unavailable'] = !$history['ok'];
        } else {
            $row['price_history'] = [];
            $row['history_unavailable'] = true;
        }

        return ['ok' => true, 'data' => $row, 'error' => null];
    }

    /**
     * Real historical points for a CoinPaprika coin, mapped from the
     * requested chart range to what the free tier actually supports:
     *   24h -> hourly-spaced points, last 1 day (free-tier hourly limit)
     *   7d  -> daily-spaced points,  last 7 days
     *   30d -> daily-spaced points,  last 30 days
     *   1y  -> weekly-spaced points, last 365 days (kept light-weight)
     *
     * @return array{ok: bool, data: array}
     */
    public function historicalTicks(string $coinId, string $range): array
    {
        [$interval, $days] = match ($range) {
            '7d'  => ['24h', 7],
            '30d' => ['24h', 30],
            '1y'  => ['7d', 365],
            default => ['1h', 1], // '24h' / '1h' request values both mean "today"
        };

        $cacheKey = "market_data:history:{$coinId}:{$range}";

        $result = $this->cachedFetch($cacheKey, function () use ($coinId, $interval, $days) {
            $start = now()->subDays($days)->toIso8601String();

            $response = Http::withHeaders($this->buildHeaders())->timeout(15)
                ->get("{$this->baseUrl}/tickers/{$coinId}/historical", [
                    'start'    => $start,
                    'interval' => $interval,
                    'limit'    => 366,
                    'quote'    => 'usd',
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException("Provider returned HTTP {$response->status()} for history");
            }

            $raw = $response->json();
            if (!is_array($raw)) return [];

            return array_values(array_map(fn ($p) => [
                'time'  => $p['timestamp'] ?? null,
                'price' => $p['price'] ?? null,
            ], $raw));
        }, ttlOverride: 300); // history moves slower than the live ticker — cache 5 min

        return $result['ok'] ? $result : ['ok' => false, 'data' => []];
    }

    protected function twelveDataHistory(string $symbol, string $range): array
    {
        [$interval, $outputsize] = match ($range) {
            '7d'  => ['1day', 7],
            '30d' => ['1day', 30],
            '1y'  => ['1week', 52],
            default => ['1h', 24],
        };

        $cacheKey = "market_data:tdhistory:{$symbol}:{$range}";

        return $this->cachedFetch($cacheKey, function () use ($symbol, $interval, $outputsize) {
            $response = Http::timeout(15)->get("{$this->tdBaseUrl}/time_series", [
                'symbol'     => $symbol,
                'interval'   => $interval,
                'outputsize' => $outputsize,
                'apikey'     => $this->tdKey,
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException("Twelve Data returned HTTP {$response->status()} for history");
            }

            $json = $response->json();
            $values = $json['values'] ?? [];

            return array_reverse(array_values(array_map(fn ($p) => [
                'time'  => $p['datetime'] ?? null,
                'price' => isset($p['close']) ? (float) $p['close'] : null,
            ], $values)));
        }, ttlOverride: 300);
    }

    /**
     * Wraps a provider call with caching + uniform error handling, so
     * every endpoint above returns the same {ok, data, error} shape
     * regardless of what actually went wrong upstream (timeout, rate
     * limit, malformed response, asset not found, etc). The controller
     * turns this into loading/error/empty states — it never sees a raw
     * exception or raw provider payload.
     */
    protected function cachedFetch(string $cacheKey, callable $fetch, ?int $ttlOverride = null): array
    {
        try {
            $ttl = $ttlOverride ?? $this->cacheTtl;
            $data = Cache::remember($cacheKey, $ttl, function () use ($fetch) {
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
            'category'       => 'crypto',
            'image'          => null, // CoinPaprika's ticker endpoint doesn't include a logo URL
            'price_usd'      => $usd['price'] ?? null,
            'change_24h_pct' => $usd['percent_change_24h'] ?? null,
            'market_cap_usd' => $usd['market_cap'] ?? null,
            'volume_24h_usd' => $usd['volume_24h'] ?? null,
            'available'      => true,
        ];

        if ($includeHistory) {
            $mapped['high_24h_usd']    = null; // not provided by the free tier
            $mapped['low_24h_usd']     = null;
            $mapped['market_cap_rank'] = $c['rank'] ?? null;
            $mapped['price_history']   = []; // filled in by assetDetail() after this map
        }

        return $mapped;
    }
}
