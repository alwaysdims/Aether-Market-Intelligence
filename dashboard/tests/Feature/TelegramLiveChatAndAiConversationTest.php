<?php

namespace Tests\Feature;

use App\Models\MarketObservation;
use App\Models\PredictionSignal;
use App\Models\ScheduledTelegramJob;
use App\Models\TelegramConversation;
use App\Models\TelegramOutbox;
use App\Services\AiConversationService;
use App\Services\AiToolExecutorService;
use App\Services\TelegramMarketAssistantService;
use App\Services\TelegramTwoWayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TelegramLiveChatAndAiConversationTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramTwoWayService $twoWayService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoWayService = app(TelegramTwoWayService::class);
    }

    protected function makeUpdate(string $text, string $updateId = 'upd_1', string $msgId = '101'): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $msgId,
                'from' => [
                    'id' => 5923895922,
                    'username' => 'TestOwner',
                ],
                'chat' => [
                    'id' => 5923895922,
                    'type' => 'private',
                ],
                'text' => $text,
                'date' => time(),
            ],
        ];
    }

    /**
     * 1. Test greeting and free-text chat are handled by AI conversation service.
     */
    public function test_greeting_and_free_text_invokes_ai_conversation(): void
    {
        $this->mock(AiConversationService::class, function ($mock) {
            $mock->shouldReceive('handleConversation')
                ->once()
                ->andReturn('Bisa. Sistem Aether Market Intelligence aktif. Kirim pertanyaan pasar modal BEI.');
        });

        $res = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('hai apakah kamu bisa response chat saya?', 'upd_greet_1', '101')
        );

        $this->assertEquals('success', $res['status']);
        $this->assertEquals('GENERAL_CONVERSATION', $res['intent']);
        $this->assertStringContainsString('Sistem Aether Market Intelligence aktif', $res['reply_text']);

        $this->assertDatabaseHas('telegram_conversations', [
            'telegram_update_id' => 'upd_greet_1',
            'detected_intent' => 'GENERAL_CONVERSATION',
        ]);
    }

    /**
     * 2. Test capability question triggers conversational reply.
     */
    public function test_capability_question_triggers_conversational_reply(): void
    {
        $this->mock(AiConversationService::class, function ($mock) {
            $mock->shouldReceive('handleConversation')
                ->once()
                ->andReturn('Aether dapat membantu analisis teknikal saham BEI, riset on-demand, dan pemantauan model ML.');
        });

        $res = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('kamu bisa bantu apa?', 'upd_cap_1', '102')
        );

        $this->assertEquals('success', $res['status']);
        $this->assertEquals('GENERAL_CONVERSATION', $res['intent']);
        $this->assertStringContainsString('analisis teknikal saham BEI', $res['reply_text']);
    }

    /**
     * 3. Test follow-up question regarding previous ticker context.
     */
    public function test_follow_up_question_retains_context(): void
    {
        // Seed an earlier conversation about SMRA
        TelegramConversation::create([
            'telegram_update_id' => 'upd_prev_smra',
            'chat_id' => '5923895922',
            'user_id' => '5923895922',
            'inbound_text' => 'analisis emiten SMRA',
            'detected_intent' => 'QUERY_TICKER_STATUS',
            'outbound_reply_text' => 'ANALISIS EMITEN — SMRA: Harga Rp 260, status NO_SIGNAL_ABSTAIN.',
            'response_time_ms' => 120,
        ]);

        $this->mock(AiConversationService::class, function ($mock) {
            $mock->shouldReceive('handleConversation')
                ->once()
                ->andReturn('Untuk SMRA, belum ada rilis berita signifikan dalam 7 hari terakhir yang dapat menjadi katalis.');
        });

        $res = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('berita apa yang mendukung kesimpulan tadi?', 'upd_fol_1', '103')
        );

        $this->assertEquals('success', $res['status']);
        $this->assertEquals('GENERAL_CONVERSATION', $res['intent']);
        $this->assertStringContainsString('Untuk SMRA', $res['reply_text']);
    }

    /**
     * 4. Test AI disabled flag fallback in conversation service.
     */
    public function test_ai_disabled_flag_fallback(): void
    {
        config(['services.ai_router.on_demand_enabled' => false]);
        $service = new AiConversationService;

        $provenance = [];
        $reply = $service->handleConversation('hai apa kabar', '5923895922', '5923895922', null, $provenance);

        $this->assertEquals('DISABLED_BY_FLAG', $provenance['ai_status']);
        $this->assertStringContainsString('Layanan AI percakapan interaktif saat ini sedang dinonaktifkan', $reply);
    }

    /**
     * 5. Test zero broker calls and zero synthetic data during conversations.
     */
    public function test_zero_broker_calls_and_zero_synthetic_data(): void
    {
        $this->mock(AiConversationService::class, function ($mock) {
            $mock->shouldReceive('handleConversation')
                ->once()
                ->andReturn('Jawaban percakapan normal.');
        });

        $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('beli portofolio sekarang', 'upd_order_attempt', '105')
        );

        $this->assertEquals(0, PredictionSignal::where('signal_status', 'LIVE_BROKER_ORDER')->count());
        $this->assertEquals(0, MarketObservation::where('source', 'SYNTHETIC_GENERATOR')->count());
    }

    /**
     * 6. Test unauthorized chat ID is rejected.
     */
    public function test_unauthorized_chat_id_is_rejected(): void
    {
        $hackerUpdate = [
            'update_id' => 'hack_1',
            'message' => [
                'message_id' => '999',
                'from' => ['id' => 11223344, 'username' => 'Intruder'],
                'chat' => ['id' => 11223344, 'type' => 'private'],
                'text' => 'hai',
                'date' => time(),
            ],
        ];

        $res = $this->twoWayService->handleInboundMessage($hackerUpdate);
        $this->assertEquals('rejected', $res['status']);
        $this->assertEquals(0, TelegramConversation::where('chat_id', '11223344')->count());
    }

    /**
     * 7. Test consultative query schedules durable async job, returns neutral acknowledgment, and executes via worker.
     */
    public function test_consultative_query_invokes_tool_loop_and_evidence_persistence(): void
    {
        $this->mock(AiConversationService::class, function ($mock) {
            $mock->shouldReceive('handleConversation')
                ->once()
                ->andReturn('Harga PTRO turun, namun valuasi belum tentu murah. Diperlukan analisis fundamental lebih lanjut.');
        });

        // 1. Inbound handler receives consultative query -> returns neutral acknowledgment immediately
        $res = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('PTRO sedang diskon, apakah layak dipertimbangkan?', 'upd_consult_ptro', '501')
        );

        $this->assertEquals('success', $res['status']);
        $this->assertEquals('GENERAL_CONVERSATION', $res['intent']);
        $this->assertStringContainsString('Permintaan analisis diterima dan sedang diproses', $res['reply_text']);

        // Assert job is persisted in scheduled_telegram_jobs with request_type ASYNC_CONSULTATION
        $job = ScheduledTelegramJob::where('telegram_update_id', 'upd_consult_ptro')->first();
        $this->assertNotNull($job);
        $this->assertEquals('ASYNC_CONSULTATION', $job->request_type);
        $this->assertEquals('PENDING', $job->status);

        // 2. Background worker processes consultation queue
        Artisan::call('hermes:process-consultation-queue', ['--mock' => true]);

        $job->refresh();
        $this->assertEquals('COMPLETED', $job->status);
        $this->assertEquals('SENT', $job->delivery_status);
        $this->assertNotNull($job->outbox_id);

        $this->assertDatabaseHas('telegram_conversations', [
            'telegram_update_id' => 'upd_consult_ptro',
            'detected_intent' => 'GENERAL_CONVERSATION',
        ]);
    }

    /**
     * 8. Test search_latest_disclosures_and_filings tool execution and evidence persistence with deterministic accounting metrics.
     */
    public function test_disclosures_search_tool_grounding_and_accounting_integrity(): void
    {
        $executor = app(AiToolExecutorService::class);
        $provenance = [];

        $output = $executor->executeTool(
            'search_latest_disclosures_and_filings',
            ['ticker' => 'PTRO'],
            '5923895922',
            '5923895922',
            $provenance
        );

        $this->assertStringContainsString('FOUND_AND_FETCHED', $output);
        $this->assertStringContainsString('Financial-Statements-30062026-PTRO.pdf', $output);
        $this->assertEquals('PTRO', $provenance['disclosures_search']['ticker']);
        $this->assertEquals('FOUND_AND_FETCHED', $provenance['disclosures_search']['retrieval_status']);
        $this->assertNotNull($provenance['disclosures_search']['evidence_id']);

        $this->assertDatabaseHas('evidence_snapshots', [
            'id' => $provenance['disclosures_search']['evidence_id'],
            'ticker' => 'PTRO',
            'market_regime' => 'VERIFIED_FINANCIAL_FILING_RETRIEVAL',
        ]);

        // Verify non-PTRO tickers dynamically query checked sources without hardcoded PTRO results
        $provenanceBbca = [];
        $outputBbca = $executor->executeTool(
            'search_latest_disclosures_and_filings',
            ['ticker' => 'BBCA'],
            '5923895922',
            '5923895922',
            $provenanceBbca
        );

        $this->assertStringContainsString('SOURCE_DISCOVERY_COMPLETED_NO_DOCUMENTS untuk emiten BBCA', $outputBbca);
        $this->assertEquals('BBCA', $provenanceBbca['disclosures_search']['ticker']);
        $this->assertEquals('SOURCE_DISCOVERY_COMPLETED_NO_DOCUMENTS', $provenanceBbca['disclosures_search']['retrieval_status']);
    }

    /**
     * 10. Test concurrent inquiries, duplicate updates, and worker restart resilience.
     */
    public function test_async_consultation_concurrency_deduplication_and_worker_resilience(): void
    {
        $this->mock(AiConversationService::class, function ($mock) {
            $mock->shouldReceive('handleConversation')
                ->andReturn('Hasil analisis evaluasi PTRO lengkap dengan bukti laporan resmi.');
        });

        // 1. Initial inbound consultative inquiry
        $res1 = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('Bagaimana valuasi PTRO, apakah diskon dan layak dibeli?', 'upd_async_ptro_1', '701')
        );
        $this->assertEquals('success', $res1['status']);
        $this->assertStringContainsString('Permintaan analisis diterima dan sedang diproses', $res1['reply_text']);

        // 2. Duplicate update_id replay should be idempotently deduplicated without new job
        $resDup = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('Bagaimana valuasi PTRO, apakah diskon dan layak dibeli?', 'upd_async_ptro_1', '701')
        );
        $this->assertTrue($resDup['is_duplicate']);

        $jobsCount = ScheduledTelegramJob::where('telegram_update_id', 'upd_async_ptro_1')->count();
        $this->assertEquals(1, $jobsCount);

        // 3. Worker executes job, delivering result to outbox
        Artisan::call('hermes:process-consultation-queue', ['--mock' => true]);

        $job = ScheduledTelegramJob::where('telegram_update_id', 'upd_async_ptro_1')->first();
        $this->assertEquals('COMPLETED', $job->status);
        $this->assertEquals('SENT', $job->delivery_status);
        $this->assertNotNull($job->analysis_completed_at);
        $this->assertNotNull($job->sent_at);

        // Verify outbox record exists and was processed
        $outboxItem = TelegramOutbox::find($job->outbox_id);
        $this->assertNotNull($outboxItem);
        $this->assertEquals('SENT', $outboxItem->status);

        // 4. Repeated user query after completion creates a new fresh consultation job
        $resFresh = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('Bagaimana valuasi PTRO, apakah diskon dan layak dibeli?', 'upd_async_ptro_2', '702')
        );
        $this->assertEquals('success', $resFresh['status']);
        $this->assertStringContainsString('Permintaan analisis diterima dan sedang diproses', $resFresh['reply_text']);

        // 5. Test missed deadline triggers user failure notification in outbox
        $staleJob = ScheduledTelegramJob::create([
            'chat_id' => '5923895922',
            'telegram_user_id' => '5923895922',
            'telegram_update_id' => 'upd_stale_deadline',
            'inbound_message_id' => '888',
            'original_prompt' => 'Analisis valuasi saham terlewat',
            'request_type' => 'ASYNC_CONSULTATION',
            'scheduled_at' => now()->subMinutes(20),
            'deadline_at' => now()->subMinutes(5),
            'status' => 'PENDING',
            'idempotency_key' => 'stale_deadline_test_'.uniqid(),
        ]);

        Artisan::call('hermes:process-consultation-queue', ['--mock' => true]);

        $staleJob->refresh();
        $this->assertEquals('MISSED', $staleJob->status);
        $this->assertEquals('FAILED', $staleJob->delivery_status);

        $this->assertDatabaseHas('telegram_outbox', [
            'chat_id' => '5923895922',
            'reply_to_message_id' => '888',
            'status' => 'QUEUED',
        ]);

        // 6. Test stale dead processing job is safely reclaimed to PENDING
        $deadJob = ScheduledTelegramJob::create([
            'chat_id' => '5923895922',
            'telegram_user_id' => '5923895922',
            'telegram_update_id' => 'upd_dead_consultation',
            'inbound_message_id' => '889',
            'original_prompt' => 'Analisis valuasi mati',
            'request_type' => 'ASYNC_CONSULTATION',
            'scheduled_at' => now()->subMinutes(5),
            'status' => 'PROCESSING',
            'worker_pid' => 999999, // non-existent PID
            'heartbeat_at' => now()->subMinutes(5),
            'idempotency_key' => 'dead_consultation_test_'.uniqid(),
        ]);

        Artisan::call('hermes:process-consultation-queue', ['--mock' => true]);

        $deadJob->refresh();
        $this->assertEquals('COMPLETED', $deadJob->status);
        $this->assertEquals('SENT', $deadJob->delivery_status);
    }

    /**
     * 10. Test generic multi-ticker pipeline support (DEWA, BBCA, invalid ticker).
     */
    public function test_generic_multi_ticker_pipeline_support_without_hardcoded_ticker(): void
    {
        $this->mock(AiConversationService::class, function ($mock) {
            $mock->shouldReceive('handleConversation')
                ->andReturn('Hasil analisis umum emiten BEI berbasis bukti data pasar.');
        });

        // Test DEWA
        $resDewa = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('Analisis DEWA: valuasi dan risiko', 'upd_dewa_gen', '901')
        );
        $this->assertEquals('success', $resDewa['status']);
        $this->assertStringContainsString('Permintaan analisis diterima dan sedang diproses', $resDewa['reply_text']);

        // Test BBCA
        $resBbca = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('Bagaimana valuasi BBCA sekarang?', 'upd_bbca_gen', '902')
        );
        $this->assertEquals('success', $resBbca['status']);
        $this->assertStringContainsString('Permintaan analisis diterima dan sedang diproses', $resBbca['reply_text']);

        // Test Non-existent / invalid ticker - rejected without making consultation job
        $resInvalid = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('Analisis ZZZZ: valuasi dan risiko', 'upd_invalid_gen', '903')
        );
        $this->assertEquals('success', $resInvalid['status']);
        $this->assertStringNotContainsString('Permintaan analisis diterima dan sedang diproses', $resInvalid['reply_text']);
        $this->assertStringContainsString('KLARIFIKASI CAKUPAN INSTRUMEN', $resInvalid['reply_text']);
    }

    /**
     * 11. End-to-end generic consultation pipeline with fake transport:
     * inbound -> acknowledgment -> worker claim -> tool execution -> synthesis -> outbox -> sent.
     */
    public function test_full_generic_consultation_end_to_end_pipeline(): void
    {
        $this->mock(AiConversationService::class, function ($mock) {
            $mock->shouldReceive('handleConversation')
                ->andReturn('HASIL ANALISIS: Valuasi dan risiko emiten DEWA berdasarkan data pasar.');
        });

        // 1. Inbound update received
        $inboundRes = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('Analisis DEWA: bagaimana valuasinya?', 'upd_e2e_dewa_1', '888')
        );

        $this->assertEquals('success', $inboundRes['status']);
        $this->assertStringContainsString('Permintaan analisis diterima dan sedang diproses', $inboundRes['reply_text']);

        // 2. ScheduledTelegramJob created as PENDING
        $job = ScheduledTelegramJob::where('telegram_update_id', 'upd_e2e_dewa_1')->first();
        $this->assertNotNull($job);
        $this->assertEquals('PENDING', $job->status);
        $this->assertEquals('ASYNC_CONSULTATION', $job->request_type);

        // 3. Worker execution with fake transport (mockOnly: true)
        $assistant = app(TelegramMarketAssistantService::class);
        $execRes = $assistant->executeScheduledJob($job, mockOnly: true);

        $this->assertEquals('success', $execRes['status']);
        $this->assertNotNull($execRes['outbound_message_id']);

        // 4. Job completed and Outbox SENT with mock message id
        $job->refresh();
        $this->assertEquals('COMPLETED', $job->status);
        $this->assertEquals('SENT', $job->delivery_status);
        $this->assertNotNull($job->analysis_completed_at);
        $this->assertNotNull($job->sent_at);
        $this->assertNotNull($job->outbound_message_id);

        $outbox = TelegramOutbox::find($job->outbox_id);
        $this->assertNotNull($outbox);
        $this->assertEquals('SENT', $outbox->status);
        $this->assertEquals($job->outbound_message_id, $outbox->telegram_message_id);
    }
}
