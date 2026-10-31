<?php

namespace App\Services;

use App\Models\BrokerSummary;
use App\Models\DataRevisionArchive;
use App\Models\DataSource;

class BrokerSummaryIngestionService
{
    public function __construct(
        protected IdxEdgeClient $client,
        protected DataSourceRegistryService $registry
    ) {}

    /**
     * Synchronize broker summary for a ticker on a specific session.
     *
     * @return array<string, mixed>
     */
    public function syncDailySummary(string $ticker, ?string $date = null, string $flow = 'all', int $limit = 50): array
    {
        $ticker = strtoupper(trim($ticker));
        $dateStr = $date ?? now('Asia/Jakarta')->toDateString();

        $source = DataSource::where('name', 'IDX Edge Broker Summary')->first();
        if ($source) {
            $source->update(['last_attempt_at' => now('UTC')]);
        }

        $response = $this->client->brokerSummary($ticker, $dateStr, $flow, $limit);
        if (! $response['success']) {
            if ($source) {
                $source->update([
                    'last_error_at' => now('UTC'),
                    'last_error_message' => $response['message'] ?? 'Gagal mengambil broker summary.',
                    'health' => 'degraded',
                ]);
            }

            return [
                'success' => false,
                'error_type' => $response['error_type'] ?? 'FETCH_FAILED',
                'message' => $response['message'] ?? 'Gagal mengambil broker summary.',
            ];
        }

        $data = $response['data'];
        $brokers = $data['brokers'] ?? [];
        $savedCount = 0;
        $totalBuyValue = 0.0;
        $totalSellValue = 0.0;

        foreach ($brokers as $b) {
            $code = $b['broker_code'];
            $bvol = (int) $b['buy_volume'];
            $bval = (float) $b['buy_value'];
            $svol = (int) $b['sell_volume'];
            $sval = (float) $b['sell_value'];
            $nvol = (int) $b['net_volume'];
            $nval = (float) $b['net_value'];

            $dataHash = hash('sha256', "{$ticker}|{$dateStr}|{$code}|{$bvol}|{$svol}|{$nvol}|{$nval}|IDX Edge PRO");

            $existing = BrokerSummary::where('ticker', $ticker)
                ->whereDate('summary_date', $dateStr)
                ->where('broker_code', $code)
                ->where('flow_type', $flow)
                ->first();

            if ($existing && $existing->data_hash !== $dataHash) {
                $revCount = DataRevisionArchive::where('record_type', 'broker_summary')
                    ->where('record_id', $existing->id)
                    ->count();

                DataRevisionArchive::create([
                    'record_type' => 'broker_summary',
                    'record_id' => $existing->id,
                    'ticker' => $ticker,
                    'period_or_date' => $dateStr,
                    'revision_number' => $revCount + 1,
                    'previous_payload' => $existing->raw_payload,
                    'previous_hash' => $existing->data_hash,
                    'new_payload' => $b,
                    'new_hash' => $dataHash,
                    'changes' => [
                        'old' => [
                            'buy_volume' => $existing->buy_volume,
                            'sell_volume' => $existing->sell_volume,
                            'net_volume' => $existing->net_volume,
                            'net_value' => $existing->net_value,
                        ],
                        'new' => [
                            'buy_volume' => $bvol,
                            'sell_volume' => $svol,
                            'net_volume' => $nvol,
                            'net_value' => $nval,
                        ],
                    ],
                    'source' => 'IDX Edge PRO',
                    'reason' => 'BROKER_SUMMARY_REVISION_DETECTED',
                ]);
            }

            $attributes = [
                'broker_name' => $b['broker_name'],
                'board' => 'RG',
                'buy_volume' => $bvol,
                'buy_value' => $bval,
                'buy_frequency' => $b['buy_frequency'],
                'buy_avg_price' => $b['buy_avg_price'],
                'sell_volume' => $svol,
                'sell_value' => $sval,
                'sell_frequency' => $b['sell_frequency'],
                'sell_avg_price' => $b['sell_avg_price'],
                'net_volume' => $nvol,
                'net_value' => $nval,
                'unit' => 'SHARES',
                'currency' => 'IDR',
                'source' => 'IDX Edge PRO',
                'data_quality' => 'PROVIDER_SUPPLIED',
                'fetched_at' => now('UTC'),
                'raw_payload' => $b,
                'data_hash' => $dataHash,
            ];

            if ($existing) {
                $existing->update($attributes);
            } else {
                BrokerSummary::create(array_merge([
                    'ticker' => $ticker,
                    'summary_date' => $dateStr,
                    'broker_code' => $code,
                    'flow_type' => $flow,
                ], $attributes));
            }

            $savedCount++;
            $totalBuyValue += $bval;
            $totalSellValue += $sval;
        }

        if ($source) {
            $source->update([
                'last_success_at' => now('UTC'),
                'last_error_at' => null,
                'last_error_message' => null,
                'health' => 'healthy',
                'data_quality' => 'PROVIDER_SUPPLIED',
            ]);
        }

        $topBuyers = BrokerSummary::where('ticker', $ticker)
            ->where('summary_date', $dateStr)
            ->where('flow_type', $flow)
            ->topBuyers(3)
            ->get(['broker_code', 'broker_name', 'net_value', 'net_volume', 'buy_avg_price']);

        $topSellers = BrokerSummary::where('ticker', $ticker)
            ->where('summary_date', $dateStr)
            ->where('flow_type', $flow)
            ->topSellers(3)
            ->get(['broker_code', 'broker_name', 'net_value', 'net_volume', 'sell_avg_price']);

        return [
            'success' => true,
            'ticker' => $ticker,
            'summary_date' => $dateStr,
            'flow' => $flow,
            'saved_records' => $savedCount,
            'board' => 'RG (Verified on Sample)',
            'volume_unit' => 'SHARES',
            'currency' => 'IDR',
            'top_buyers' => $topBuyers->toArray(),
            'top_sellers' => $topSellers->toArray(),
            'total_buy_value' => $totalBuyValue,
            'total_sell_value' => $totalSellValue,
            'synced_at' => now('UTC')->toIso8601String(),
        ];
    }
}
