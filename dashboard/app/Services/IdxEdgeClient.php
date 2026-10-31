<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class IdxEdgeClient
{
    /** @return array<string, mixed> */
    public function price(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));
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

    /** @return array<string, mixed> */
    public function history(string $ticker, int $limit = 120, bool $adjusted = false): array
    {
        $ticker = strtoupper(trim($ticker));
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

        $res = $this->get(
            "/api/history/{$ticker}",
            ['limit' => $effectiveLimit, 'frame' => 'daily', 'adjusted' => $adjusted ? 'true' : 'false'],
            "history:{$ticker}:{$effectiveLimit}:".($adjusted ? 'adjusted' : 'raw'),
            function (array $data) use ($ticker, $adjusted, $requestedLimit, $effectiveLimit): array {
                if (($data['stock_code'] ?? null) !== $ticker || ($data['frame'] ?? null) !== 'daily' || ! is_array($data['rows'] ?? null)) {
                    throw new \UnexpectedValueException('Skema histori IDX Edge tidak valid.');
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
                    if ($high < max($open, $close, $low) || $low > min($open, $close, $high) || isset($seenDates[$date])) {
                        throw new \UnexpectedValueException('Integritas OHLC atau tanggal histori IDX Edge tidak valid.');
                    }
                    $seenDates[$date] = true;
                    $bars[] = [
                        'date' => $date,
                        'open' => $open,
                        'high' => $high,
                        'low' => $low,
                        'close' => $close,
                        'volume' => $volume,
                        'value_idr' => isset($row['value']) && is_numeric($row['value']) ? (float) $row['value'] : null,
                    ];
                }

                return [
                    'ticker' => $ticker,
                    'timeframe' => '1d',
                    'adjustment_basis' => $adjusted ? 'PROVIDER_BACK_ADJUSTED' : 'UNADJUSTED',
                    'volume_unit' => 'NOT_DOCUMENTED',
                    'requested_limit' => $requestedLimit,
                    'effective_limit' => $effectiveLimit,
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
            || $this->isEndpointAllowed('fundamental');
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
                    'broker_levels' => $data['broker_levels'] ?? [],
                ];
            },
            300
        );
    }

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

        if (! (bool) config('services.idx_edge.enabled', false) && ! $this->isEndpointAllowed('broker')) {
            return $this->failure('PROVIDER_DISABLED', 'IDX Edge dinonaktifkan.');
        }

        if (! $this->isEndpointAllowed('broker')) {
            return $this->failure('ENDPOINT_DISABLED', 'Endpoint broker summary/accumulation IDX Edge belum diaktifkan.');
        }

        $query = [
            'top' => min(5, max(1, $top)),
        ];
        if ($startDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $query['start_date'] = $startDate;
        }
        if ($endDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $query['end_date'] = $endDate;
        }
        if ($brokers !== null) {
            $query['brokers'] = $brokers;
        }

        $cacheTag = 'broker-accum:'.$ticker.':'.($startDate ?? 'all').':'.($endDate ?? 'all').':'.$top.':'.($brokers ?? 'none');

        return $this->get(
            "/api/broker-accumulation/{$ticker}",
            $query,
            $cacheTag,
            function (array $data) use ($ticker): array {
                return [
                    'ticker' => $ticker,
                    'start_date' => $data['start_date'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                    'top_buyers' => $data['top_buyers'] ?? [],
                    'top_sellers' => $data['top_sellers'] ?? [],
                    'series' => $data['series'] ?? [],
                ];
            },
            300
        );
    }

    public function financialStatements(string $ticker, string $reportType = 'INCOME_STATEMENT', string $period = 'annually', int $limit = 2): array
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

        return $this->get(
            "/api/financial-statements/{$ticker}",
            [
                'report_type' => $reportType,
                'period' => $period,
                'limit' => min(10, max(1, $limit)),
            ],
            "financial-statements:{$ticker}:{$reportType}:{$period}:{$limit}",
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
        $dailyBudget = max(0, (int) config('services.idx_edge.daily_budget', 800));
        $reserve = max(0, (int) config('services.idx_edge.reserve', 200));
        $used = (int) Cache::get($usageKey, 0);
        if ($used + 1 > max(0, $dailyBudget - $reserve)) {
            return $this->failure('DAILY_BUDGET_RESERVED', 'Cadangan kuota harian IDX Edge dilindungi.');
        }

        $rpm = max(1, (int) config('services.idx_edge.requests_per_minute', 10));
        if (! RateLimiter::attempt('idx-edge:provider-rpm', $rpm, static fn (): bool => true, 60)) {
            return $this->failure('LOCAL_RATE_LIMIT', 'Batas permintaan lokal IDX Edge tercapai.');
        }

        $startedAt = microtime(true);
        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->timeout(max(1, (int) config('services.idx_edge.timeout', 10)))
                ->get(rtrim((string) config('services.idx_edge.base_url'), '/').$path, $query);
        } catch (ConnectionException $exception) {
            return $this->failure('PROVIDER_CONNECTION_FAILED', $this->redact($exception->getMessage(), $apiKey), $startedAt);
        } catch (Throwable $exception) {
            return $this->failure('PROVIDER_REQUEST_FAILED', $this->redact($exception->getMessage(), $apiKey), $startedAt);
        }

        $quotaCost = max(0, (int) ($response->header('X-Quota-Cost') ?? 1));
        Cache::put($usageKey, $used + $quotaCost, now('Asia/Jakarta')->endOfDay());

        if (! $response->successful()) {
            $retryAfter = $response->header('Retry-After');
            $message = (string) ($response->json('message') ?? "IDX Edge mengembalikan HTTP {$response->status()}.");

            return array_merge(
                $this->failure("PROVIDER_HTTP_{$response->status()}", $this->redact($message, $apiKey), $startedAt),
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
            return $this->failure('INVALID_PROVIDER_SCHEMA', $this->redact($exception->getMessage(), $apiKey), $startedAt);
        }

        $result = [
            'success' => true,
            'provider' => 'IDX_EDGE',
            'data' => $data,
            'cache_hit' => false,
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
                'local_used' => $used + $quotaCost,
                'daily_budget' => $dailyBudget,
                'reserve' => $reserve,
            ],
        ];

        Cache::put($cacheKey, $result, $ttl);

        return $result;
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
    private function failure(string $errorType, string $message, ?float $startedAt = null): array
    {
        return [
            'success' => false,
            'provider' => 'IDX_EDGE',
            'error_type' => $errorType,
            'message' => $message,
            'cache_hit' => false,
            'latency_ms' => $startedAt === null ? 0 : (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function redact(string $message, string $apiKey): string
    {
        $redacted = $apiKey === '' ? $message : str_replace($apiKey, '[REDACTED]', $message);

        return (string) preg_replace('/\bsk_[A-Za-z0-9_-]{12,}\b/', '[REDACTED]', $redacted);
    }
}
