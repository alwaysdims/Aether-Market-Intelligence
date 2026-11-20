<?php

require __DIR__ . '/../dashboard/vendor/autoload.php';
$app = require __DIR__ . '/../dashboard/bootstrap/app.php';
$app->make("Illuminate\Contracts\Console\Kernel")->bootstrap();

use App\Services\IdxEdgeClient;
use Illuminate\Support\Facades\DB;

$client = app(IdxEdgeClient::class);

echo "=== STEP 2: STAGING UNIVERSE DISCOVERY FROM PROVIDER ===\n";

// 1. Ensure staging table exists in PostgreSQL
DB::statement("
    CREATE TABLE IF NOT EXISTS staging_universe_discovery (
        id SERIAL PRIMARY KEY,
        ticker VARCHAR(16) UNIQUE NOT NULL,
        company_name VARCHAR(255),
        listed_shares BIGINT,
        instrument_type VARCHAR(32) NOT NULL,
        provider_page INT NOT NULL,
        raw_payload JSONB,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
");

$page = 1;
$perPage = 50;
$totalPages = 1;
$providerTotalReported = 0;
$seenTickers = [];
$failedPages = [];
$stagedCount = 0;

do {
    $response = null;
    $retries = 0;
    $maxRetries = 5;

    while ($retries < $maxRetries) {
        $response = $client->marketCap($page, $perPage);
        if (($response['success'] ?? false) && !empty($response['data']['items'])) {
            break;
        }

        $err = $response['error_type'] ?? 'UNKNOWN';
        echo "Page {$page} retry " . ($retries + 1) . " due to error: {$err}\n";
        $retries++;
        sleep(2); // 2s backoff
    }

    if (!($response['success'] ?? false) || empty($response['data']['items'])) {
        echo "WARNING: Rate limit on Page {$page}. Pausing 45s cool-off...\n";
        sleep(45);
        // Retry page
        $response = $client->marketCap($page, $perPage);
        if (!($response['success'] ?? false) || empty($response['data']['items'])) {
            echo "ERROR: Failed to fetch page {$page} even after cool-off!\n";
            $failedPages[] = $page;
            $page++;
            continue;
        }
    }

    $data = $response['data'];
    $totalPages = (int) ($data['total_pages'] ?? 1);
    if ($providerTotalReported === 0) {
        $providerTotalReported = (int) ($data['total'] ?? 0);
        echo "Provider reported total items: {$providerTotalReported} across {$totalPages} pages.\n";
    }

    $items = $data['items'];
    echo "Processing Page {$page}/{$totalPages} (" . count($items) . " items)...\n";

    foreach ($items as $item) {
        $code = strtoupper(trim((string) ($item['code'] ?? '')));
        if ($code === '' || strlen($code) > 8) {
            continue;
        }

        if (isset($seenTickers[$code])) {
            continue;
        }

        $seenTickers[$code] = true;
        $isIndex = in_array($code, ['^JKSE', 'JKSE', 'IHSG', 'LQ45', 'IDX30', 'KOMPAS100'], true);
        $instrumentType = $isIndex ? 'INDEX' : 'EQUITY';
        $listedShares = isset($item['listed_shares']) ? (int) $item['listed_shares'] : null;

        DB::table('staging_universe_discovery')->updateOrInsert(
            ['ticker' => $code],
            [
                'company_name' => (string) ($item['name'] ?? ''),
                'listed_shares' => $listedShares,
                'instrument_type' => $instrumentType,
                'provider_page' => $page,
                'raw_payload' => json_encode($item),
            ]
        );
        $stagedCount++;
    }

    $page++;
    usleep(250000); // 250ms pacing between pages
} while ($page <= $totalPages);

echo "\n=== STAGING DISCOVERY COMPLETE ===\n";
echo "Total Provider Pages Processed: " . ($page - 1) . "/{$totalPages}\n";
echo "Failed Pages: " . (empty($failedPages) ? 'NONE' : implode(', ', $failedPages)) . "\n";
echo "Distinct Tickers Staged: " . count($seenTickers) . "\n";

echo "\n=== COMPARISON: STAGING DISCOVERY VS EXISTING REGISTRY ===\n";
$existingRegistries = DB::table('idx_company_registries')->get();
$existingCount = $existingRegistries->count();
$existingTickers = $existingRegistries->pluck('ticker')->all();
$existingMap = $existingRegistries->keyBy('ticker');

$newDiscoveredInStaging = [];
$matchingInStaging = [];

foreach ($seenTickers as $t => $_) {
    if (isset($existingMap[$t])) {
        $matchingInStaging[] = $t;
    } else {
        $newDiscoveredInStaging[] = $t;
    }
}

$missingFromStaging = array_diff($existingTickers, array_keys($seenTickers));

echo "Existing Registries Count: {$existingCount}\n";
echo "Matching in Staging: " . count($matchingInStaging) . "\n";
echo "New Discovered Tickers (in Staging but not in Registry): " . count($newDiscoveredInStaging) . "\n";
echo "Existing Tickers not returned by Provider: " . count($missingFromStaging) . "\n";

if (!empty($newDiscoveredInStaging)) {
    echo "Sample New Tickers Discovered: " . implode(', ', array_slice($newDiscoveredInStaging, 0, 15)) . "...\n";
}
if (!empty($missingFromStaging)) {
    echo "Sample Existing Tickers Missing from Provider Response: " . implode(', ', array_slice($missingFromStaging, 0, 15)) . "...\n";
}

echo "\nNote: Existing idx_company_registries remains UNTOUCHED pending explicit activation step.\n";
