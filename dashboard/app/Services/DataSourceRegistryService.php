<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\MarketObservation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataSourceRegistryService
{
    /**
     * Idempotently sync data sources according to current verified architecture.
     *
     * @return array{created: int, updated: int, total: int}
     */
    public function syncRegistry(): array
    {
        $definitions = [
            [
                'name' => 'IDX Edge Histori EOD Reguler',
                'type' => 'market_data',
                'data_classification' => 'end_of_day',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/history/{code}',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'VALID',
                'quarantine_reason' => null,
                'metadata' => [
                    'board' => 'RG',
                    'frame' => 'daily',
                    'adjusted_supported' => true,
                    'role' => 'Primary EOD Source',
                    'canary_status' => 'PASSED',
                ],
            ],
            [
                'name' => 'IDX Edge Quote Intraday (MIXED_BOARD_UNSEPARABLE)',
                'type' => 'market_data',
                'data_classification' => 'real_time',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/price/{code}',
                'status' => 'quarantined',
                'is_enabled' => false,
                'health' => 'quarantined',
                'data_quality' => 'MIXED_BOARD_UNSEPARABLE',
                'quarantine_reason' => 'Snapshot mencampur Pasar Reguler (RG), Negosiasi (NG), dan Tunai (TN) tanpa field board_scope atau filter server-side. Dikeluarkan dari seluruh jalur sinyal dan harga RG.',
                'metadata' => [
                    'board_scope' => 'MIXED_BOARD_UNSEPARABLE',
                    'quarantined_from' => 'regular_chart_and_signals',
                    'issue' => 'board_contamination_NG_TN',
                    'role' => 'Canonical Intraday REST Snapshot (Quarantined)',
                    'reference_count' => 'derived_from_foreign_keys',
                ],
            ],
            [
                'name' => 'IDX Edge WebSocket Running Trade',
                'type' => 'real_time_stream',
                'data_classification' => 'real_time',
                'base_url' => 'wss://stock.arjum.com',
                'endpoint' => '/ws/running-trade',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'active_unverified_board',
                'data_quality' => 'BOARD_SCOPE_UNVERIFIED',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'Live Trade Event Stream (Single Owner)',
                    'board_scope' => 'BOARD_SCOPE_UNVERIFIED',
                    'board_rg_guaranteed' => false,
                    'has_official_trade_id' => true,
                    'has_event_timestamp' => true,
                    'dedup_key' => 'tn (Bounded Ring Buffer 2000)',
                    'screening_status' => 'BLOCKED_EXTERNAL_CAPABILITY',
                ],
            ],
            [
                'name' => 'IDX Edge Broker Summary',
                'type' => 'broker_summary',
                'data_classification' => 'end_of_day',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/broker-summary/{code}',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'VERIFIED_ON_SAMPLE',
                'quarantine_reason' => null,
                'metadata' => [
                    'flow_filters' => ['all', 'F', 'D'],
                    'board' => 'RG_VERIFIED_ON_SAMPLE',
                    'volume_unit' => 'SHARES',
                    'currency' => 'IDR',
                    'role' => 'Broker Summary (Pasar Reguler - RG)',
                ],
            ],
            [
                'name' => 'IDX Edge Ownership Snapshot',
                'type' => 'ownership',
                'data_classification' => 'monthly_snapshot',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/ownership/stock?code={code}',
                'status' => 'inactive',
                'is_enabled' => false,
                'health' => 'unknown',
                'data_quality' => 'UNVERIFIED',
                'quarantine_reason' => 'Provider ownership schema and semantics require authenticated runtime verification before enablement.',
                'metadata' => [
                    'threshold' => '>=1%',
                    'month_year_supported' => true,
                    'change_pct_mom' => 'NOT_DOCUMENTED',
                    'role' => 'Ownership Monthly Snapshot',
                ],
            ],
            [
                'name' => 'IDX Edge Fundamental',
                'type' => 'fundamental',
                'data_classification' => 'historical',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/financial-statements/{code}',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'VERIFIED_ON_SAMPLE',
                'quarantine_reason' => null,
                'metadata' => [
                    'statements' => ['INCOME_STATEMENT', 'BALANCE_SHEET', 'CASH_FLOW_REPORT'],
                    'currency' => 'IDR',
                    'unit' => 'IDR',
                    'role' => 'Fundamental Statements (Canary Verified)',
                ],
            ],
            [
                'name' => 'Yahoo Finance Daily EOD (Arsip Historis)',
                'type' => 'market_data',
                'data_classification' => 'end_of_day',
                'base_url' => 'https://query1.finance.yahoo.com',
                'endpoint' => '/v8/finance/chart/{ticker}',
                'status' => 'inactive',
                'is_enabled' => false,
                'health' => 'unknown',
                'data_quality' => 'HISTORICAL_ARCHIVE_ONLY',
                'quarantine_reason' => 'Jalur operasional Yahoo Finance dinonaktifkan permanen. Dipertahankan murni sebagai arsip read-only.',
                'metadata' => [
                    'role' => 'Historical Archive Only (Non-operational)',
                ],
            ],
            [
                'name' => 'LKBN ANTARA Berita Finansial',
                'type' => 'news',
                'data_classification' => 'rss_feed',
                'base_url' => 'https://www.antaranews.com',
                'endpoint' => '/rss/ekonomi-finansial.xml',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'VALID',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'Primary Operational News Feed (LKBN ANTARA)',
                ],
            ],
            [
                'name' => 'IDX Official Announcements & News (Arsip Dokumen)',
                'type' => 'news_archive',
                'data_classification' => 'delayed',
                'base_url' => 'https://www.idx.co.id',
                'endpoint' => '/primary/ListedCompany/GetAnnouncement',
                'status' => 'inactive',
                'is_enabled' => false,
                'health' => 'unknown',
                'data_quality' => 'HISTORICAL_ARCHIVE_ONLY',
                'quarantine_reason' => 'Arsip pengumuman emiten BEI. Bukan feed berita live operasional.',
                'metadata' => [
                    'role' => 'Official Issuer Announcements Archive (Cold)',
                ],
            ],
            [
                'name' => 'Index Alpha Broker Summary',
                'type' => 'broker_summary',
                'data_classification' => 'end_of_day',
                'base_url' => 'https://api.indexalpha.id',
                'endpoint' => '/stocks/broker-summary',
                'status' => 'inactive',
                'is_enabled' => false,
                'health' => 'unknown',
                'data_quality' => 'DEPRECATED',
                'quarantine_reason' => 'Dinonaktifkan total; seluruh fitur digantikan IDX Edge PRO',
                'metadata' => [
                    'role' => 'Legacy Broker Summary (Disabled)',
                ],
            ],
            [
                'name' => 'IDX Edge Market Cap & Saham Beredar',
                'type' => 'market_data',
                'data_classification' => 'end_of_day',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/market-cap',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'VERIFIED_ON_SAMPLE',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'Universe Discovery and Market Cap Registry',
                ],
            ],
            [
                'name' => 'IDX Edge Akumulasi Broker Historis',
                'type' => 'broker_summary',
                'data_classification' => 'end_of_day',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/broker-accumulation/{code}',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'PROVIDER_SUPPLIED',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'Historical Broker Flow Points (Pasar Reguler - RG)',
                ],
            ],
            [
                'name' => 'IDX Edge Pencarian Saham',
                'type' => 'search',
                'data_classification' => 'metadata',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/search',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'VERIFIED_ON_SAMPLE',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'Ticker Autocomplete & Discovery',
                ],
            ],
            [
                'name' => 'IDX Edge Harga IHSG',
                'type' => 'market_data',
                'data_classification' => 'snapshot',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/price/IHSG',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'VERIFIED_ON_SAMPLE',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'IHSG Index Snapshot',
                    'board_scope' => 'INDEX',
                    'freshness_basis' => 'provider_data_ts_or_source_date',
                ],
            ],
            [
                'name' => 'IDX Edge Movers Harian',
                'type' => 'market_ranking',
                'data_classification' => 'snapshot',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/movers?by=chg&n=10',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'SCHEMA_VALIDATED_ON_SAMPLE',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'Daily Movers by Change',
                    'board_scope' => 'UNVERIFIED',
                    'volume_unit' => 'PROVIDER_LOT_FIELD_UNCONVERTED',
                ],
            ],
            [
                'name' => 'IDX Edge Insider Transactions',
                'type' => 'corporate_action',
                'data_classification' => 'historical',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/insiders/{code}',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'SCHEMA_VALIDATED_ON_SAMPLE',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'Insider Transaction Disclosure',
                    'board_scope' => 'UNVERIFIED',
                    'unit' => 'NOT_DOCUMENTED',
                    'calculation_usage' => 'BLOCKED_UNTIL_UNIT_VALIDATED',
                ],
            ],
            [
                'name' => 'IDX Edge Chart 60 Seconds',
                'type' => 'market_data',
                'data_classification' => 'intraday',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/chart/{code}?interval=300',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'SCHEMA_VALIDATED_ON_SAMPLE',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'Intraday Chart 300 Seconds',
                    'board_scope' => 'UNVERIFIED',
                    'volume_unit' => 'NOT_DOCUMENTED',
                    'interval_contract' => 'REQUESTED_300_PROVIDER_NATIVE_15',
                    'signal_usage' => 'BLOCKED_DISPLAY_ONLY',
                ],
            ],
        ];

        $created = 0;
        $updated = 0;

        // Resolve connectors by provider + normalized endpoint + role. IDs are historical data, not identity.

        DB::transaction(function () use ($definitions, &$created, &$updated): void {
            foreach ($definitions as $def) {
                $identity = $this->identityKey($def);
                $existing = DataSource::query()
                    ->where('base_url', $def['base_url'])
                    ->where('endpoint', $def['endpoint'])
                    ->where('type', $def['type'])
                    ->get()
                    ->first(fn (DataSource $source): bool => $this->identityKey([
                        'base_url' => $source->base_url,
                        'endpoint' => $source->endpoint,
                        'type' => $source->type,
                        'metadata' => $source->metadata ?? [],
                    ]) === $identity);

                if (! $existing) {
                    $nameOwner = DataSource::where('name', $def['name'])->first();
                    if ($nameOwner && $this->identityKey([
                        'base_url' => $nameOwner->base_url,
                        'endpoint' => $nameOwner->endpoint,
                        'type' => $nameOwner->type,
                        'metadata' => $nameOwner->metadata ?? [],
                    ]) !== $identity) {
                        throw new \LogicException("Source name collision has different connector identity: {$def['name']}");
                    }
                    DataSource::create($def);
                    $created++;
                } else {
                    $existing->update([
                        'name' => $def['name'],
                        'type' => $def['type'],
                        'data_classification' => $def['data_classification'],
                        'base_url' => $def['base_url'],
                        'endpoint' => $def['endpoint'],
                        'status' => $def['status'],
                        'is_enabled' => $def['is_enabled'],
                        'health' => $def['health'],
                        'data_quality' => $def['data_quality'],
                        'quarantine_reason' => $def['quarantine_reason'],
                        'metadata' => $def['metadata'],
                    ]);
                    $updated++;
                }
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => DataSource::count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function identityKey(array $definition): string
    {
        $baseUrl = rtrim(strtolower(trim((string) ($definition['base_url'] ?? ''))), '/');
        $endpoint = '/'.ltrim(strtolower(trim((string) ($definition['endpoint'] ?? ''))), '/');
        $role = (string) ($definition['metadata']['role'] ?? '');

        return hash('sha256', json_encode([
            'provider' => $baseUrl,
            'endpoint' => $endpoint,
            'type' => (string) ($definition['type'] ?? ''),
            'role' => $role,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return Collection<int, DataSource> */
    public function all(): Collection
    {
        return DataSource::orderBy('type')->orderBy('name')->get();
    }

    public function isEndpointEnabled(string $endpointKey): bool
    {
        $mapping = [
            'history' => 'IDX Edge Histori EOD Reguler',
            'price' => 'IDX Edge Quote Intraday (MIXED_BOARD_UNSEPARABLE)',
            'broker' => 'IDX Edge Broker Summary',
            'fundamental' => 'IDX Edge Fundamental',
            'market_cap' => 'IDX Edge Market Cap & Saham Beredar',
            'broker_accumulation' => 'IDX Edge Akumulasi Broker Historis',
            'search' => 'IDX Edge Pencarian Saham',
            'price_ihsg' => 'IDX Edge Harga IHSG',
            'movers' => 'IDX Edge Movers Harian',
            'insiders' => 'IDX Edge Insider Transactions',
            'chart' => 'IDX Edge Chart 60 Seconds',
            'chart_ihsg' => 'IDX Edge Chart 60 Seconds',
        ];

        $sourceName = $mapping[$endpointKey] ?? null;
        if (! $sourceName) {
            return false;
        }

        if (! Schema::hasTable('data_sources')) {
            return (bool) config("services.idx_edge.endpoints.{$endpointKey}", false);
        }

        $source = DataSource::where('name', $sourceName)->first();

        if ($source === null) {
            return (bool) config("services.idx_edge.endpoints.{$endpointKey}", false);
        }

        // Strict multi-factor authorization check:
        // 1. Must be enabled by administrator
        if (! $source->is_enabled) {
            return false;
        }

        // 2. Must be actively operational (not disabled or archived)
        if ($source->status !== 'active') {
            return false;
        }

        // 3. Quarantine check: quarantined sources (e.g. quote intraday contaminated by NG trades) are strictly rejected
        if ($source->quarantine_reason !== null || $source->health === 'quarantined' || $endpointKey === 'price') {
            return false;
        }

        // 4. Validation & Provenance: display-only rankings may use a schema-validated sample.
        if ($endpointKey === 'movers' && $source->data_quality === 'SCHEMA_VALIDATED_ON_SAMPLE') {
            return true;
        }

        return in_array($source->data_quality, ['VALID', 'VERIFIED_ON_SAMPLE', 'PROVIDER_SUPPLIED'], true);
    }

    /**
     * Determine runtime effective enabled state and reasons.
     *
     * @return array{effective_enabled: bool, badge: string, label: string, reason: string}
     */
    public function getEffectiveStatus(DataSource $source): array
    {
        $hasKey = (string) config('services.idx_edge.api_key', '') !== '';

        if (str_starts_with($source->name, 'IDX Edge')) {
            if (! $hasKey) {
                return [
                    'effective_enabled' => false,
                    'badge' => 'BLOCKED_NO_KEY',
                    'label' => 'DIBLOKIR (KREDENSIAL KOSONG)',
                    'reason' => 'Kredensial API key IDX Edge kosong atau belum terpasang di runtime.',
                ];
            }

            if ($source->status === 'quarantined' || in_array($source->data_quality, ['QUARANTINED', 'MIXED_BOARD_UNSEPARABLE'], true)) {
                return [
                    'effective_enabled' => false,
                    'badge' => 'QUARANTINED',
                    'label' => 'DIKARANTINA (OFF-LIMITS)',
                    'reason' => $source->quarantine_reason ?? 'Dilarang digunakan karena anomali data.',
                ];
            }

            if ($source->status === 'inactive' || $source->data_quality === 'NOT_VERIFIED') {
                return [
                    'effective_enabled' => false,
                    'badge' => 'PENDING_CANARY',
                    'label' => 'DITAHAN (BELUM TERVERIFIKASI)',
                    'reason' => $source->quarantine_reason ?? 'Belum diverifikasi pada sampel data.',
                ];
            }

            if ($source->is_enabled && $source->status === 'active') {
                $label = 'SIAP (TERKONTROL)';
                $reason = 'Tervalidasi pada sampel operasional. Diizinkan untuk sinkronisasi terkelola.';
                if (str_contains($source->name, 'Broker Summary')) {
                    $label = 'SIAP (TERKONTROL BROKER RG)';
                    $reason = 'Tervalidasi pada sampel (Pasar Reguler - RG). Kuota dan rate limiter dihormati.';
                } elseif (str_contains($source->name, 'Fundamental')) {
                    $label = 'SIAP (TERKONTROL FUNDAMENTAL)';
                    $reason = 'Tervalidasi pada laporan keuangan emiten resmi (Income/Balance/CashFlow).';
                } elseif (str_contains($source->name, 'Akumulasi Broker Historis')) {
                    $label = 'SIAP (FLOW HISTORIS RG)';
                    $reason = 'Tersedia dari provider (data time-series akumulasi broker RG).';
                } elseif (str_contains($source->name, 'Histori')) {
                    $label = 'SIAP (OPERASIONAL EOD)';
                    $reason = 'Sumber primer EOD terverifikasi (nominal unadjusted & provider back-adjusted).';
                }

                return [
                    'effective_enabled' => true,
                    'badge' => 'READY_CONTROLLED',
                    'label' => $label,
                    'reason' => $reason,
                ];
            }
        }

        if (str_contains($source->name, 'Yahoo Finance')) {
            return [
                'effective_enabled' => false,
                'badge' => 'HISTORICAL_ARCHIVE',
                'label' => 'ARSIP HISTORIS (NON-OPERASIONAL)',
                'reason' => 'Dinonaktifkan total dari seluruh jalur pengambilan aktif. Dipertahankan murni sebagai arsip audit historis.',
            ];
        }

        if (str_contains($source->name, 'LKBN ANTARA')) {
            return [
                'effective_enabled' => true,
                'badge' => 'ACTIVE_NEWS_FEED',
                'label' => 'AKTIF (FEED BERITA RESMI)',
                'reason' => 'Feed berita resmi ekonomi & finansial operasional aktif.',
            ];
        }

        if (str_contains($source->name, 'IDX Official Announcements')) {
            return [
                'effective_enabled' => false,
                'badge' => 'HISTORICAL_ARCHIVE',
                'label' => 'ARSIP PENGUMUMAN (COLD)',
                'reason' => 'Arsip pengumuman emiten BEI. Bukan feed berita live operasional.',
            ];
        }

        if (str_contains($source->name, 'Index Alpha')) {
            return [
                'effective_enabled' => false,
                'badge' => 'DEPRECATED_LEGACY',
                'label' => 'DEPRECATED (NONAKTIF)',
                'reason' => 'Dinonaktifkan permanen; seluruh fungsi digantikan oleh IDX Edge PRO.',
            ];
        }

        if ($source->status === 'inactive') {
            return [
                'effective_enabled' => false,
                'badge' => 'DISABLED_LEGACY',
                'label' => 'NONAKTIF',
                'reason' => $source->quarantine_reason ?? 'Konektor sumber data tidak aktif.',
            ];
        }

        return [
            'effective_enabled' => (bool) $source->is_enabled,
            'badge' => $source->is_enabled ? 'ACTIVE' : 'INACTIVE',
            'label' => $source->is_enabled ? 'AKTIF' : 'NONAKTIF',
            'reason' => 'Status sumber data mengikuti konfigurasi database.',
        ];
    }

    /**
     * Execute controlled automated EOD history synchronization for 1 ticker.
     *
     * @return array<string, mixed>
     */
    public function syncControlledEodHistory(string $ticker, int $limit = 20): array
    {
        $ticker = strtoupper(trim($ticker));
        $source = DataSource::where('name', 'IDX Edge Histori EOD Reguler')->first();
        if ($source) {
            $source->update(['last_attempt_at' => now('UTC')]);
        }

        $effective = $source ? $this->getEffectiveStatus($source) : ['effective_enabled' => false, 'reason' => 'Source not registered'];
        if (! $effective['effective_enabled']) {
            if ($source) {
                $source->update([
                    'last_error_at' => now('UTC'),
                    'last_error_message' => $effective['reason'],
                    'health' => 'degraded',
                ]);
            }

            return [
                'success' => false,
                'error_type' => 'SYNC_BLOCKED',
                'message' => $effective['reason'],
            ];
        }

        $client = app(IdxEdgeClient::class);
        $fetch = $client->history($ticker, $limit, true);

        if (! $fetch['success'] || empty($fetch['data']['bars'])) {
            if ($source) {
                $source->update([
                    'last_error_at' => now('UTC'),
                    'last_error_message' => $fetch['message'] ?? 'Gagal mengambil histori.',
                    'health' => 'degraded',
                ]);
            }

            return [
                'success' => false,
                'error_type' => $fetch['error_type'] ?? 'FETCH_FAILED',
                'message' => $fetch['message'] ?? 'Gagal mengambil histori.',
            ];
        }

        $syncedBars = 0;
        $latestObs = null;

        foreach ($fetch['data']['bars'] as $bar) {
            $date = $bar['date'];
            $dataHash = hash('sha256', "{$ticker}|{$date}|{$bar['open']}|{$bar['high']}|{$bar['low']}|{$bar['close']}|{$bar['volume']}|IDX Edge PRO");

            $obs = MarketObservation::updateOrCreate(
                [
                    'ticker' => $ticker,
                    'bar_date' => $date,
                    'interval' => '1d',
                    'data_classification' => 'PUBLIC_MARKET_EOD',
                    'source' => 'IDX Edge PRO',
                ],
                [
                    'observation_time' => Carbon::parse("{$date} 16:00:00", 'Asia/Jakarta')->utc(),
                    'open_price' => $bar['open'],
                    'high_price' => $bar['high'],
                    'low_price' => $bar['low'],
                    'close_price' => $bar['close'],
                    'volume' => $bar['volume'],
                    'is_eod_final' => true,
                    'delay_minutes' => 0,
                    'data_hash' => $dataHash,
                    'timezone' => 'Asia/Jakarta',
                    'currency' => 'IDR',
                    'finality_basis' => 'BEI official closing settlement via IDX Edge PRO (Pasar Reguler - RG)',
                    'adjustment_mode' => 'PROVIDER_BACK_ADJUSTED',
                    'validation_status' => 'VALID',
                    'fetched_at' => now('UTC'),
                    'available_at' => now('UTC'),
                ]
            );

            if ($latestObs === null) {
                $latestObs = $obs;
            }
            $syncedBars++;
        }

        if ($source) {
            $source->update([
                'last_success_at' => now('UTC'),
                'last_error_at' => null,
                'last_error_message' => null,
                'health' => 'healthy',
            ]);
        }

        return [
            'success' => true,
            'ticker' => $ticker,
            'synced_bars' => $syncedBars,
            'latest_observation_id' => $latestObs?->id,
            'latest_date' => $latestObs?->bar_date?->toDateString(),
            'latest_close' => $latestObs?->close_price,
            'source' => 'IDX Edge PRO',
            'synced_at' => now('UTC')->toIso8601String(),
        ];
    }
}
