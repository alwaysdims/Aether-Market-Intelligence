<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class IdxEdgeClient
{
    /** @return array<string, mixed> */
    public function price(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));
        if ($ticker === '^JKSE' || $ticker === 'JKSE') {
            $ticker = 'IHSG';
        }
        if (! preg_match('/^[A-Z]{4}$/', $ticker)) {
            return $this->failure('INVALID_TICKER', 'Kode saham harus terdiri dari 4 huruf.');
        }

        if (! (bool) config('services.idx_edge.enabled', false) && ! $this->isEndpointAllowed('price')) {
            return $this->failure('PROVIDER_DISABLED', 'IDX Edge dinonaktifkan.');
        }

        if (! $this->isEndpointAllowed('price')) {
            return $this->failure('ENDPOINT_QUARANTINED', 'Endpoint quote IDX Edge dikarantina karena anomali data Pasar Negosiasi (NG).');
        }

        return $this->get("/api/price/{$ticker}", [], "price:{$ticker}", function (array $data): array {
            if (! isset($data['code'], $data['last_price'])
                || ! is_string($data['code'])
                || ! is_numeric($data['last_price'])
                || (float) $data['last_price'] <= 0) {
                throw new \UnexpectedValueException('Skema harga IDX Edge tidak valid.');
            }

            $sourceDate = isset($data['source_date']) && is_string($data['source_date']) && $data['source_date'] !== ''
                ? $data['source_date']
                : now('Asia/Jakarta')->toDateString();

            $isReferencePrice = ! empty($data['no_trade_today']) || (isset($data['freq']) && (int) $data['freq'] === 0);

            return [
                'code' => strtoupper($data['code']),
                'last_price' => (float) $data['last_price'],
                'source_date' => $sourceDate,
                'data_ts' => isset($data['data_ts']) && is_string($data['data_ts']) ? $data['data_ts'] : null,
                'served_ts' => isset($data['served_ts']) && is_string($data['served_ts']) ? $data['served_ts'] : null,
                'market_state' => isset($data['market_state']) && is_string($data['market_state']) ? $data['market_state'] : null,
                'market_label' => isset($data['market_label']) && is_string($data['market_label']) ? $data['market_label'] : null,
                'no_trade_today' => ! empty($data['no_trade_today']),
                'is_reference_price_only' => $isReferencePrice,
                'trade_occurred_today' => ! $isReferencePrice,
                'freq' => isset($data['freq']) && is_numeric($data['freq']) ? (int) $data['freq'] : 0,
                'lot' => isset($data['lot']) && is_numeric($data['lot']) ? (float) $data['lot'] : 0.0,
                'value' => isset($data['value']) && is_numeric($data['value']) ? (float) $data['value'] : 0.0,
                'vwap' => isset($data['vwap']) && is_numeric($data['vwap']) ? (float) $data['vwap'] : null,
                'change' => isset($data['change']) && is_numeric($data['change']) ? (float) $data['change'] : 0.0,
                'change_pct' => isset($data['change_pct']) && is_numeric($data['change_pct']) ? (float) $data['change_pct'] : 0.0,
                'quote_age_ms' => isset($data['quote_age_ms']) && is_numeric($data['quote_age_ms']) ? (int) $data['quote_age_ms'] : null,
            ];
        });
    }

    /**
     * Read latest prices for many instruments in one provider request.
     *
     * @param  array<int, string>  $tickers
     * @return array<string, mixed>
     */
    public function priceList(array $tickers): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $ticker): string => strtoupper(trim((string) $ticker)),
            $tickers
        ), static fn (string $ticker): bool => preg_match('/^[A-Z]{4,5}$/', $ticker) === 1)));
        if ($codes === []) {
            return $this->failure('INVALID_TICKER_LIST', 'Daftar kode saham kosong.');
        }
        if (count($codes) > 1000) {
            return $this->failure('TOO_MANY_TICKERS', 'Maksimum 1000 kode per request.');
        }
        if (! (bool) config('services.idx_edge.enabled', false) && ! $this->isEndpointAllowed('price')) {
            return $this->failure('PROVIDER_DISABLED', 'IDX Edge dinonaktifkan.');
        }

        sort($codes);

        return $this->get(
            '/api/price/list',
            ['codes' => implode(',', $codes)],
            'price-list:'.implode(',', $codes),
            function (array $data): array {
                $stocks = is_array($data['stocks'] ?? null) ? $data['stocks'] : [];
                $normalized = [];
                foreach ($stocks as $code => $item) {
                    if (! is_array($item) || ! is_numeric($item['last_price'] ?? null)) {
                        continue;
                    }
                    $normalized[strtoupper((string) ($item['code'] ?? $code))] = [
                        'code' => strtoupper((string) ($item['code'] ?? $code)),
                        'last_price' => (float) $item['last_price'],
                        'change' => isset($item['change']) && is_numeric($item['change']) ? (float) $item['change'] : null,
                        'change_pct' => isset($item['change_pct']) && is_numeric($item['change_pct']) ? (float) $item['change_pct'] : null,
                        'source_date' => isset($item['source_date']) ? (string) $item['source_date'] : null,
                        'data_ts' => isset($item['data_ts']) ? (string) $item['data_ts'] : null,
                        'quote_age_ms' => isset($item['quote_age_ms']) && is_numeric($item['quote_age_ms']) ? (int) $item['quote_age_ms'] : null,
                        'freq' => isset($item['freq']) && is_numeric($item['freq']) ? (int) $item['freq'] : null,
                        'lot' => isset($item['lot']) && is_numeric($item['lot']) ? (float) $item['lot'] : null,
                        'value' => isset($item['value']) && is_numeric($item['value']) ? (float) $item['value'] : null,
                        'vwap' => isset($item['vwap']) && is_numeric($item['vwap']) ? (float) $item['vwap'] : null,
                    ];
                }

                return [
                    'count' => count($normalized),
                    'stocks' => $normalized,
                    'market' => is_array($data['market'] ?? null) ? $data['market'] : [],
                ];
            },
            30
        );
    }

    /** @return array<string, mixed> */
    public function priceIhsg(): array
    {
        if (! $this->isEndpointAllowed('price_ihsg')) {
            return $this->failure('ENDPOINT_DISABLED', 'Endpoint harga IHSG IDX Edge belum diaktifkan.');
        }

        return $this->get(
            '/api/price/IHSG',
            [],
            'price:IHSG',
            fn (array $data): array => app(IdxEdgeCapabilityParser::class)->parsePriceIhsg($data),
            10
        );
    }

    /** @return array<string, mixed> */
    public function chart(string $ticker, int $interval = 60, int $limit = 2000): array
    {
        $ticker = strtoupper(trim($ticker));
        if (! preg_match('/^[A-Z0-9^.-]{1,10}$/', $ticker)) {
            return $this->failure('INVALID_TICKER', 'Kode instrumen tidak valid.');
        }
        if (! in_array($interval, [60], true)) {
            return $this->failure('INVALID_INTERVAL', 'Chart hanya mendukung interval 60 detik.');
        }
        $limit = min(2000, max(1, $limit));
        $endpoint = $ticker === 'IHSG' ? 'chart_ihsg' : 'chart';
        if (! $this->isEndpointAllowed($endpoint)) {
            return $this->failure('ENDPOINT_DISABLED', 'Endpoint chart belum diaktifkan.');
        }

        return $this->get(
            "/api/chart/{$ticker}",
            ['interval' => $interval, 'limit' => $limit],
            "chart:{$ticker}:{$interval}:{$limit}",
            function (array $data): array {
                $parsed = app(IdxEdgeCapabilityParser::class)->parseChart($data);
                $parsed['session_status'] = $parsed['as_of_date'] === now('Asia/Jakarta')->toDateString()
                    ? 'CURRENT_SESSION_OR_PROVIDER_DATE'
                    : 'LAST_AVAILABLE_SESSION';

                return $parsed;
            },
            5
        );
    }

    /** @return array<string, mixed> */
    public function chartIhsg(): array
    {
        return $this->chart('IHSG', 60, 2000);
    }

    /** @return array<string, mixed> */
    public function probeCapabilityEndpoint(string $endpointKey, string $ticker = 'BBCA'): array
    {
        $ticker = strtoupper(trim($ticker));
        $definitions = [
            'price_ihsg' => ['/api/price/IHSG', [], 'probe:price:IHSG'],
            'chart' => ["/api/chart/{$ticker}", ['interval' => 300], "probe:chart:{$ticker}:300"],
            'screener' => ['/api/screener/latest', [], 'probe:screener:latest'],
            'movers' => ['/api/movers', ['by' => 'chg', 'n' => 10], 'probe:movers:chg:10'],
            'financial_statements' => ["/api/financial-statements/{$ticker}", [
                'report_type' => 'INCOME_STATEMENT',
                'period' => 'quarterly',
                'limit' => 12,
            ], "probe:financial-statements:{$ticker}:INCOME_STATEMENT:quarterly:12"],
            'insiders' => ["/api/insiders/{$ticker}", [], "probe:insiders:{$ticker}"],
            'broker_accumulation' => ["/api/broker-accumulation/{$ticker}", [], "probe:broker-accumulation:{$ticker}"],
        ];
        if (! isset($definitions[$endpointKey])) {
            return $this->failure('INVALID_ENDPOINT', 'Endpoint capability probe tidak dikenal.');
        }

        [$path, $query, $cacheSuffix] = $definitions[$endpointKey];

        return $this->executeHttpRequest(
            'idx-edge:probe:'.hash('sha256', $cacheSuffix.'?'.http_build_query($query)),
            $path,
            $query,
            static fn (array $data): array => [
                'top_level_keys' => array_keys($data),
                'array_keys' => array_keys(array_filter($data, 'is_array')),
            ],
            (string) config('services.idx_edge.api_key', ''),
            1
        );
    }

    /** @return array<string, mixed> */
    public function history(
        string $ticker,
        int $limit = 120,
        bool $adjusted = false,
        ?string $startDate = null,
        ?string $endDate = null,
        bool $isEod = false
    ): array {
        $ticker = strtoupper(trim($ticker));
        if ($ticker === '^JKSE' || $ticker === 'JKSE') {
            $ticker = 'IHSG';
        }
        if (! preg_match('/^[A-Z]{4}$/', $ticker)) {
            return $this->failure('INVALID_TICKER', 'Kode saham harus terdiri dari 4 huruf.');
        }
        // Provider IDX Edge mensyaratkan parameter bar harian minimal 20 bar (ge: 20) dan maksimal 500 bar.
        // Catat requested_limit dan effective_limit secara transparan tanpa mutasi tersembunyi.
        $requestedLimit = $limit;
        $effectiveLimit = min(500, max(20, $requestedLimit));

        if (! (bool) config('services.idx_edge.enabled', false) && ! $this->isEndpointAllowed('history')) {
            return $this->failure('PROVIDER_DISABLED', 'IDX Edge dinonaktifkan.');
        }

        if (! $this->isEndpointAllowed('history')) {
            return $this->failure('ENDPOINT_DISABLED', 'Endpoint histori IDX Edge belum diaktifkan.');
        }

        $params = [
            'limit' => $effectiveLimit,
            'frame' => 'daily',
            'adjusted' => $adjusted ? 'true' : 'false',
        ];
        if ($isEod) {
            $params['is_eod'] = true;
        }
        if ($startDate !== null) {
            $params['start_date'] = $startDate;
        }
        if ($endDate !== null) {
            $params['end_date'] = $endDate;
        }

        $cacheKey = "history:{$ticker}:{$effectiveLimit}:".($adjusted ? 'adjusted' : 'raw').":{$startDate}:{$endDate}";

        $res = $this->get(
            "/api/history/{$ticker}",
            $params,
            $cacheKey,
            function (array $data) use ($ticker, $adjusted, $requestedLimit, $effectiveLimit): array {
                if (($data['stock_code'] ?? null) !== $ticker || ($data['frame'] ?? null) !== 'daily' || ! is_array($data['rows'] ?? null)) {
                    throw new \UnexpectedValueException('Skema histori IDX Edge tidak valid.');
                }
                if (array_key_exists('data_available', $data) && $data['data_available'] === false) {
                    throw new \UnexpectedValueException('PROVIDER_EMPTY');
                }

                $bars = [];
                $seenDates = [];
                foreach ($data['rows'] as $row) {
                    if (! is_array($row) || ! isset($row['date'], $row['open'], $row['high'], $row['low'], $row['close'], $row['volume'])) {
                        throw new \UnexpectedValueException('Bar histori IDX Edge tidak lengkap.');
                    }
                    $open = $this->finitePositive($row['open']);
                    $high = $this->finitePositive($row['high']);
                    $low = $this->finitePositive($row['low']);
                    $close = $this->finitePositive($row['close']);
                    $volume = $this->finiteNonNegative($row['volume']);
                    $date = (string) $row['date'];

                    // Geometri candle formal:
                    // low <= min(open, close)
                    // high >= max(open, close)
                    // low <= high
                    $minOpenClose = min($open, $close);
                    $maxOpenClose = max($open, $close);
                    $isGeometryValid = ($low <= $minOpenClose) && ($high >= $maxOpenClose) && ($low <= $high);

                    // Volume nol tidak otomatis invalid; pisahkan zero-trade / suspended dari corrupt
                    $volumeClassification = ($volume === 0) ? 'ZERO_VOLUME_SUSPENSION_OR_NO_TRADE' : 'TRADED';

                    $validationStatus = $isGeometryValid ? 'VALID' : 'OHLC_INVALID';
                    $anomalyReason = $isGeometryValid
                        ? ($volume === 0 ? 'Sesi tanpa transaksi atau suspensi (volume 0)' : null)
                        : "Pelanggaran geometri OHLC: low ({$low}) > min(open, close) ({$minOpenClose}), high ({$high}) < max(open, close) ({$maxOpenClose}), atau low > high";

                    if (isset($seenDates[$date])) {
                        continue;
                    }
                    $seenDates[$date] = true;
                    $bars[] = [
                        'date' => $date,
                        'open' => $open,
                        'high' => $high,
                        'low' => $low,
                        'close' => $close,
                        'volume' => $volume,
                        'volume_classification' => $volumeClassification,
                        'value_idr' => isset($row['value']) && is_numeric($row['value']) ? (float) $row['value'] : null,
                        'validation_status' => $validationStatus,
                        'anomaly_reason' => $anomalyReason,
                    ];
                }

                $invalidBarsCount = 0;
                foreach ($bars as $b) {
                    if ($b['validation_status'] === 'OHLC_INVALID') {
                        $invalidBarsCount++;
                    }
                }

                return [
                    'ticker' => $ticker,
                    'timeframe' => '1d',
                    'adjustment_basis' => $adjusted ? 'PROVIDER_BACK_ADJUSTED' : 'UNADJUSTED',
                    'volume_unit' => 'NOT_DOCUMENTED',
                    'requested_limit' => $requestedLimit,
                    'effective_limit' => $effectiveLimit,
                    'data_available' => $data['data_available'] ?? null,
                    'has_invalid_bars' => $invalidBarsCount > 0,
                    'invalid_bars_count' => $invalidBarsCount,
                    'bars' => $bars,
                ];
            }
        );

        if (($res['success'] ?? false) && isset($res['data'])) {
            $res['data']['requested_limit'] = $requestedLimit;
            $res['data']['effective_limit'] = $effectiveLimit;
        }

        return $res;
    }

    public function isEndpointAllowed(string $endpointKey): bool
    {
        if (class_exists(DataSourceRegistryService::class)) {
            $registry = app(DataSourceRegistryService::class);
            if ($registry->isEndpointEnabled($endpointKey)) {
                return true;
            }
        }

        return (bool) config("services.idx_edge.endpoints.{$endpointKey}", false);
    }

    public function hasAnyEndpointAllowed(): bool
    {
        return $this->isEndpointAllowed('history')
            || $this->isEndpointAllowed('price')
            || $this->isEndpointAllowed('broker')
            || $this->isEndpointAllowed('fundamental')
            || $this->isEndpointAllowed('search')
            || $this->isEndpointAllowed('market_cap')
            || $this->isEndpointAllowed('broker_accumulation');
    }

    /**
     * Search stock / instrument from IDX Edge by code or company name.
     *
     * @return array<string, mixed>
     */
    public function search(string $query): array
    {
        $q = trim($query);
        if ($q === '') {
            return $this->failure('INVALID_QUERY', 'Kata kunci pencarian tidak boleh kosong.');
        }

        if (! (bool) config('services.idx_edge.enabled', false) && ! $this->isEndpointAllowed('search')) {
            return $this->failure('PROVIDER_DISABLED', 'IDX Edge dinonaktifkan.');
        }

        if (! $this->isEndpointAllowed('search')) {
            return $this->failure('ENDPOINT_DISABLED', 'Endpoint pencarian saham IDX Edge belum diaktifkan.');
        }

        return $this->get(
            '/api/search',
            ['q' => $q],
            'search',
            function (array $data): array {
                $rawList = is_array($data['results'] ?? null)
                    ? $data['results']
                    : (is_array($data) ? $data : []);

                $cleanList = [];
                foreach ($rawList as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $code = strtoupper(trim((string) ($item['stock_code'] ?? $item['code'] ?? '')));
                    if ($code === '') {
                        continue;
                    }
                    $cleanList[] = [
                        'stock_code' => $code,
                        'stock_name' => (string) ($item['stock_name'] ?? $item['name'] ?? ''),
                        'last_date' => (string) ($item['last_date'] ?? ''),
                    ];
                }

                return [
                    'query' => $q ?? '',
                    'total_found' => count($cleanList),
                    'items' => $cleanList,
                ];
            },
            300 // 5 minutes cache for search discovery
        );
    }

    /**
     * Get market cap, listed shares, and universe from IDX Edge.
     *
     * @return array<string, mixed>
     */
    public function marketCap(int $page = 1, int $perPage = 50, ?string $date = null, ?string $codes = null): array
    {
        if (! (bool) config('services.idx_edge.enabled', false) && ! $this->isEndpointAllowed('market_cap')) {
            return $this->failure('PROVIDER_DISABLED', 'IDX Edge dinonaktifkan.');
        }

        if (! $this->isEndpointAllowed('market_cap')) {
            return $this->failure('ENDPOINT_DISABLED', 'Endpoint market-cap IDX Edge belum diaktifkan.');
        }

        $params = [
            'page' => max(1, $page),
            'per_page' => min(1000, max(1, $perPage)),
        ];
        if ($date !== null) {
            $params['date'] = $date;
        }
        if ($codes !== null) {
            $params['codes'] = $codes;
        }

        $cacheKey = "market_cap:{$page}:{$perPage}:{$date}:{$codes}";

        return $this->get(
            '/api/market-cap',
            $params,
            $cacheKey,
            function (array $data): array {
                if (! isset($data['total'], $data['data']) || ! is_array($data['data'])) {
                    throw new \UnexpectedValueException('Skema market-cap IDX Edge tidak valid.');
                }

                $items = [];
                foreach ($data['data'] as $row) {
                    if (! is_array($row) || empty($row['code'])) {
                        continue;
                    }
                    $items[] = [
                        'code' => strtoupper(trim((string) $row['code'])),
                        'name' => (string) ($row['name'] ?? ''),
                        'close' => isset($row['close']) && is_numeric($row['close']) ? (float) $row['close'] : null,
                        'change' => isset($row['change']) && is_numeric($row['change']) ? (float) $row['change'] : null,
                        'change_pct' => isset($row['change_pct']) && is_numeric($row['change_pct']) ? (float) $row['change_pct'] : null,
                        'source_date' => (string) ($row['source_date'] ?? $data['date'] ?? ''),
                        'listed_shares' => isset($row['listed_shares']) && is_numeric($row['listed_shares']) ? (float) $row['listed_shares'] : null,
                        'market_cap' => isset($row['market_cap']) && is_numeric($row['market_cap']) ? (float) $row['market_cap'] : null,
                        'turnover_ratio' => isset($row['turnover_ratio']) && is_numeric($row['turnover_ratio']) ? (float) $row['turnover_ratio'] : null,
                    ];
                }

                return [
                    'date' => (string) ($data['date'] ?? ''),
                    'total' => (int) $data['total'],
                    'page' => (int) ($data['page'] ?? 1),
                    'per_page' => (int) ($data['per_page'] ?? 50),
                    'total_pages' => (int) ($data['total_pages'] ?? 1),
                    'items' => $items,
                ];
            },
            30 // Short cache for current API snapshot
        );
    }

    /**
     * Read complete current equity universe from IDX Edge market-cap pages.
     *
     * @return array<string, mixed>
     */
    public function allMarketCap(?string $date = null): array
    {
        $cacheKey = 'idx-edge:universe:'.($date ?? 'latest');
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $first = $this->marketCap(1, 50, $date);
        if (! ($first['success'] ?? false)) {
            return $first;
        }

        $data = $first['data'] ?? [];
        $items = $data['items'] ?? [];
        $totalPages = min(100, max(1, (int) ($data['total_pages'] ?? 1)));
        for ($page = 2; $page <= $totalPages; $page++) {
            // Provider accepts 50 rows only; spacing avoids local throttle between pages.
            usleep(350000);
            $next = $this->marketCap($page, 50, $date);
            if (! ($next['success'] ?? false)) {
                return $next;
            }
            $items = array_merge($items, $next['data']['items'] ?? []);
        }

        $result = [
            'success' => true,
            'data' => [
                'date' => $data['date'] ?? $date,
                'total' => (int) ($data['total'] ?? count($items)),
                'items' => $items,
            ],
            'provider' => $first['provider'] ?? null,
            'received_at' => $first['received_at'] ?? null,
        ];
        Cache::put($cacheKey, $result, now()->addMinutes(5));

        return $result;
    }

    /**
     * Get historical broker accumulation vs distribution time-series.
     *
     * @return array<string, mixed>
     */
    public function brokerAccumulation(
        string $ticker,
        ?string $startDate = null,
        ?string $endDate = null,
        int $top = 3,
        ?string $brokers = null
    ): array {
        $ticker = strtoupper(trim($ticker));
        if (! preg_match('/^[A-Z]{4}$/', $ticker)) {
            return $this->failure('INVALID_TICKER', 'Kode saham harus terdiri dari 4 huruf.');
        }

        if (! (bool) config('services.idx_edge.enabled', false) && ! $this->isEndpointAllowed('broker_accumulation')) {
            return $this->failure('PROVIDER_DISABLED', 'IDX Edge dinonaktifkan.');
        }

        if (! $this->isEndpointAllowed('broker_accumulation')) {
            return $this->failure('ENDPOINT_DISABLED', 'Endpoint broker-accumulation IDX Edge belum diaktifkan.');
        }

        $params = [
            'top' => min(5, max(1, $top)),
        ];
        if ($startDate !== null) {
            $params['start_date'] = $startDate;
        }
        if ($endDate !== null) {
            $params['end_date'] = $endDate;
        }
        if ($brokers !== null) {
            $params['brokers'] = $brokers;
        }

        $cacheKey = "broker_accum:{$ticker}:{$top}:{$startDate}:{$endDate}:{$brokers}";

        return $this->get(
            "/api/broker-accumulation/{$ticker}",
            $params,
            $cacheKey,
            function (array $data) use ($ticker): array {
                if (($data['code'] ?? null) !== $ticker || ! is_array($data['series'] ?? null)) {
                    throw new \UnexpectedValueException('Skema broker-accumulation IDX Edge tidak valid.');
                }

                $series = [];
                foreach ($data['series'] as $s) {
                    if (! is_array($s) || empty($s['broker_code'])) {
                        continue;
                    }
                    $points = [];
                    if (is_array($s['points'] ?? null)) {
                        foreach ($s['points'] as $pt) {
                            if (! is_array($pt) || ! isset($pt['date'])) {
                                continue;
                            }
                            foreach (['nval', 'nvol', 'cum_nval'] as $field) {
                                if (! array_key_exists($field, $pt) || ! is_numeric($pt[$field])) {
                                    throw new \UnexpectedValueException("Field {$field} broker-accumulation tidak valid.");
                                }
                            }
                            $points[] = [
                                'date' => (string) $pt['date'],
                                'nval' => (float) $pt['nval'],
                                'nvol' => (float) $pt['nvol'],
                                'cum_nval' => (float) $pt['cum_nval'],
                                'bavg' => isset($pt['bavg']) && is_numeric($pt['bavg']) ? (float) $pt['bavg'] : null,
                                'savg' => isset($pt['savg']) && is_numeric($pt['savg']) ? (float) $pt['savg'] : null,
                            ];
                        }
                    }

                    $series[] = [
                        'broker_code' => (string) $s['broker_code'],
                        'broker_name' => (string) ($s['broker_name'] ?? ''),
                        'points' => $points,
                    ];
                }

                return [
                    'ticker' => $ticker,
                    'start_date' => (string) ($data['start_date'] ?? ''),
                    'end_date' => (string) ($data['end_date'] ?? ''),
                    'series' => $series,
                    'top_buyers' => is_array($data['top_buyers'] ?? null) ? $data['top_buyers'] : [],
                    'top_sellers' => is_array($data['top_sellers'] ?? null) ? $data['top_sellers'] : [],
                ];
            },
            600 // 10 minutes cache
        );
    }

    public function brokerSummary(
        string $ticker,
        ?string $startDate = null,
        string $flow = 'all',
        int $limit = 50,
        ?string $endDate = null,
        bool $net = false
    ): array {
        $ticker = strtoupper(trim($ticker));
        if (! preg_match('/^[A-Z]{4}$/', $ticker)) {
            return $this->failure('INVALID_TICKER', 'Kode saham harus terdiri dari 4 huruf.');
        }

        if (! (bool) config('services.idx_edge.enabled', false) && ! $this->isEndpointAllowed('broker')) {
            return $this->failure('PROVIDER_DISABLED', 'IDX Edge dinonaktifkan.');
        }

        if (! $this->isEndpointAllowed('broker')) {
            return $this->failure('ENDPOINT_DISABLED', 'Endpoint broker summary IDX Edge belum diaktifkan.');
        }

        $effectiveLimit = min(100, max(5, $limit));
        $query = [
            'flow' => in_array($flow, ['all', 'F', 'D'], true) ? $flow : 'all',
            'broker_limit' => $effectiveLimit,
            'level_limit' => 25,
            'all_data' => 'false',
        ];
        if ($net) {
            $query['net'] = 'true';
        }

        if ($startDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $query['start_date'] = $startDate;
            $query['end_date'] = ($endDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) ? $endDate : $startDate;
        } elseif ($endDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $query['end_date'] = $endDate;
        }

        $cacheTag = 'broker-summary:'.$ticker.':'.$flow.':'.($query['start_date'] ?? 'all').':'.($query['end_date'] ?? 'all').':'.$effectiveLimit.':'.($net ? '1' : '0');

        return $this->get(
            "/api/broker-summary/{$ticker}",
            $query,
            $cacheTag,
            function (array $data) use ($ticker, $flow): array {
                $brokers = [];
                $rawList = $data['brokers'] ?? [];
                if (is_array($rawList)) {
                    foreach ($rawList as $b) {
                        if (! is_array($b) || ! isset($b['broker_code'])) {
                            continue;
                        }
                        $bvol = (int) ($b['bvol'] ?? 0);
                        $bval = (float) ($b['bval'] ?? 0);
                        $svol = (int) ($b['svol'] ?? 0);
                        $sval = (float) ($b['sval'] ?? 0);
                        $nvol = (int) ($b['nvol'] ?? ($bvol - $svol));
                        $nval = (float) ($b['nval'] ?? ($bval - $sval));

                        $brokers[] = [
                            'broker_code' => strtoupper(trim((string) $b['broker_code'])),
                            'broker_name' => isset($b['broker_name']) ? trim((string) $b['broker_name']) : null,
                            'board' => 'RG',
                            'buy_volume' => $bvol,
                            'buy_value' => $bval,
                            'buy_frequency' => (int) ($b['bfrq'] ?? 0),
                            'buy_avg_price' => $bvol > 0 ? round($bval / $bvol, 4) : null,
                            'sell_volume' => $svol,
                            'sell_value' => $sval,
                            'sell_frequency' => (int) ($b['sfrq'] ?? 0),
                            'sell_avg_price' => $svol > 0 ? round($sval / $svol, 4) : null,
                            'net_volume' => $nvol,
                            'net_value' => $nval,
                            'net_ratio' => $nvol != 0 ? round($nval / $nvol, 4) : null,
                            'flow_type' => $flow,
                            'buy_lot' => $bvol % 100 === 0 ? intdiv($bvol, 100) : null,
                            'sell_lot' => $svol % 100 === 0 ? intdiv($svol, 100) : null,
                            'net_lot' => $nvol % 100 === 0 ? intdiv($nvol, 100) : null,
                        ];
                    }
                }

                return [
                    'ticker' => $ticker,
                    'start_date' => $data['broker_start_date'] ?? $data['broker_date_min'] ?? null,
                    'end_date' => $data['broker_end_date'] ?? $data['broker_date_max'] ?? null,
                    'flow' => $flow,
                    'board_coverage' => 'RG_VERIFIED_ON_SAMPLE',
                    'volume_unit' => 'SHARES',
                    'currency' => 'IDR',
                    'brokers' => $brokers,
                    'top_buyers' => collect($brokers)->filter(fn (array $row): bool => $row['net_value'] > 0)->sortByDesc('net_value')->take(5)->values()->all(),
                    'top_sellers' => collect($brokers)->filter(fn (array $row): bool => $row['net_value'] < 0)->sortBy('net_value')->take(5)->values()->all(),
                    'net_value' => collect($brokers)->sum('net_value'),
                    'sessions_covered' => $data['sessions_covered'] ?? $data['session_count'] ?? null,
                    'broker_levels' => $data['broker_levels'] ?? [],
                ];
            },
            300
        );
    }

    /** @return array<string, mixed> */
    public function insiders(string $ticker, int $page = 1, int $pageSize = 10): array
    {
        $ticker = strtoupper(trim($ticker));
        $page = min(100, max(1, $page));
        $pageSize = min(100, max(1, $pageSize));

        return $this->fetchInsiderPage($ticker, $page, $pageSize);
    }

    /** @return array<string, mixed> */
    public function allInsiders(string $ticker, int $pageSize = 100): array
    {
        $ticker = strtoupper(trim($ticker));
        $pageSize = min(100, max(1, $pageSize));
        $first = $this->fetchInsiderPage($ticker, 1, $pageSize);
        if (! ($first['success'] ?? false)) {
            return $first;
        }

        $data = $first['data'] ?? [];
        $totalPages = max(1, (int) ($data['total_pages'] ?? 1));
        $items = $data['items'] ?? [];
        for ($currentPage = 2; $currentPage <= $totalPages; $currentPage++) {
            $next = $this->fetchInsiderPage($ticker, $currentPage, $pageSize);
            if (! ($next['success'] ?? false)) {
                return $next + ['partial_items' => $items, 'partial_pages' => $currentPage - 1];
            }
            $items = array_merge($items, $next['data']['items'] ?? []);
        }
        $data['items'] = $items;
        $data['count'] = count($items);
        $data['page'] = 1;
        $data['page_size'] = $pageSize;
        $data['total_pages'] = $totalPages;
        $data['evidence'] = ['pages_fetched' => $totalPages, 'complete' => true];
        $first['data'] = $data;

        return $first;
    }

    /** @return array<string, mixed> */
    private function fetchInsiderPage(string $ticker, int $page, int $pageSize): array
    {
        if (! preg_match('/^[A-Z]{4}$/', $ticker)) {
            return $this->failure('INVALID_TICKER', 'Kode saham harus terdiri dari 4 huruf.');
        }
        if (! $this->isEndpointAllowed('insiders')) {
            return $this->failure('PROVIDER_CAPABILITY_UNVERIFIED', 'Endpoint insider IDX Edge belum diprobe atau skemanya belum terverifikasi.');
        }

        return $this->get(
            "/api/insiders/{$ticker}",
            ['page' => $page, 'page_size' => $pageSize],
            "insiders:{$ticker}:{$page}:{$pageSize}",
            function (array $data) use ($ticker): array {
                $parsed = app(IdxEdgeCapabilityParser::class)->parseInsiders($data);
                if ($parsed['ticker'] !== $ticker) {
                    throw new \UnexpectedValueException('Kode saham insider tidak cocok dengan request.');
                }

                return $parsed;
            },
            900
        );
    }

    /** @return array<string, mixed> */
    public function screenerLatest(): array
    {
        if (! $this->isEndpointAllowed('screener')) {
            return $this->failure('PROVIDER_CAPABILITY_UNVERIFIED', 'Endpoint screener IDX Edge belum diprobe atau skemanya belum terverifikasi.');
        }

        return $this->get('/api/screener/latest', [], 'screener:latest', fn (array $data): array => app(IdxEdgeCapabilityParser::class)->parseScreener($data), 300);
    }

    /** @return array<string, mixed> */
    public function movers(string $sort = 'chg', int $limit = 10): array
    {
        if (! $this->isEndpointAllowed('movers')) {
            return $this->failure('PROVIDER_CAPABILITY_UNVERIFIED', 'Endpoint movers IDX Edge belum diprobe atau skemanya belum terverifikasi.');
        }

        $sort = in_array($sort, ['chg'], true) ? $sort : 'chg';
        $limit = min(50, max(1, $limit));

        return $this->get(
            '/api/movers',
            ['by' => $sort, 'n' => $limit],
            "movers:{$sort}:{$limit}",
            fn (array $data): array => app(IdxEdgeCapabilityParser::class)->parseMovers($data),
            300
        );
    }

    public function financialStatements(string $ticker, string $reportType = 'INCOME_STATEMENT', string $period = 'annually', int $limit = 2, ?string $year = null): array
    {
        $ticker = strtoupper(trim($ticker));
        if (! preg_match('/^[A-Z]{4}$/', $ticker)) {
            return $this->failure('INVALID_TICKER', 'Kode saham harus terdiri dari 4 huruf.');
        }

        if (! (bool) config('services.idx_edge.enabled', false) && ! $this->isEndpointAllowed('fundamental')) {
            return $this->failure('PROVIDER_DISABLED', 'IDX Edge dinonaktifkan.');
        }

        if (! $this->isEndpointAllowed('fundamental')) {
            return $this->failure('ENDPOINT_DISABLED', 'Endpoint fundamental IDX Edge belum diaktifkan.');
        }

        $validReports = ['INCOME_STATEMENT', 'BALANCE_SHEET', 'CASH_FLOW_REPORT'];
        $reportType = in_array($reportType, $validReports, true) ? $reportType : 'INCOME_STATEMENT';
        $period = in_array($period, ['annually', 'quarterly'], true) ? $period : 'annually';
        $year = $year !== null && preg_match('/^\d{4}$/', $year) ? $year : null;
        $query = [
            'report_type' => $reportType,
            'period' => $period,
            'limit' => min(40, max(1, $limit)),
        ];
        if ($year !== null) {
            $query['year'] = $year;
        }

        return $this->get(
            "/api/financial-statements/{$ticker}",
            $query,
            "financial-statements:{$ticker}:{$reportType}:{$period}:{$limit}:".($year ?? 'all'),
            function (array $data) use ($ticker, $reportType, $period): array {
                $items = $data['items'] ?? [];
                if (! is_array($items)) {
                    throw new \UnexpectedValueException('Skema laporan keuangan IDX Edge tidak valid.');
                }

                return [
                    'ticker' => $ticker,
                    'report_type' => $reportType,
                    'period' => $period,
                    'count' => count($items),
                    'items' => $items,
                ];
            }
        );
    }

    /** @return array<string, mixed> */
    public function ownership(string $ticker, ?int $month = null, ?int $year = null): array
    {
        $ticker = strtoupper(trim($ticker));
        if (! preg_match('/^[A-Z]{4}$/', $ticker)) {
            return $this->failure('INVALID_TICKER', 'Kode saham harus terdiri dari 4 huruf.');
        }
        if (! $this->isEndpointAllowed('ownership')) {
            return $this->failure('PROVIDER_CAPABILITY_UNVERIFIED', 'Endpoint ownership belum terverifikasi.');
        }
        $query = ['code' => $ticker];
        if ($month !== null && $month >= 1 && $month <= 12) {
            $query['month'] = $month;
        }
        if ($year !== null && $year >= 2000 && $year <= 2100) {
            $query['year'] = $year;
        }

        return $this->get('/api/ownership/stock', $query, 'ownership:'.$ticker.':'.($month ?? 'latest').':'.($year ?? 'latest'), function (array $data) use ($ticker): array {
            $items = $data['items'] ?? $data['data'] ?? [];
            if (! is_array($items)) {
                throw new \UnexpectedValueException('Skema ownership IDX Edge tidak valid.');
            }

            return [
                'ticker' => $ticker,
                'found' => (bool) ($data['found'] ?? ($items !== [])),
                'period' => $data['period'] ?? null,
                'previous_period' => $data['previous_period'] ?? null,
                'available_periods' => is_array($data['available_periods'] ?? null) ? $data['available_periods'] : [],
                'items' => array_values($items),
                'change_pct_mom_semantics' => $data['change_pct_mom_semantics'] ?? 'NOT_DOCUMENTED',
            ];
        }, 300);
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  callable(array<string, mixed>): array<string, mixed>  $normalize
     * @return array<string, mixed>
     */
    private function get(string $path, array $query, string $cacheSuffix, callable $normalize, int $ttl = 30): array
    {
        if (! (bool) config('services.idx_edge.enabled', false) && ! $this->hasAnyEndpointAllowed()) {
            return $this->failure('PROVIDER_DISABLED', 'IDX Edge dinonaktifkan.');
        }

        $apiKey = (string) config('services.idx_edge.api_key', '');
        if ($apiKey === '') {
            return $this->failure('CREDENTIAL_MISSING', 'Kredensial IDX Edge belum dikonfigurasi.');
        }

        $cacheKey = 'idx-edge:response:'.hash('sha256', $cacheSuffix.'?'.http_build_query($query));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            // Guard against stale entries if driver does not purge automatically
            if (! empty($cached['expires_at']) && Carbon::parse($cached['expires_at'])->isPast()) {
                Cache::forget($cacheKey);
            } else {
                $cached['cache_hit'] = true;
                $cached['read_at'] = now('UTC')->toIso8601String();

                return $cached;
            }
        }

        // Atomic request coalescing: Lock per request key, re-check cache inside lock
        // Lock TTL and wait duration dynamically aligned with HTTP timeout to prevent premature lock release
        $httpTimeout = max(1, (int) config('services.idx_edge.timeout', 10));
        $lockDuration = $httpTimeout + 15;
        $lockWait = $httpTimeout + 5;
        $lockKey = 'idx-edge:lock:'.hash('sha256', $cacheSuffix.'?'.http_build_query($query));

        try {
            $lock = Cache::lock($lockKey, $lockDuration);

            return $lock->block($lockWait, function () use ($cacheKey, $path, $query, $normalize, $apiKey, $ttl): array {
                $cached = Cache::get($cacheKey);
                if (is_array($cached)) {
                    $cached['cache_hit'] = true;
                    $cached['coalesced'] = true;

                    return $cached;
                }

                return $this->executeHttpRequest($cacheKey, $path, $query, $normalize, $apiKey, $ttl);
            });
        } catch (Throwable $e) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cached['cache_hit'] = true;

                return $cached;
            }

            return $this->executeHttpRequest($cacheKey, $path, $query, $normalize, $apiKey, $ttl);
        }
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  callable(array<string, mixed>): array<string, mixed>  $normalize
     * @return array<string, mixed>
     */
    private function executeHttpRequest(string $cacheKey, string $path, array $query, callable $normalize, string $apiKey, int $ttl): array
    {
        $date = now('Asia/Jakarta')->toDateString();
        $usageKey = "idx-edge:quota:used:{$date}";
        $httpReqKey = "idx-edge:http_requests:count:{$date}";
        $rejectedKey = "idx-edge:requests:rejected:{$date}";

        // Entitlement reconciliation: use confirmed account quota from provider headers if available
        $entitlementLimit = (int) Cache::get('idx-edge:entitlement:limit', 0);
        $configuredBudget = (int) config('services.idx_edge.daily_budget', 30000);
        $dailyBudget = $entitlementLimit > 0 ? min($entitlementLimit, $configuredBudget) : $configuredBudget;

        // Dynamic reserve: protect EOD refresh (~1,926 units for full universe raw+adjusted) + Telegram interactive (200 units)
        // If query sets 'is_eod' or 'priority', it can tap into EOD reserve. Backfill cannot consume this reserve.
        $reserve = max(0, (int) config('services.idx_edge.reserve', 500));
        $eodReserve = max(0, (int) config('services.idx_edge.eod_allocation', 2500));
        $pathUpper = strtoupper($path);
        $isHighPriority = str_contains($pathUpper, '/IHSG')
            || str_contains($pathUpper, '/BBCA')
            || in_array(strtoupper($query['ticker'] ?? ''), ['IHSG', '^JKSE', 'JKSE', 'BBCA'])
            || ($query['priority'] ?? false) === true
            || ($query['is_eod'] ?? false) === true;

        // Batas kerja bagi backfill: dailyBudget - reserve - eodReserve
        // Batas kerja bagi EOD refresh: dailyBudget - reserve
        // Batas kerja bagi interaktif/prioritas tinggi: dailyBudget
        $isEodJob = ($query['is_eod'] ?? false) === true;
        $isDisplaySnapshot = str_contains($pathUpper, '/MOVERS');
        if ($isHighPriority && ! $isEodJob) {
            $threshold = $dailyBudget;
        } elseif ($isEodJob) {
            $threshold = max(0, $dailyBudget - $reserve);
        } elseif ($isDisplaySnapshot) {
            // Dashboard snapshot gets controlled interactive allocation, never EOD reserve.
            $threshold = max(0, $dailyBudget - $reserve);
        } else {
            // General backfill / batch
            $threshold = max(0, $dailyBudget - $reserve - $eodReserve);
        }

        // Rate limiting check FIRST: cooling rate limit never consumes quota units
        $rpm = max(1, (int) config('services.idx_edge.requests_per_minute', 10));
        if (! RateLimiter::attempt('idx-edge:provider-rpm', $rpm, static fn (): bool => true, 60)) {
            $seconds = RateLimiter::availableIn('idx-edge:provider-rpm');

            return $this->failure('LOCAL_RATE_LIMIT', "Batas permintaan lokal IDX Edge tercapai. Tersedia dalam {$seconds} detik.", null, [
                'available_in' => $seconds,
            ]);
        }

        // Atomic check and quota reservation across concurrent workers AFTER rate limit passes
        $lock = Cache::lock("idx-edge:lock:quota:{$date}", 5);
        $acquired = $lock->block(3);
        if (! $acquired) {
            return $this->failure('CONCURRENT_LOCK_TIMEOUT', 'Gagal memperoleh lock kuota antar-worker.');
        }

        try {
            $used = (int) Cache::get($usageKey, 0);
            if ($used + 1 > $threshold) {
                Cache::increment($rejectedKey);

                return $this->failure('DAILY_BUDGET_RESERVED', 'Cadangan kuota harian IDX Edge dilindungi.');
            }
            Cache::put($usageKey, $used + 1, now('Asia/Jakarta')->endOfDay());
        } finally {
            $lock->release();
        }

        $startedAt = microtime(true);
        try {
            Log::info('[IDX_EDGE_REQUEST]', ['path' => $path, 'query' => $query]);
            $response = Http::acceptJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->timeout(max(1, (int) config('services.idx_edge.timeout', 15)))
                ->get(rtrim((string) config('services.idx_edge.base_url'), '/').$path, $query);
        } catch (ConnectionException $exception) {
            Cache::decrement($usageKey);

            return $this->failure('PROVIDER_CONNECTION_FAILED', $this->redact($exception->getMessage(), $apiKey), $startedAt, [
                'http_status' => null,
                'transport_status' => 'CONNECTION_EXCEPTION',
                'response_period' => $this->responsePeriod($startedAt),
            ]);
        } catch (Throwable $exception) {
            return $this->failure('PROVIDER_REQUEST_FAILED', $this->redact($exception->getMessage(), $apiKey), $startedAt, [
                'http_status' => null,
                'transport_status' => 'TRANSPORT_EXCEPTION',
                'response_period' => $this->responsePeriod($startedAt),
            ]);
        }

        // Record both HTTP request count and actual quota cost units
        Cache::increment($httpReqKey);
        $quotaCost = max(0, (int) ($response->header('X-Quota-Cost') ?? 1));
        if ($quotaCost > 1) {
            Cache::increment($usageKey, $quotaCost - 1);
        }

        if ($response->header('X-RateLimit-Limit')) {
            Cache::put('idx-edge:entitlement:limit', (int) $response->header('X-RateLimit-Limit'), now()->addDays(7));
        }
        if ($response->header('X-RateLimit-Remaining')) {
            Cache::put('idx-edge:entitlement:remaining', (int) $response->header('X-RateLimit-Remaining'), now()->addDays(1));
        }

        if (! $response->successful()) {
            $retryAfter = $response->header('Retry-After');
            $message = (string) ($response->json('message') ?? "IDX Edge mengembalikan HTTP {$response->status()}.");
            $errorType = $response->status() === 429 ? 'PROVIDER_429_RATE_LIMIT' : "PROVIDER_HTTP_{$response->status()}";

            return array_merge(
                $this->failure($errorType, $this->redact($message, $apiKey), $startedAt, [
                    'http_status' => $response->status(),
                    'transport_status' => 'HTTP_RESPONSE',
                    'response_period' => $this->responsePeriod($startedAt),
                ]),
                ['retry_after_seconds' => is_numeric($retryAfter) ? (int) $retryAfter : null]
            );
        }

        $body = $response->json();
        if (! is_array($body)) {
            return $this->failure('INVALID_PROVIDER_RESPONSE', 'Respons IDX Edge bukan objek JSON.', $startedAt);
        }

        try {
            $data = $normalize($body);
        } catch (Throwable $exception) {
            $errorType = $exception->getMessage() === 'PROVIDER_EMPTY'
                ? 'PROVIDER_EMPTY'
                : 'INVALID_PROVIDER_SCHEMA';

            return $this->failure($errorType, $this->redact($exception->getMessage(), $apiKey), $startedAt);
        }

        $result = [
            'success' => true,
            'provider' => 'IDX_EDGE',
            'data' => $data,
            'raw_response' => $body,
            'payload_sha256' => hash('sha256', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'cache_hit' => false,
            'http_status' => $response->status(),
            'transport_status' => 'HTTP_RESPONSE',
            'response_period' => $this->responsePeriod($startedAt),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'received_at' => now('UTC')->toIso8601String(),
            'cached_at' => now('UTC')->toIso8601String(),
            'expires_at' => now('UTC')->addSeconds($ttl)->toIso8601String(),
            'quota' => [
                'cost' => $quotaCost,
                'limit' => is_numeric($response->header('X-RateLimit-Limit'))
                    ? (int) $response->header('X-RateLimit-Limit')
                    : null,
                'remaining' => is_numeric($response->header('X-RateLimit-Remaining'))
                    ? (int) $response->header('X-RateLimit-Remaining')
                    : null,
                'reset' => $response->header('X-RateLimit-Reset') ?? null,
                'request_id' => $response->header('CF-RAY') ?? $response->header('X-Request-Id') ?? null,
                'http_requests_count' => (int) Cache::get($httpReqKey, 1),
                'quota_units_used' => (int) Cache::get($usageKey, $used + $quotaCost),
                'local_used' => $used + $quotaCost,
                'daily_budget' => $dailyBudget,
                'reserve' => $reserve,
            ],
        ];

        Cache::put($cacheKey, $result, $ttl);

        return $result;
    }

    /** @return array{started_at: string, finished_at: string, duration_ms: int} */
    private function responsePeriod(float $startedAt): array
    {
        $finishedAt = microtime(true);

        return [
            'started_at' => Carbon::createFromTimestamp($startedAt, 'UTC')->toIso8601String(),
            'finished_at' => Carbon::createFromTimestamp($finishedAt, 'UTC')->toIso8601String(),
            'duration_ms' => max(0, (int) round(($finishedAt - $startedAt) * 1000)),
        ];
    }

    private function finitePositive(mixed $value): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
            throw new \UnexpectedValueException('Nilai harga IDX Edge harus finite dan positif.');
        }

        return (float) $value;
    }

    private function finiteNonNegative(mixed $value): int
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new \UnexpectedValueException('Volume IDX Edge harus finite dan non-negatif.');
        }

        return (int) $value;
    }

    /** @return array<string, mixed> */
    private function failure(string $errorType, string $message, ?float $startedAt = null, array $extra = []): array
    {
        return array_merge([
            'success' => false,
            'provider' => 'IDX_EDGE',
            'error_type' => $errorType,
            'message' => $message,
            'cache_hit' => false,
            'latency_ms' => $startedAt === null ? 0 : (int) round((microtime(true) - $startedAt) * 1000),
        ], $extra);
    }

    private function redact(string $message, string $apiKey): string
    {
        $redacted = $apiKey === '' ? $message : str_replace($apiKey, '[REDACTED]', $message);

        return (string) preg_replace('/\bsk_[A-Za-z0-9_-]{12,}\b/', '[REDACTED]', $redacted);
    }
}
