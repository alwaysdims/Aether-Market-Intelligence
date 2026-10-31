<?php

namespace App\Console\Commands;

use App\Models\PredictionSignal;
use App\Models\TelegramOutbox;
use App\Services\TelegramFormattingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class HermesSmokeTestCommand extends Command
{
    protected $signature = 'hermes:smoke-test';

    protected $description = 'Dispatch the authorized single smoke test message via Telegram Outbox';

    public function handle(TelegramFormattingService $formatter): int
    {
        $this->info('=== AETHER MARKET INTELLIGENCE — TELEGRAM SMOKE TEST TAHAP I ===');

        // 1. Ambil data aktual dari PostgreSQL
        $totalSignals = PredictionSignal::count();
        $abstainCount = PredictionSignal::where('signal_status', 'NO_SIGNAL_ABSTAIN')->count();
        $directionalCount = PredictionSignal::where('signal_status', 'PUBLISHED_DIRECTIONAL')->count();
        $waktuAktualWib = Carbon::now('Asia/Jakarta')->format('d M Y, H:i').' WIB';

        $statusPrediksiDb = "{$totalSignals} Sinyal Forward Terdaftar ({$abstainCount} Abstain / NO_SIGNAL, {$directionalCount} Directional)";

        // 2. Bangun payload persis sesuai spesifikasi pengguna
        $payloadLines = [
            '🎯 AETHER — UJI KONEKSI TAHAP I',
            '',
            "Waktu: {$waktuAktualWib}",
            'Mode: Research-only',
            'Data: EOD, bukan realtime tick',
            "Status prediksi: {$statusPrediksiDb}",
            '',
            'Pesan keluar sedang diuji.',
            'Percakapan dua arah belum dinyatakan lulus.',
            '',
            'Silakan balas pesan ini:',
            '“Status sistem sekarang bagaimana?”',
            '',
            'Ini pesan pengujian, bukan sinyal saham.',
        ];
        $payloadText = implode("\n", $payloadLines);

        // 3. Masukkan ke tabel telegram_outbox
        $targetChatId = config('services.telegram.chat_id', '5923895922');
        $dedupKey = 'SMOKE_TEST_OUTBOUND_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));

        $outbox = TelegramOutbox::create([
            'dedup_key' => $dedupKey,
            'chat_id' => $targetChatId,
            'message_type' => 'SYSTEM_ALERT',
            'payload_text' => $payloadText,
            'status' => 'QUEUED',
            'retry_count' => 0,
            'metadata' => [
                'test_type' => 'smoke_test_tahap_i',
                'created_at_wib' => $waktuAktualWib,
                'signals_summary' => $statusPrediksiDb,
            ],
        ]);

        $this->line("Outbox ID      : {$outbox->id}");
        $this->line("Dedup Key      : {$outbox->dedup_key}");
        $this->line("Target Chat ID : {$targetChatId}");
        $this->line("Status Awal    : {$outbox->status}");
        $this->line('Memproses pengiriman via Hermes CLI transport...');

        // 4. Jalankan dispatcher outbox secara live
        $res = $formatter->processOutboxQueue(mockOnly: false);

        // 5. Muat ulang status dari database
        $outbox->refresh();

        $this->table(
            ['Atribut Smoke Test', 'Nilai Tercatat di DB'],
            [
                ['Outbox ID', $outbox->id],
                ['Target Chat ID', $outbox->chat_id],
                ['Telegram Message ID', $outbox->telegram_message_id ?? 'N/A'],
                ['Waktu Pengiriman', $outbox->sent_at ? Carbon::parse($outbox->sent_at)->setTimezone('Asia/Jakarta')->format('d M Y, H:i:s').' WIB' : 'N/A'],
                ['Status Delivery', $outbox->status],
                ['Dedup Key', $outbox->dedup_key],
                ['Error Message', $outbox->error_message ?? 'NONE'],
            ]
        );

        if ($outbox->status === 'SENT') {
            $this->info('✅ Pesan smoke test BERHASIL dikirim dan tercatat di database.');

            return 0;
        } else {
            $this->error("❌ Pengiriman smoke test belum berhasil atau berstatus {$outbox->status}.");

            return 1;
        }
    }
}
