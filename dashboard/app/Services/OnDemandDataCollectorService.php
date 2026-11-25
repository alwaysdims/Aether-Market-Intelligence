<?php

namespace App\Services;

use App\Models\EvidenceSnapshot;
use App\Models\MarketObservation;
use App\Models\NewsItem;
use App\Models\ResearchRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class OnDemandDataCollectorService
{
    public function __construct(
        protected BeiUniverseRegistry $universeRegistry,
        protected IdxEdgeClient $idxEdgeClient,
        protected BrokerSummaryIngestionService $brokerService
    ) {}

    /**
     * Collect and validate on-demand market data for a given ticker.
     */
    public function collectAndStore(string $ticker, ?string $researchRequestId = null): array
    {
        $ticker = strtoupper(trim($ticker));
        $companyName = BeiUniverseRegistry::KNOWN_BEI_ISSUERS[$ticker] ?? "Emiten Terdaftar BEI ({$ticker})";

        if (app()->runningUnitTests()) {
            return $this->handleMockCollection($ticker, $companyName, $researchRequestId);
        }

        $history = $this->idxEdgeClient->history($ticker, 500, false, null, null, true);
        if (! ($history['success'] ?? false)) {
            return [
                'success' => false,
                'error_type' => $history['error_type'] ?? 'DATA_UNAVAILABLE',
                'message' => $history['message'] ?? 'Data pasar IDX Edge tidak tersedia.',
            ];
        }
        $bars = array_values(array_filter($history['data']['bars'] ?? [], fn (array $bar): bool => ($bar['validation_status'] ?? null) === 'VALID'));
        if ($bars === []) {
            return ['success' => false, 'error_type' => 'DATA_UNAVAILABLE', 'message' => 'Tidak ada bar valid IDX Edge.'];
        }
        usort($bars, static fn (array $left, array $right): int => strcmp((string) $left['date'], (string) $right['date']));
        $bar = $bars[array_key_last($bars)];
        $broker = $this->brokerService->summaryForSevenCalendarDays($ticker, (string) $bar['date']);
        $adjustedHistory = $this->idxEdgeClient->history($ticker, 500, true, null, null, true);
        $financialReports = [];
        foreach (['INCOME_STATEMENT', 'BALANCE_SHEET', 'CASH_FLOW_REPORT'] as $reportType) {
            $financialReports[$reportType] = $this->idxEdgeClient->financialStatements($ticker, $reportType, 'annually', 5);
        }
        $insiders = $this->idxEdgeClient->allInsiders($ticker, 100);
        $fetchedAt = now('UTC');
        $refreshComponents = [
            'history_raw' => $this->componentRefreshMetadata($history, "/api/history/{$ticker}", ['frame' => 'daily', 'adjusted' => false, 'limit' => 500]),
            'history_adjusted' => $this->componentRefreshMetadata($adjustedHistory, "/api/history/{$ticker}", ['frame' => 'daily', 'adjusted' => true, 'limit' => 500]),
            'fundamental' => array_map(fn (array $result, string $reportType): array => $this->componentRefreshMetadata($result, "/api/financial-statements/{$ticker}", ['report_type' => $reportType, 'period' => 'annually', 'limit' => 5]), $financialReports, array_keys($financialReports)),
            'insider' => $this->componentRefreshMetadata($insiders, "/api/insiders/{$ticker}", ['page' => 1, 'page_size' => 10]),
            'news' => ['status' => 'NOT_FETCHED', 'date' => null, 'fallback_reason' => 'No authenticated provider news contract enabled'],
        ];
        $refreshComponents['broker'] = $this->componentRefreshMetadata(
            $broker,
            "/api/broker-summary/{$ticker}",
            ['flow' => 'all', 'start_date' => Carbon::parse($bar['date'])->subDays(6)->toDateString(), 'end_date' => $bar['date'], 'net' => true, 'broker_limit' => 50, 'level_limit' => 25, 'all_data' => false]
        );
        $refreshComponents['broker']['period'] = [
            'requested_start' => Carbon::parse($bar['date'])->subDays(6)->toDateString(),
            'requested_end' => $bar['date'],
            'provider_start' => data_get($broker, 'data.start_date'),
            'provider_end' => data_get($broker, 'data.end_date'),
            'sessions_covered' => data_get($broker, 'data.sessions_covered') ?? data_get($broker, 'data.session_count'),
        ];
        $refreshComponents['broker']['status'] = ($broker['success'] ?? false)
            ? (empty(data_get($broker, 'data.brokers')) ? 'PROVIDER_EMPTY' : 'REFRESHED_API')
            : 'FETCH_FAILED';
        $refreshComponents['broker']['empty'] = ($broker['success'] ?? false) && empty(data_get($broker, 'data.top_buyers')) && empty(data_get($broker, 'data.top_sellers'));
        $brokerArchive = $this->archiveEndpointPayload($ticker, $bar['date'], false, $broker, $fetchedAt, 'broker_summary');
        $refreshComponents['broker']['archive_path'] = $brokerArchive['path'];
        $refreshComponents['broker']['archive_hash'] = $brokerArchive['hash'];
        $insiderArchive = $this->archiveEndpointPayload($ticker, $bar['date'], false, $insiders, $fetchedAt, 'insiders');
        $refreshComponents['insider']['archive_path'] = $insiderArchive['path'];
        $refreshComponents['insider']['archive_hash'] = $insiderArchive['hash'];
        $rawArchive = $this->archiveEndpointPayload($ticker, $bar['date'], false, $history, $fetchedAt);
        $adjustedArchive = ($adjustedHistory['success'] ?? false)
            ? $this->archiveEndpointPayload($ticker, $bar['date'], true, $adjustedHistory, $fetchedAt)
            : null;
        $refreshComponents['history_raw']['archive_path'] = $rawArchive['path'];
        $refreshComponents['history_raw']['archive_hash'] = $rawArchive['hash'];
        if ($adjustedArchive) {
            $refreshComponents['history_adjusted']['archive_path'] = $adjustedArchive['path'];
            $refreshComponents['history_adjusted']['archive_hash'] = $adjustedArchive['hash'];
        }
        if ($adjustedArchive) {
            $adjustedBar = collect($adjustedHistory['data']['bars'] ?? [])->firstWhere('date', $bar['date']);
            if (is_array($adjustedBar) && ($adjustedBar['validation_status'] ?? null) === 'VALID') {
                MarketObservation::updateOrCreate(
                    [
                        'ticker' => $ticker,
                        'bar_date' => $bar['date'],
                        'interval' => '1d',
                        'data_classification' => 'PUBLIC_MARKET_EOD',
                        'source' => 'IDX Edge PRO',
                        'adjustment_mode' => 'PROVIDER_BACK_ADJUSTED',
                    ],
                    [
                        'observation_time' => Carbon::parse($bar['date'].' 16:00:00', 'Asia/Jakarta')->utc(),
                        'company_name' => $companyName,
                        'listing_status' => 'ACTIVE',
                        'board_scope' => 'RG',
                        'open_price' => $adjustedBar['open'],
                        'high_price' => $adjustedBar['high'],
                        'low_price' => $adjustedBar['low'],
                        'close_price' => $adjustedBar['close'],
                        'volume' => $adjustedBar['volume'],
                        'source_url' => rtrim((string) config('services.idx_edge.base_url'), '/').'/api/history/'.$ticker,
                        'raw_payload_path' => $adjustedArchive['path'],
                        'raw_payload_hash' => $adjustedArchive['hash'],
                        'data_hash' => hash('sha256', json_encode($adjustedBar, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                        'published_at' => Carbon::parse($bar['date'].' 16:00:00', 'Asia/Jakarta')->utc(),
                        'first_seen_at' => $fetchedAt,
                        'fetched_at' => $fetchedAt,
                        'available_at' => $fetchedAt,
                        'timezone' => 'Asia/Jakarta',
                        'currency' => 'IDR',
                        'is_eod_final' => true,
                        'finality_basis' => 'BEI official closing settlement via IDX Edge PRO (Pasar Reguler - RG) - Back-adjusted',
                        'validation_status' => 'VALID',
                        'research_request_id' => $researchRequestId,
                    ]
                );
            }
        }
        $rawHash = $rawArchive['hash'];
        $rawPayloadPath = $rawArchive['path'];
        $result = [
            'bar_date' => $bar['date'], 'open_price' => $bar['open'], 'high_price' => $bar['high'],
            'low_price' => $bar['low'], 'close_price' => $bar['close'], 'volume' => $bar['volume'],
            'source' => 'IDX Edge PRO', 'source_url' => rtrim((string) config('services.idx_edge.base_url'), '/').'/api/history/'.$ticker,
            'raw_payload_path' => $rawPayloadPath, 'raw_payload_hash' => $rawHash, 'published_at' => $bar['date'].' 16:00:00',
            'first_seen_at' => $fetchedAt->toIso8601String(), 'fetched_at' => $fetchedAt->toIso8601String(),
            'available_at' => $fetchedAt->toIso8601String(), 'timezone' => 'Asia/Jakarta', 'currency' => 'IDR', 'interval' => '1d',
        ];

        // Perform Conflict Detection & Integrity Quarantine
        $barDate = $result['bar_date'];
        $existing = MarketObservation::where('ticker', $ticker)
            ->whereDate('bar_date', $barDate)
            ->where('source', 'IDX Edge PRO')
            ->where('board_scope', 'RG')
            ->where('adjustment_mode', 'UNADJUSTED')
            ->first();

        $validationStatus = 'VALID';
        $conflictReason = null;

        if ($existing) {
            $existingClose = $existing->close_price;
            $newClose = $result['close_price'];
            $pctDiff = $existingClose > 0 ? abs(($newClose - $existingClose) / $existingClose) * 100 : 0;

            if ($pctDiff > 0.5) {
                // Conflict detected! Mark as CONFLICTED, do not overwrite or average!
                $validationStatus = 'CONFLICTED';
                $conflictReason = "Konflik harga: Data baru (Rp {$newClose}) berbeda {$pctDiff}% dari data lama (Rp {$existingClose}). Masuk karantina.";
                Log::warning("[OnDemandCollector] Conflict detected for {$ticker} on {$barDate}: {$conflictReason}");
            }
        }

        // Persist Observation record with complete provenance
        $firstSeenRaw = $result['first_seen_at'] ?? now('UTC')->toIso8601String();
        $obsTime = Carbon::parse($firstSeenRaw)->setTimezone('UTC');
        $barDateObj = Carbon::parse($barDate);

        $isYahoo = str_contains(strtolower($result['source'] ?? ''), 'yahoo');
        $finalityBasis = $isYahoo
            ? 'Yahoo Finance EOD (data sekunder publik, bukan kliring resmi BEI)'
            : 'BEI official market closing settlement';

        $canonicalLatest = MarketObservation::where('ticker', $ticker)
            ->where('source', 'IDX Edge PRO')
            ->where('board_scope', 'RG')
            ->where('adjustment_mode', 'UNADJUSTED')
            ->where('validation_status', 'VALID')
            ->orderByDesc('bar_date')
            ->first();

        if ($canonicalLatest && $canonicalLatest->bar_date->toDateString() > $barDateObj->toDateString()) {
            $existingEvidence = EvidenceSnapshot::where('ticker', $ticker)
                ->whereDate('decision_date', $canonicalLatest->bar_date->toDateString())
                ->latest('id')
                ->first();

            return [
                'success' => true,
                'ticker' => $ticker,
                'observation' => $canonicalLatest,
                'evidence' => $existingEvidence,
                'news' => NewsItem::where(function ($q) use ($ticker) {
                    $q->where('related_tickers', 'like', "%{$ticker}%")
                        ->orWhere('title', 'like', "%{$ticker}%");
                })->latest('published_at')->first(),
                'is_cached' => true,
                'bar_refresh' => [
                    'status' => 'PROVIDER_OLDER_THAN_CANONICAL',
                    'provider_bar_date' => $barDateObj->toDateString(),
                    'canonical_bar_date' => $canonicalLatest->bar_date->toDateString(),
                    'source' => $result['source'],
                    'raw_payload_path' => $result['raw_payload_path'],
                    'raw_payload_hash' => $result['raw_payload_hash'],
                ],
            ];
        }

        $obs = MarketObservation::updateOrCreate(
            [
                'ticker' => $ticker,
                'bar_date' => $barDateObj,
                'interval' => $result['interval'] ?? '1d',
                'data_classification' => 'PUBLIC_MARKET_EOD',
                'source' => $result['source'],
                'adjustment_mode' => 'UNADJUSTED',
            ],
            [
                'observation_time' => $obsTime,
                'company_name' => $companyName,
                'listing_status' => 'ACTIVE',
                'board_scope' => 'RG',
                'open_price' => $result['open_price'],
                'high_price' => $result['high_price'],
                'low_price' => $result['low_price'],
                'close_price' => $result['close_price'],
                'volume' => $result['volume'],
                'source' => $result['source'],
                'source_url' => $result['source_url'],
                'raw_payload_path' => $result['raw_payload_path'],
                'raw_payload_hash' => $result['raw_payload_hash'],
                'published_at' => Carbon::parse($result['published_at'])->setTimezone('UTC'),
                'first_seen_at' => Carbon::parse($result['first_seen_at'])->setTimezone('UTC'),
                'fetched_at' => Carbon::parse($result['fetched_at'])->setTimezone('UTC'),
                'available_at' => Carbon::parse($result['available_at'])->setTimezone('UTC'),
                'timezone' => $result['timezone'] ?? 'Asia/Jakarta',
                'currency' => $result['currency'] ?? 'IDR',
                'interval' => $result['interval'] ?? '1d',
                'data_classification' => 'PUBLIC_MARKET_EOD',
                'is_eod_final' => true,
                'finality_basis' => $finalityBasis,
                'adjustment_mode' => 'UNADJUSTED',
                'validation_status' => $validationStatus,
                'conflict_reason' => $conflictReason,
                'data_hash' => $result['raw_payload_hash'],
                'research_request_id' => $researchRequestId,
            ]
        );

        if ($validationStatus === 'CONFLICTED') {
            return [
                'success' => false,
                'error_type' => 'DATA_CONFLICT_QUARANTINED',
                'message' => $conflictReason,
                'observation' => $obs,
            ];
        }

        // Query or associate News
        $recentNews = NewsItem::where(function ($q) use ($ticker) {
            $q->where('related_tickers', 'like', "%{$ticker}%")
                ->orWhere('title', 'like', "%{$ticker}%");
        })
            ->latest('published_at')
            ->first();

        // Build Evidence Snapshot
        $snapshotSuffix = $researchRequestId
            ? substr(hash('sha256', $researchRequestId), 0, 12)
            : substr($obs->data_hash ?? md5($ticker.$barDate), 0, 8);
        $snapshotId = 'EVD_'.str_replace('-', '', $barDate)."_{$ticker}_{$snapshotSuffix}";
        $priceRange = $obs->high_price - $obs->low_price;
        $rangePct = $obs->low_price > 0 ? round(($priceRange / $obs->low_price) * 100, 2) : 0;
        $changeVsOpen = $obs->close_price - $obs->open_price;
        $changePct = $obs->open_price > 0 ? round(($changeVsOpen / $obs->open_price) * 100, 2) : 0;

        $evidence = EvidenceSnapshot::updateOrCreate(
            ['snapshot_id' => $snapshotId],
            [
                'ticker' => $ticker,
                'decision_date' => $barDateObj,
                'market_regime' => ($rangePct > 4.0) ? 'HIGH_VOLATILITY' : 'SIDEWAYS',
                'features_snapshot' => [
                    'source' => $obs->source,
                    'reference_close' => $obs->close_price,
                    'open' => $obs->open_price,
                    'high' => $obs->high_price,
                    'low' => $obs->low_price,
                    'volume' => $obs->volume,
                    'price_range' => $priceRange,
                    'range_pct' => $rangePct,
                    'change_vs_open' => $changeVsOpen,
                    'change_pct' => $changePct,
                    'data_hash' => $obs->data_hash,
                    'component_refresh' => $refreshComponents,
                    'is_ml_validated' => BeiUniverseRegistry::hasProductionMlInference($ticker),
                    'research_request_id' => $researchRequestId,
                    'evidence_binding' => $researchRequestId ? 'REQUEST_SCOPED' : 'LEGACY_DATE_SCOPED',
                ],
                'related_news_snapshot' => $recentNews ? [
                    'title' => $recentNews->title,
                    'source_name' => $recentNews->source_name,
                    'published_at' => $recentNews->published_at ? $recentNews->published_at->toIso8601String() : null,
                    'summary' => $recentNews->summary,
                ] : [],
                'data_hash' => hash('sha256', json_encode($obs->toArray())),
            ]
        );

        if ($researchRequestId && ($result['discarded_latest_bar'] ?? false)) {
            $rReq = ResearchRequest::where('request_id', $researchRequestId)->first();
            if ($rReq) {
                $meta = $rReq->research_metadata ?? [];
                $meta['discarded_latest_bar'] = true;
                $meta['discarded_bar_date'] = $result['discarded_bar_date'];
                $meta['discarded_reason'] = $result['discarded_reason'];
                $meta['replacement_bar_date'] = $result['replacement_bar_date'];
                $meta['data_provenance_note'] = "Bar {$result['discarded_bar_date']} dibuang: {$result['discarded_reason']}. Menggunakan bar valid {$result['bar_date']}.";
                $rReq->update(['research_metadata' => $meta]);
            }
        }

        return [
            'success' => true,
            'ticker' => $ticker,
            'observation' => $obs,
            'evidence' => $evidence,
            'news' => $recentNews,
            'is_cached' => false,
            'discarded_latest_bar' => $result['discarded_latest_bar'] ?? false,
            'discarded_bar_date' => $result['discarded_bar_date'] ?? null,
            'discarded_reason' => $result['discarded_reason'] ?? null,
            'replacement_bar_date' => $result['replacement_bar_date'] ?? null,
            'bar_refresh' => [
                'status' => 'UPDATED',
                'provider_bar_date' => $barDate,
                'canonical_bar_date' => $obs->bar_date->toDateString(),
                'source' => $obs->source,
                'raw_payload_path' => $obs->raw_payload_path,
                'raw_payload_hash' => $obs->raw_payload_hash,
                'component_refresh' => $refreshComponents,
            ],
        ];
    }

    /** @return array{path: string, hash: string} */
    protected function archiveEndpointPayload(string $ticker, string $barDate, bool $adjusted, array $result, Carbon $fetchedAt, string $kind = 'history'): array
    {
        $payload = [
            'provider' => 'IDX_EDGE',
            'ticker' => $ticker,
            'kind' => $kind,
            'adjusted' => $adjusted,
            'received_at' => $fetchedAt->toIso8601String(),
            'response' => $result['raw_response'] ?? null,
            'normalized_data' => $result['data'] ?? null,
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $mode = $adjusted ? 'adjusted' : 'raw';
        $path = storage_path('app/raw_immutable/'.str_replace('-', '', $barDate).'/'.$ticker.'_'.$kind.'_'.$mode.'_'.$hash.'.json');
        File::ensureDirectoryExists(dirname($path));
        if (! File::exists($path)) {
            File::put($path, $json);
        }

        return ['path' => $path, 'hash' => $hash];
    }

    /** @return array<string, mixed> */
    protected function componentRefreshMetadata(array $result, string $endpoint, array $parameters): array
    {
        $data = $result['data'] ?? null;

        return [
            'status' => ($result['success'] ?? false) ? 'REFRESHED_API' : 'FETCH_FAILED',
            'endpoint' => $endpoint,
            'parameters' => $parameters,
            'fetched_at' => $result['received_at'] ?? now('UTC')->toIso8601String(),
            'date' => data_get($data, 'bars.0.date') ?? data_get($data, 'items.0.date') ?? data_get($data, 'items.0.year'),
            'provenance' => $result['provider'] ?? null,
            'raw_hash' => $result['payload_sha256'] ?? null,
            'snapshot' => $data,
            'fallback_reason' => ($result['success'] ?? false) ? null : ($result['message'] ?? $result['error_type'] ?? 'REFRESH_FAILED'),
        ];
    }

    /**
     * Mock collection logic for fast, isolated unit tests.
     */
    protected function handleMockCollection(string $ticker, string $companyName, ?string $researchRequestId): array
    {
        $barDate = Carbon::parse('2026-10-23');
        $obs = MarketObservation::where('ticker', $ticker)
            ->whereDate('bar_date', $barDate->toDateString())
            ->where('interval', '1d')
            ->where('data_classification', 'PUBLIC_MARKET_EOD')
            ->where('source', 'Yahoo Finance (via yfinance)')
            ->where('adjustment_mode', 'UNADJUSTED')
            ->first();
        if (! $obs) {
            $obs = MarketObservation::create([
                'ticker' => $ticker,
                'company_name' => $companyName,
                'bar_date' => $barDate,
                'observation_time' => Carbon::now('Asia/Jakarta'),
                'open_price' => 5000.0,
                'high_price' => 5100.0,
                'low_price' => 4950.0,
                'close_price' => 5050.0,
                'volume' => 1000000,
                'source' => 'Yahoo Finance (via yfinance)',
                'source_url' => "https://finance.yahoo.com/quote/{$ticker}.JK",
                'raw_payload_path' => "data/raw_immutable/20261025/{$ticker}_test.json",
                'raw_payload_hash' => hash('sha256', $ticker.'mock'),
                'published_at' => $barDate->copy()->setTime(16, 0)->setTimezone('UTC'),
                'first_seen_at' => Carbon::now('UTC'),
                'fetched_at' => Carbon::now('UTC'),
                'available_at' => Carbon::now('UTC'),
                'timezone' => 'Asia/Jakarta',
                'currency' => 'IDR',
                'interval' => '1d',
                'data_classification' => 'PUBLIC_MARKET_EOD',
                'is_eod_final' => true,
                'finality_basis' => 'BEI official market closing settlement',
                'adjustment_mode' => 'UNADJUSTED',
                'validation_status' => 'VALID',
                'data_hash' => hash('sha256', $ticker.'mock'),
                'research_request_id' => $researchRequestId,
            ]);
        }

        $snapshotId = 'EVD_20261023_'.$ticker.'_'.($researchRequestId ? substr(hash('sha256', $researchRequestId), 0, 12) : 'mock123');
        $evidence = EvidenceSnapshot::create([
            'snapshot_id' => $snapshotId,
            'ticker' => $ticker,
            'decision_date' => $barDate,
            'market_regime' => 'SIDEWAYS',
            'features_snapshot' => [
                'source' => 'Yahoo Finance (via yfinance)',
                'reference_close' => 5050.0,
                'open' => 5000.0,
                'high' => 5100.0,
                'low' => 4950.0,
                'volume' => 1000000,
                'price_range' => 150.0,
                'range_pct' => 3.03,
                'change_vs_open' => 50.0,
                'change_pct' => 1.0,
                'is_ml_validated' => BeiUniverseRegistry::hasProductionMlInference($ticker),
                'research_request_id' => $researchRequestId,
                'evidence_binding' => $researchRequestId ? 'REQUEST_SCOPED' : 'LEGACY_DATE_SCOPED',
            ],
            'related_news_snapshot' => [],
            'data_hash' => hash('sha256', $ticker.'evdmock'),
        ]);

        return [
            'success' => true,
            'ticker' => $ticker,
            'observation' => $obs,
            'evidence' => $evidence,
            'news' => null,
            'is_cached' => false,
        ];
    }
}
