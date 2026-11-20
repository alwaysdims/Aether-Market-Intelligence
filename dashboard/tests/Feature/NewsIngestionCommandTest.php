<?php

namespace Tests\Feature;

use App\Models\NewsItem;
use App\Services\HermesAdapterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NewsIngestionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_archives_provenance_and_deduplicates_by_url(): void
    {
        $this->app->instance(HermesAdapterService::class, new class extends HermesAdapterService
        {
            public function fetchNewsFeed(?string $feedUrl = null, int $limit = 15): array
            {
                return [
                    'ok' => true,
                    'data' => [
                        'feed_url' => 'https://example.test/feed.xml',
                        'items' => [[
                            'title' => 'BBCA reports quarterly results',
                            'url' => 'https://example.test/articles/bbca-q3',
                            'source_name' => 'Example Feed',
                            'published_at_raw' => 'Fri, 13 Nov 2026 11:09:54 +0700',
                            'fetched_at_wib' => '2026-11-13T11:19:57+07:00',
                            'summary' => 'Provider-supplied feed summary.',
                            'verification_status' => 'unverified',
                            'related_tickers' => ['BBCA'],
                        ]],
                    ],
                ];
            }
        });

        $this->artisan('hermes:ingest-news --limit=1')->assertExitCode(0);
        $this->artisan('hermes:ingest-news --limit=1')->assertExitCode(0);

        $this->assertSame(1, NewsItem::count());
        $item = NewsItem::sole();
        $this->assertSame('BBCA reports quarterly results', $item->title);
        $this->assertSame(['BBCA'], $item->related_tickers);
        $this->assertSame('unverified', $item->verification_status);
        $this->assertNotNull($item->available_at);
        $this->assertSame(hash('sha256', 'Provider-supplied feed summary.'), $item->raw_payload_hash);
        Storage::disk('local')->assertExists($item->raw_payload_path);
    }
}
