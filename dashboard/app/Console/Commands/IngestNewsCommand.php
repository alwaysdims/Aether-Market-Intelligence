<?php

namespace App\Console\Commands;

use App\Models\DataRevisionArchive;
use App\Models\NewsItem;
use App\Services\HermesAdapterService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class IngestNewsCommand extends Command
{
    protected $signature = 'hermes:ingest-news {--limit=10 : Maximum provider items}';

    protected $description = 'Ingest provider news with raw payload provenance and URL idempotency';

    public function handle(HermesAdapterService $adapter): int
    {
        $expectedDatabase = (string) getenv('AETHER_ACTIVE_DATABASE');
        $expectedRole = (string) getenv('AETHER_WRITER_ROLE');
        if ($expectedDatabase === '' || $expectedRole === '') {
            $this->error('NEWS_WRITER_ABORT missing explicit writer identity');

            return self::FAILURE;
        }
        $identity = DB::selectOne("SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name, current_setting('search_path') AS search_path");
        if ($identity->database !== $expectedDatabase || $identity->user_name !== $expectedRole || $identity->schema_name !== 'public') {
            $this->error('NEWS_WRITER_ABORT identity mismatch after bootstrap');

            return self::FAILURE;
        }

        $response = $adapter->fetchNewsFeed(null, max(1, min(50, (int) $this->option('limit'))));
        $items = $response['data']['items'] ?? [];
        $feedUrl = (string) ($response['data']['feed_url'] ?? '');

        if (! ($response['ok'] ?? false) || ! is_array($items)) {
            $this->error('NEWS_PROVIDER_UNAVAILABLE');

            return self::FAILURE;
        }

        $stored = 0;
        foreach ($items as $item) {
            $url = trim((string) ($item['url'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $summary = trim((string) ($item['summary'] ?? ''));
            if ($url === '' || $title === '') {
                continue;
            }

            $fetchedAt = isset($item['fetched_at_wib'])
                ? Carbon::parse($item['fetched_at_wib'])->utc()
                : now('UTC');
            $publishedAt = $this->parseTimestamp($item['published_at_raw'] ?? null);
            $raw = $summary !== '' ? $summary : $title;
            $hash = hash('sha256', $raw);
            $relativePath = 'news/raw/'.substr($hash, 0, 2).'/'.$hash.'.txt';
            Storage::disk('local')->put($relativePath, $raw);

            $existing = NewsItem::where('url', $url)->first();
            $values = [
                'title' => $title,
                'source_name' => trim((string) ($item['source_name'] ?? 'Unknown provider')),
                'published_at' => $publishedAt,
                'fetched_at' => $fetchedAt,
                'available_at' => $fetchedAt,
                'summary' => $summary,
                'verification_status' => $this->verificationStatus($item['verification_status'] ?? null),
                'related_tickers' => array_values(array_unique(array_filter($item['related_tickers'] ?? [], 'is_string'))),
                'raw_payload_path' => $relativePath,
                'raw_payload_hash' => $hash,
                'source_feed_url' => $feedUrl,
            ];

            if ($existing) {
                $sameVersion = $existing->raw_payload_hash === $hash;
                unset($values['available_at']);
                if (! $sameVersion) {
                    DataRevisionArchive::create([
                        'record_type' => 'NewsItem',
                        'record_id' => $existing->id,
                        'ticker' => 'NEWS',
                        'period_or_date' => $existing->published_at?->toDateString() ?? 'UNKNOWN',
                        'revision_number' => DataRevisionArchive::where('record_type', 'NewsItem')->where('record_id', $existing->id)->count() + 1,
                        'previous_payload' => $existing->toArray(),
                        'previous_hash' => $existing->raw_payload_hash,
                        'new_payload' => $values,
                        'new_hash' => $hash,
                        'changes' => ['raw_payload_hash' => ['old' => $existing->raw_payload_hash, 'new' => $hash]],
                        'source' => 'Antara News Ekonomi & Finansial',
                        'reason' => 'NEWS_CONTENT_VERSION_CHANGED',
                    ]);
                    $values['available_at'] = $fetchedAt;
                }
                $existing->fill($values)->save();
            } else {
                NewsItem::create($values + ['url' => $url, 'first_seen_at' => $fetchedAt]);
            }
            $stored++;
        }

        $this->info("NEWS_INGESTED {$stored}");

        return self::SUCCESS;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function verificationStatus(mixed $value): string
    {
        return in_array($value, ['official_statement', 'verified_fact', 'opinion', 'rumor', 'unverified'], true)
            ? $value
            : 'unverified';
    }
}
