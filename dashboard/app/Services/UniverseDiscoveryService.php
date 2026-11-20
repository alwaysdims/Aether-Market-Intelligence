<?php

namespace App\Services;

use App\Models\IdxCompanyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UniverseDiscoveryService
{
    public function __construct(
        protected IdxEdgeClient $client
    ) {}

    /**
     * Reconcile local registry with IDX Edge provider catalogue (/api/market-cap).
     *
     * @return array<string, mixed>
     */
    public function reconcileUniverse(?string $runId = null): array
    {
        $runId ??= 'universe_'.now('Asia/Jakarta')->format('Ymd_His').'_'.bin2hex(random_bytes(4));
        $run = DB::table('universe_discovery_runs')->where('run_id', $runId)->first();
        if (! $run) {
            DB::table('universe_discovery_runs')->insert([
                'run_id' => $runId,
                'status' => 'DISCOVERING',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $run = DB::table('universe_discovery_runs')->where('run_id', $runId)->first();
        }
        $stagedTickers = DB::table('universe_discovery_items')
            ->where('run_id', $runId)
            ->pluck('ticker')
            ->all();
        $page = max(1, (int) $run->next_page);
        $perPage = 50;
        $totalPages = max(1, (int) $run->total_pages);
        $providerTotalReported = (int) $run->provider_total;
        $discovered = (int) $run->raw_items_seen;
        $created = 0;
        $updated = 0;
        $duplicates = 0;
        $excluded = 0;
        $failedPages = [];
        $seenTickers = array_fill_keys($stagedTickers, true);

        do {
            $response = null;
            $retries = 0;
            $maxRetries = 4;

            while ($retries < $maxRetries) {
                $response = $this->client->marketCap($page, $perPage);
                if (($response['success'] ?? false) && ! empty($response['data']['items'])) {
                    break;
                }

                $err = $response['error_type'] ?? 'UNKNOWN';
                if ($err === 'LOCAL_RATE_LIMIT' || $err === 'PROVIDER_CONNECTION_FAILED') {
                    $retries++;
                    usleep(1500000); // 1.5s backoff

                    continue;
                }

                break;
            }

            if (! ($response['success'] ?? false) || empty($response['data']['items'])) {
                Log::warning("Universe discovery failed at page {$page}", ['response' => $response]);
                $failedPages[] = $page;
                $page++;

                continue;
            }

            $data = $response['data'];
            $totalPages = (int) ($data['total_pages'] ?? 1);
            if ($providerTotalReported === 0) {
                $providerTotalReported = (int) ($data['total'] ?? 0);
            }

            $items = $data['items'];

            foreach ($items as $item) {
                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                if ($code === '' || strlen($code) > 8) {
                    $excluded++;

                    continue;
                }

                if (isset($seenTickers[$code])) {
                    $duplicates++;

                    continue;
                }

                $seenTickers[$code] = true;
                $discovered++;

                $isIndex = in_array($code, ['^JKSE', 'JKSE', 'IHSG', 'LQ45', 'IDX30', 'KOMPAS100'], true);
                $instrumentType = $isIndex ? 'INDEX' : 'EQUITY';

                $inserted = DB::table('universe_discovery_items')->insertOrIgnore([
                    'run_id' => $runId,
                    'ticker' => $code,
                    'provider_page' => $page,
                    'company_name' => (string) ($item['name'] ?? ''),
                    'listed_shares' => isset($item['listed_shares']) ? (int) $item['listed_shares'] : null,
                    'instrument_type' => $instrumentType,
                    'raw_payload' => json_encode($item, JSON_THROW_ON_ERROR),
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
                if ($inserted === 0) {
                    $duplicates++;
                }
            }

            $page++;
            DB::table('universe_discovery_runs')->where('run_id', $runId)->update([
                'provider_total' => $providerTotalReported,
                'total_pages' => $totalPages,
                'next_page' => $page,
                'raw_items_seen' => $discovered,
                'updated_at' => now('UTC'),
            ]);
            usleep(150000); // 150ms pacing
        } while ($page <= $totalPages);

        $unavailableCount = 0;
        $complete = $failedPages === []
            && $providerTotalReported > 0
            && $discovered === $providerTotalReported
            && count($seenTickers) === DB::table('universe_discovery_items')->where('run_id', $runId)->count();

        if ($complete) {
            DB::transaction(function () use ($runId, &$created, &$updated, &$unavailableCount): void {
                $staged = DB::table('universe_discovery_items')->where('run_id', $runId)->get();
                foreach ($staged as $item) {
                    $isKnownOfficial = isset(BeiUniverseRegistry::KNOWN_BEI_ISSUERS[$item->ticker]);
                    $identityStatus = $item->instrument_type === 'INDEX'
                        ? 'VALID_BEI_INDEX'
                        : ($isKnownOfficial ? 'VALID_BEI_EQUITY' : 'PROVIDER_DISCOVERED');
                    $existing = IdxCompanyRegistry::where('ticker', $item->ticker)->first();
                    if (! $existing) {
                        IdxCompanyRegistry::create([
                            'ticker' => $item->ticker,
                            'company_name' => $item->company_name ?: $item->ticker,
                            'shares_outstanding' => $item->listed_shares,
                            'instrument_type' => $item->instrument_type,
                            'listing_status' => 'ACTIVE',
                            'identity_status' => $identityStatus,
                            'provider_availability' => 'AVAILABLE',
                            'backfill_status' => 'PENDING',
                            'source' => 'IDX Edge PRO Market Cap Discovery',
                            'snapshot_date' => now('Asia/Jakarta')->toDateString(),
                        ]);
                        $created++;

                        continue;
                    }
                    $changes = ['provider_availability' => 'AVAILABLE'];
                    if (! in_array($existing->identity_status, ['VALID_BEI_EQUITY', 'VALID_BEI_INDEX'], true)) {
                        $changes['identity_status'] = $identityStatus;
                    }
                    if (empty($existing->company_name) && $item->company_name) {
                        $changes['company_name'] = $item->company_name;
                    }
                    if ((int) $item->listed_shares > 0) {
                        $changes['shares_outstanding'] = (int) $item->listed_shares;
                    }
                    $existing->update($changes);
                    $updated++;
                }

                $seen = $staged->pluck('ticker')->all();
                $unavailableCount = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
                    ->whereNotIn('ticker', $seen)
                    ->where('provider_availability', '!=', 'UNAVAILABLE')
                    ->update([
                        'provider_availability' => 'UNAVAILABLE',
                        'identity_status' => 'REGISTRY_PROVIDER_UNAVAILABLE',
                    ]);
            });
        }

        DB::table('universe_discovery_runs')->where('run_id', $runId)->update([
            'status' => $complete ? 'PROMOTED' : 'PARTIAL',
            'failed_pages' => json_encode($failedPages),
            'validation_error' => $complete ? null : 'Discovery belum lengkap; registri tidak diubah.',
            'completed_at' => $complete ? now('UTC') : null,
            'updated_at' => now('UTC'),
        ]);

        $totalEquities = IdxCompanyRegistry::where('instrument_type', 'EQUITY')->count();
        $totalIndices = IdxCompanyRegistry::where('instrument_type', 'INDEX')->count();
        $availableEquities = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
            ->where('provider_availability', 'AVAILABLE')
            ->count();
        $distinctDiscovered = count($seenTickers);

        return [
            'success' => $complete,
            'run_id' => $runId,
            'promotion_status' => $complete ? 'PROMOTED' : 'NOT_PROMOTED',
            'provider_total_reported' => $providerTotalReported,
            'total_pages' => $totalPages,
            'failed_pages' => $failedPages,
            'distinct_tickers_discovered' => $distinctDiscovered,
            'total_items_processed' => $discovered,
            'duplicates_skipped' => $duplicates,
            'excluded_invalid_codes' => $excluded,
            'new_registered' => $created,
            'updated_existing' => $updated,
            'marked_unavailable' => $unavailableCount,
            'total_equities_in_registry' => $totalEquities,
            'total_indices_in_registry' => $totalIndices,
            'available_equities' => $availableEquities,
            'timestamp' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }
}
