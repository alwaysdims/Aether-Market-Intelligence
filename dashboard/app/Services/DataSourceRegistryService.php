<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\MarketObservation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
                'name' => 'IDX Edge Quote Intraday',
                'type' => 'market_data',
                'data_classification' => 'real_time',
                'base_url' => 'https://stock.arjum.com',
                'endpoint' => '/api/price/{code}',
                'status' => 'quarantined',
                'is_enabled' => false,
                'health' => 'healthy',
                'data_quality' => 'QUARANTINED',
                'quarantine_reason' => 'Terkontaminasi transaksi Pasar Negosiasi (NG) tanpa filter board; tidak sesuai sebagai harga reguler',
                'metadata' => [
                    'quarantined_from' => 'regular_chart_and_signals',
                    'issue' => 'board_contamination_NG',
                    'role' => 'Intraday Snapshot (Quarantined)',
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
                'name' => 'Yahoo Finance Daily EOD',
                'type' => 'market_data',
                'data_classification' => 'end_of_day',
                'base_url' => 'https://query1.finance.yahoo.com/v8/finance/chart/',
                'endpoint' => '/v8/finance/chart/{ticker}',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'VALID',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'Fallback EOD Source',
                ],
            ],
            [
                'name' => 'IDX Official Announcements & News',
                'type' => 'news',
                'data_classification' => 'delayed',
                'base_url' => 'https://www.idx.co.id',
                'endpoint' => '/primary/ListedCompany/GetAnnouncement',
                'status' => 'active',
                'is_enabled' => true,
                'health' => 'healthy',
                'data_quality' => 'VALID',
                'quarantine_reason' => null,
                'metadata' => [
                    'role' => 'Official Issuer Announcements & News',
                ],
            ],
            [
                'name' => 'Index Alpha Broker Summary',
                'type' => 'broker_summary',
                'data_classification' => 'end_of_day',
                'base_url' => 'https://api.indexalpha.id/stocks/broker-summary',
                'endpoint' => '/stocks/broker-summary',
                'status' => 'inactive',
                'is_enabled' => false,
                'health' => 'unknown',
                'data_quality' => 'DEPRECATED',
                'quarantine_reason' => 'Dinonaktifkan; digantikan oleh rencana konektor terverifikasi',
                'metadata' => [
                    'role' => 'Legacy Broker Summary (Disabled)',
                ],
            ],
        ];

        $created = 0;
        $updated = 0;

        foreach ($definitions as $def) {
            $existing = DataSource::where('name', $def['name'])->first();
            if (! $existing) {
                DataSource::create($def);
                $created++;
            } else {
                $existing->update([
                    'type' => $def['type'],
                    'data_classification' => $def['data_classification'],
                    'base_url' => $def['base_url'],
                    'endpoint' => $def['endpoint'],
                    'status' => $def['status'],
                    'is_enabled' => $def['is_enabled'],
                    'health' => $def['health'],
                    'data_quality' => $def['data_quality'],
                    'quarantine_reason' => $def['quarantine_reason'],
                    'metadata' => array_merge($existing->metadata ?? [], $def['metadata']),
                ]);
                $updated++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => DataSource::count(),
        ];
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
            'price' => 'IDX Edge Quote Intraday',
            'broker' => 'IDX Edge Broker Summary',
            'fundamental' => 'IDX Edge Fundamental',
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

        return (bool) ($source->is_enabled && $source->status === 'active' && in_array($source->data_quality, ['VALID', 'VERIFIED_ON_SAMPLE'], true));
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
                    'label' => 'DIBLOKIR (KREDENSIAL TIDAK TERPASANG)',
                    'reason' => 'Kredensial API key IDX Edge kosong atau belum terpasang di runtime.',
                ];
            }

            if ($source->data_quality === 'QUARANTINED') {
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
                    'label' => 'DITAHAN (CANARY PENDING)',
                    'reason' => $source->quarantine_reason ?? 'Belum diverifikasi canary.',
                ];
            }

            if ($source->is_enabled && $source->status === 'active' && in_array($source->data_quality, ['VALID', 'VERIFIED_ON_SAMPLE'], true)) {
                $label = 'SIAP (TERKONTROL)';
                if (str_contains($source->name, 'Broker')) {
                    $label = 'SIAP (TERKONTROL BROKER RG)';
                } elseif (str_contains($source->name, 'Fundamental')) {
                    $label = 'SIAP (TERKONTROL FUNDAMENTAL)';
                } elseif (str_contains($source->name, 'Histori')) {
                    $label = 'SIAP (TERKONTROL EOD)';
                }

                return [
                    'effective_enabled' => true,
                    'badge' => 'READY_CONTROLLED',
                    'label' => $label,
                    'reason' => 'Tervalidasi pada sampel (RG/Audit). Diizinkan untuk sinkronisasi terkontrol.',
                ];
            }
        }

        if ($source->name === 'Yahoo Finance Daily EOD') {
            return [
                'effective_enabled' => (bool) $source->is_enabled,
                'badge' => 'ACTIVE_FALLBACK',
                'label' => 'AKTIF (FALLBACK EOD)',
                'reason' => 'Sumber fallback aktif untuk kontinuitas operasional.',
            ];
        }

        if ($source->name === 'IDX Official Announcements & News') {
            return [
                'effective_enabled' => (bool) $source->is_enabled,
                'badge' => 'ACTIVE_OFFICIAL',
                'label' => 'AKTIF (PENGUMUMAN BEI)',
                'reason' => 'Sumber resmi pengumuman dan berita emiten BEI.',
            ];
        }

        if ($source->status === 'inactive') {
            return [
                'effective_enabled' => false,
                'badge' => 'DISABLED_LEGACY',
                'label' => 'NONAKTIF',
                'reason' => $source->quarantine_reason ?? 'Sumber nonaktif.',
            ];
        }

        return [
            'effective_enabled' => (bool) $source->is_enabled,
            'badge' => strtoupper($source->status),
            'label' => strtoupper($source->status),
            'reason' => $source->quarantine_reason ?? '-',
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
