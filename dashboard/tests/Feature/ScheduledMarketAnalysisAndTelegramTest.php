<?php

namespace Tests\Feature;

use App\Console\Commands\HermesProcessScheduledJobsCommand;
use App\Models\MarketObservation;
use App\Models\ScheduledTelegramJob;
use App\Models\TelegramOutbox;
use App\Services\AiConversationService;
use App\Services\CanonicalMarketReadService;
use App\Services\TelegramMarketAssistantService;
use App\Services\TelegramTwoWayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduledMarketAnalysisAndTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.chat_id' => '5923895922']);
    }

    public function test_extract_ticker_strictly_rejects_common_words_and_unregistered_symbols(): void
    {
        $service = app(TelegramTwoWayService::class);

        // "analisis pasar" must NOT extract "PASAR" as a ticker
        $this->assertNull($service->extractTicker('pertama tama kamu analisis pasar dulu trus baru rekomendasi'));
        $this->assertNull($service->extractTicker('analisis saham pasar'));
        $this->assertNull($service->extractTicker('analisis situasi'));
        $this->assertNull($service->extractTicker('analisis overview'));

        // Real tickers must extract successfully
        $this->assertSame('BRIS', $service->extractTicker('analisis saham BRIS'));
        $this->assertSame('BBCA', $service->extractTicker('kondisi emiten BBCA'));
        $this->assertSame('^JKSE', $service->extractTicker('analisis pasar IHSG'));
    }

    public function test_parse_structured_request_for_complex_morning_inquiry(): void
    {
        $assistant = app(TelegramMarketAssistantService::class);
        $prompt = 'rekomandasi saham hari ini apa? tolong kasih saya rekomendasi di jam 09.05. pertama tama kamu analisis pasar dulu trus baru kasih rekomandasi saham mana yg bakal naik';

        // Fix evaluation time at 09:45 WIB (meaning 09:05 has already passed today)
        $now = Carbon::create(2026, 10, 27, 9, 45, 0, 'Asia/Jakarta');
        $parsed = $assistant->parseStructuredRequest($prompt, '5923895922', $now);

        $this->assertTrue($parsed['is_complex_inquiry']);
        $this->assertTrue($parsed['wants_market_analysis']);
        $this->assertTrue($parsed['wants_recommendation']);
        $this->assertTrue($parsed['has_schedule']);
        $this->assertSame('09:05', $parsed['requested_time_raw']);
        $this->assertTrue($parsed['is_past_time']);
        $this->assertSame('PAST_TIME_ON_SAME_DAY', $parsed['timing_status']);
        $this->assertSame('EOD_ONLY', $parsed['available_data_tier']);
        $this->assertStringContainsString('Analisis berbasis penutupan sesi sebelumnya', $parsed['data_gap_notice']);
    }

    public function test_inbound_past_schedule_delivers_immediate_report_with_transparent_disclaimer(): void
    {
        // Seed observation so screening candidate is produced
        MarketObservation::create([
            'ticker' => 'BRIS',
            'bar_date' => '2026-10-26',
            'observation_time' => '16:00:00',
            'interval' => '1d',
            'open_price' => 1030,
            'high_price' => 1070,
            'low_price' => 1025,
            'close_price' => 1055,
            'volume' => 115630800,
            'source' => 'YAHOO_FINANCE',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'is_eod_final' => true,
        ]);

        $service = app(TelegramTwoWayService::class);
        $update = [
            'update_id' => 'test_audit_'.uniqid(),
            'message' => [
                'message_id' => 9993,
                'from' => ['id' => '5923895922', 'first_name' => 'User'],
                'chat' => ['id' => '5923895922'],
                'text' => 'rekomandasi saham hari ini apa? tolong kasih saya rekomendasi di jam 09.05. pertama tama kamu analisis pasar dulu trus baru kasih rekomandasi saham mana yg bakal naik',
            ],
            '_synthetic' => true,
        ];

        Carbon::setTestNow(Carbon::create(2026, 10, 27, 9, 45, 0, 'Asia/Jakarta'));
        try {
            $res = $service->handleInboundMessage($update);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('COMMAND_SCHEDULED_MARKET_ANALYSIS', $res['intent']);
        $this->assertStringContainsString('ANALISIS PASAR & SCREENING KANDIDAT BEI', $res['reply_text']);
        $this->assertStringContainsString('Waktu yang diminta telah terlampaui saat permintaan diproses', $res['reply_text']);
        $this->assertStringContainsString('TIDAK menjadwalkan diam-diam untuk hari esok', $res['reply_text']);
        $this->assertStringContainsString('Analisis berbasis penutupan sesi sebelumnya', $res['reply_text']);
        $this->assertStringContainsString('Tidak ada jaminan saham pasti naik', $res['reply_text']);
        $this->assertStringContainsString('1. KONDISI & BREADTH PASAR', $res['reply_text']);
        $this->assertStringContainsString('3. SCREENING KANDIDAT POTENSIAL', $res['reply_text']);
        $this->assertStringContainsString('Invalidation Level', $res['reply_text']);
        $this->assertStringContainsString('Output A (Studi Sentuhan Barrier): WAITING_FOR_FINAL_DATA', $res['reply_text']);
        $this->assertStringContainsString('Output B (Simulasi Transaksi): WITHHELD', $res['reply_text']);
    }

    public function test_future_scheduled_request_persists_pending_job_and_executes_at_due_time(): void
    {
        $service = app(TelegramTwoWayService::class);
        $assistant = app(TelegramMarketAssistantService::class);

        $uniquePrompt = 'tolong jadwalkan analisis pasar dan rekomendasi saham di jam 23.59 (test_'.uniqid().')';
        $update = [
            'update_id' => 'test_future_'.uniqid(),
            'message' => [
                'message_id' => 9994,
                'from' => ['id' => '5923895922', 'first_name' => 'User'],
                'chat' => ['id' => '5923895922'],
                'text' => $uniquePrompt,
            ],
            '_synthetic' => true,
        ];

        $res = $service->handleInboundMessage($update);

        $this->assertSame('COMMAND_SCHEDULED_MARKET_ANALYSIS', $res['intent']);
        $this->assertStringContainsString('PERMINTAAN TERJADWAL TERSIMPAN', $res['reply_text']);
        $this->assertStringContainsString('23:59 WIB', $res['reply_text']);

        // Check persistent record in database
        $job = ScheduledTelegramJob::where('original_prompt', $uniquePrompt)->first();
        $this->assertNotNull($job);
        $this->assertSame('PENDING', $job->status);

        // Simulate time advancing: make the job due
        $job->update(['scheduled_at' => now()->subMinute()]);

        // Run worker command in mock mode
        Artisan::call('hermes:process-scheduled-jobs', ['--mock' => true]);

        $job->refresh();
        $this->assertSame('COMPLETED', $job->status);
        $this->assertSame('SENT', $job->delivery_status);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->analysis_completed_at);
        $this->assertNotNull($job->sent_at);
        $this->assertNotNull($job->outbox_id);
        $this->assertNotNull($job->outbound_message_id);
        $this->assertStringStartsWith('MOCK_TG_JOB_', $job->outbound_message_id);

        // Check outbox row
        $outbox = TelegramOutbox::find($job->outbox_id);
        $this->assertNotNull($outbox);
        $this->assertSame('SENT', $outbox->status);
        $this->assertSame('SCHEDULED_MARKET_ANALYSIS', $outbox->message_type);
        $this->assertStringContainsString('ANALISIS PASAR & SCREENING KANDIDAT BEI', $outbox->payload_text);
    }

    public function test_overdue_job_beyond_threshold_is_marked_missed(): void
    {
        $job = ScheduledTelegramJob::create([
            'chat_id' => '5923895922',
            'telegram_user_id' => '5923895922',
            'original_prompt' => 'Pekerjaan sangat terlambat test',
            'scheduled_at' => now()->subHours(3),
            'status' => 'PENDING',
            'idempotency_key' => 'test_missed_'.uniqid(),
        ]);

        Artisan::call('hermes:process-scheduled-jobs', ['--mock' => true]);

        $job->refresh();
        $this->assertSame('MISSED', $job->status);
        $this->assertStringContainsString('terlewat', $job->error_message);
    }

    public function test_market_breadth_strict_mathematical_identities_and_partitioning(): void
    {
        $readService = app(CanonicalMarketReadService::class);

        $createObs = function ($ticker, $date, $close) {
            MarketObservation::create([
                'ticker' => $ticker,
                'bar_date' => $date,
                'observation_time' => '16:00:00',
                'open_price' => $close,
                'high_price' => $close,
                'low_price' => $close,
                'close_price' => $close,
                'volume' => 10000,
                'interval' => '1d',
                'data_classification' => 'PUBLIC_MARKET_EOD',
                'source' => 'SRC',
            ]);
        };

        // Seed 5 equity tickers and 1 benchmark
        // Ticker A: Advancer (+5.0%)
        $createObs('TKRA', '2026-10-23', 1000);
        $createObs('TKRA', '2026-10-26', 1050);

        // Ticker B: Decliner (-5.0%)
        $createObs('TKRB', '2026-10-23', 2000);
        $createObs('TKRB', '2026-10-26', 1900);

        // Ticker C: Unchanged (0.0%)
        $createObs('TKRC', '2026-10-23', 500);
        $createObs('TKRC', '2026-10-26', 500);

        // Ticker D: Excluded (No bar on reference date 2026-10-26)
        $createObs('TKRD', '2026-10-23', 300);

        // Ticker E: Excluded (No bar on previous session 2026-10-23)
        $createObs('TKRE', '2026-10-26', 400);

        // Benchmark ^JKSE
        $createObs('^JKSE', '2026-10-23', 5000);
        $createObs('^JKSE', '2026-10-26', 5050);

        $res = $readService->calculateMarketBreadth('2026-10-26');

        $this->assertTrue($res['math_verified']);
        $this->assertSame(5, $res['universe_total']);
        $this->assertSame(3, $res['eligible_count']);
        $this->assertSame(1, $res['advancers_count']);
        $this->assertSame(1, $res['decliners_count']);
        $this->assertSame(1, $res['unchanged_count']);
        $this->assertSame(2, $res['excluded_total']);
        $this->assertSame(1, $res['excluded_reasons']['NO_BAR_ON_REFERENCE_DATE']);
        $this->assertSame(1, $res['excluded_reasons']['NO_BAR_ON_PREV_SESSION']);

        // Automated Assertions for Math Identities
        $this->assertSame($res['eligible_count'], $res['advancers_count'] + $res['decliners_count'] + $res['unchanged_count']);
        $this->assertSame($res['universe_total'], $res['eligible_count'] + $res['excluded_total']);
        $this->assertSame(0.0, $res['median_change_pct']);

        // Benchmark verification
        $this->assertSame(5050.0, $res['benchmark']['close']);
        $this->assertSame(50.0, $res['benchmark']['daily_change']);
        $this->assertSame(1.0, $res['benchmark']['daily_change_pct']);
    }

    public function test_active_worker_lease_is_protected_and_dead_worker_is_reclaimed(): void
    {
        $currentPid = getmypid();

        // 1. Job with active alive PID should NOT be reclaimed
        $activeJob = ScheduledTelegramJob::create([
            'chat_id' => '5923895922',
            'telegram_user_id' => '5923895922',
            'original_prompt' => 'Pekerjaan aktif dengan PID hidup',
            'scheduled_at' => now()->subMinutes(10),
            'status' => 'PROCESSING',
            'worker_pid' => $currentPid,
            'heartbeat_at' => now()->subMinutes(6),
            'idempotency_key' => 'test_alive_'.uniqid(),
        ]);

        // 2. Job with non-existent dead PID should BE reclaimed
        $deadJob = ScheduledTelegramJob::create([
            'chat_id' => '5923895922',
            'telegram_user_id' => '5923895922',
            'original_prompt' => 'Pekerjaan mati dengan PID fiktif',
            'scheduled_at' => now()->subMinutes(10),
            'status' => 'PROCESSING',
            'worker_pid' => 999999, // non-existent PID
            'heartbeat_at' => now()->subMinutes(6),
            'idempotency_key' => 'test_dead_'.uniqid(),
        ]);

        Artisan::call('hermes:process-scheduled-jobs', ['--mock' => true]);

        $activeJob->refresh();
        $this->assertSame('PROCESSING', $activeJob->status, 'Active job with alive PID must remain in PROCESSING');

        $deadJob->refresh();
        $this->assertSame('COMPLETED', $deadJob->status, 'Dead job must be reclaimed to PENDING and then processed to COMPLETED by worker');
    }

    public function test_user_specified_tight_tolerance_is_honored_and_marks_missed_when_exceeded(): void
    {
        $service = app(TelegramTwoWayService::class);
        $prompt = 'jadwalkan analisis pasar jam 23.50 toleransi 15 menit (test_'.uniqid().')';

        $update = [
            'update_id' => 'test_tol_'.uniqid(),
            'message' => [
                'message_id' => 9998,
                'from' => ['id' => '5923895922', 'first_name' => 'User'],
                'chat' => ['id' => '5923895922'],
                'text' => $prompt,
            ],
            '_synthetic' => true,
        ];

        $res = $service->handleInboundMessage($update);
        $this->assertStringContainsString('15 menit (Preferensi pengguna)', $res['reply_text']);

        $job = ScheduledTelegramJob::where('original_prompt', $prompt)->first();
        $this->assertNotNull($job);
        $this->assertSame(15, $job->tolerance_minutes);

        // Advance time by 20 minutes (exceeding 15m tolerance)
        $job->update(['scheduled_at' => now()->subMinutes(20)]);

        Artisan::call('hermes:process-scheduled-jobs', ['--mock' => true]);

        $job->refresh();
        $this->assertSame('MISSED', $job->status);
        $this->assertStringContainsString('melebihi toleransi 15 menit', $job->error_message);
    }

    public function test_strict_lease_ownership_blocks_stolen_or_fenced_worker(): void
    {
        $assistant = app(TelegramMarketAssistantService::class);

        $job = ScheduledTelegramJob::create([
            'chat_id' => '5923895922',
            'telegram_user_id' => '5923895922',
            'original_prompt' => 'Uji strict ownership',
            'scheduled_at' => now()->subMinute(),
            'status' => 'PENDING',
            'idempotency_key' => 'test_owner_'.uniqid(),
        ]);

        // Mock outbox / execution
        // Acquire by simulating another worker taking it with different token
        $job->update([
            'status' => 'PROCESSING',
            'worker_pid' => 12345,
            'worker_token' => 'WTOK_FOREIGN_TOKEN',
            'heartbeat_at' => now(),
        ]);

        // Attempt execution: since status is already PROCESSING, acquisition fails
        $res = $assistant->executeScheduledJob($job, mockOnly: true);
        $this->assertSame('skipped', $res['status']);
    }

    public function test_timezone_conversion_semantics_and_information_cutoff_enforcement(): void
    {
        $readService = app(CanonicalMarketReadService::class);

        // 1. Verify Timezone Semantic Identity: 16:00:00 WIB === 09:00:00 UTC
        $wibTime = Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-26 16:00:00', 'Asia/Jakarta');
        $utcTime = $wibTime->copy()->utc();

        $this->assertSame('09:00:00', $utcTime->format('H:i:s'));
        $this->assertNotSame('16:00:00', $utcTime->format('H:i:s'));

        // 2. Information Cutoff Enforcement
        // Cutoff: 2026-10-26 23:59:59 WIB = 2026-10-26 16:59:59 UTC
        $cutoffUtc = Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-26 16:59:59', 'UTC');

        // Seed Ticker CUTOFF_OK: available BEFORE cutoff (12:00:00 UTC)
        MarketObservation::create([
            'ticker' => 'COK',
            'bar_date' => '2026-10-23',
            'observation_time' => '16:00:00',
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 100,
            'close_price' => 100,
            'volume' => 1000,
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'source' => 'SRC',
            'available_at' => Carbon::parse('2026-10-23 12:00:00', 'UTC'),
        ]);
        MarketObservation::create([
            'ticker' => 'COK',
            'bar_date' => '2026-10-26',
            'observation_time' => '16:00:00',
            'open_price' => 110,
            'high_price' => 110,
            'low_price' => 110,
            'close_price' => 110,
            'volume' => 1000,
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'source' => 'SRC',
            'available_at' => Carbon::parse('2026-10-26 12:00:00', 'UTC'),
        ]);

        // Seed Ticker CUTOFF_LATE: reference bar available AFTER cutoff (2026-10-27 02:00:00 UTC)
        MarketObservation::create([
            'ticker' => 'CLATE',
            'bar_date' => '2026-10-23',
            'observation_time' => '16:00:00',
            'open_price' => 200,
            'high_price' => 200,
            'low_price' => 200,
            'close_price' => 200,
            'volume' => 1000,
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'source' => 'SRC',
            'available_at' => Carbon::parse('2026-10-23 12:00:00', 'UTC'),
        ]);
        MarketObservation::create([
            'ticker' => 'CLATE',
            'bar_date' => '2026-10-26',
            'observation_time' => '16:00:00',
            'open_price' => 220,
            'high_price' => 220,
            'low_price' => 220,
            'close_price' => 220,
            'volume' => 1000,
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'source' => 'SRC',
            'available_at' => Carbon::parse('2026-10-27 02:00:00', 'UTC'), // LATE! After cutoff!
        ]);

        $res = $readService->calculateMarketBreadth('2026-10-26', $cutoffUtc);

        // COK must be eligible, CLATE must be excluded as NO_BAR_ON_REFERENCE_DATE because it was available only after cutoff
        $this->assertContains('COK', $res['eligible_tickers']);
        $this->assertNotContains('CLATE', $res['eligible_tickers']);
        $this->assertSame(1, $res['excluded_reasons']['NO_BAR_ON_REFERENCE_DATE']);
    }

    public function test_pid_reuse_fencing_protection_blocks_kill_on_mismatched_creation_date(): void
    {
        $command = app(HermesProcessScheduledJobsCommand::class);
        $myPid = getmypid();

        // 1. Same PID but mismatched bogus creation date must REFUSE fencing to protect against PID reuse
        $fenced = $command->fenceProcess($myPid, 'BOGUS_CREATION_DATE_19990101_000000');
        $this->assertFalse($fenced, 'Fence must be refused when creation date does not match');

        // 2. Non-existent PID must return true (already dead/no harm)
        $this->assertTrue($command->fenceProcess(99999999));
    }

    /**
     * Test worker separation: scheduled worker never processes ASYNC_CONSULTATION,
     * and consultation worker never processes MARKET_ANALYSIS_AND_RECOMMENDATION.
     */
    public function test_worker_separation_and_job_type_isolation(): void
    {
        // 1. Create a due ASYNC_CONSULTATION job
        $asyncJob = ScheduledTelegramJob::create([
            'chat_id' => '5923895922',
            'telegram_user_id' => '5923895922',
            'original_prompt' => 'Analisis valuasi cepat (async)',
            'request_type' => 'ASYNC_CONSULTATION',
            'scheduled_at' => now()->subMinute(),
            'status' => 'PENDING',
            'idempotency_key' => 'test_worker_sep_async_'.uniqid(),
        ]);

        // 2. Create a due MARKET_ANALYSIS_AND_RECOMMENDATION job
        $scheduledJob = ScheduledTelegramJob::create([
            'chat_id' => '5923895922',
            'telegram_user_id' => '5923895922',
            'original_prompt' => 'Jadwal rekomendasi pasar reguler',
            'request_type' => 'MARKET_ANALYSIS_AND_RECOMMENDATION',
            'scheduled_at' => now()->subMinute(),
            'status' => 'PENDING',
            'idempotency_key' => 'test_worker_sep_sched_'.uniqid(),
        ]);

        // 3. Run scheduled worker: MUST ignore ASYNC_CONSULTATION and process MARKET_ANALYSIS_AND_RECOMMENDATION
        Artisan::call('hermes:process-scheduled-jobs', ['--mock' => true]);

        $asyncJob->refresh();
        $scheduledJob->refresh();

        $this->assertSame('PENDING', $asyncJob->status, 'Scheduled worker must NEVER claim ASYNC_CONSULTATION jobs');
        $this->assertSame('COMPLETED', $scheduledJob->status, 'Scheduled worker must claim and complete regular scheduled job');

        // 4. Run consultation worker: MUST process ASYNC_CONSULTATION and ignore regular jobs
        $mockConv = $this->mock(AiConversationService::class, function ($mock) {
            $mock->shouldReceive('handleConversation')->andReturn('Hasil analisis evaluasi konsultasi.');
        });

        Artisan::call('hermes:process-consultation-queue', ['--mock' => true]);

        $asyncJob->refresh();
        $this->assertSame('COMPLETED', $asyncJob->status, 'Consultation worker must claim and complete ASYNC_CONSULTATION jobs');
    }
}
