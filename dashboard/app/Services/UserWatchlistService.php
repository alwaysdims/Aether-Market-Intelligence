<?php

namespace App\Services;

use App\Models\MarketObservation;
use App\Models\UserWatchlist;
use Carbon\Carbon;

class UserWatchlistService
{
    protected BeiUniverseRegistry $registry;

    public function __construct(BeiUniverseRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Add a ticker to user's watchlist idempotently.
     */
    public function addTicker(string $userId, string $chatId, string $ticker, ?string $notes = null): array
    {
        $ticker = strtoupper(trim($ticker));

        // 1. Verify and resolve BEI instrument
        $resolution = $this->registry->resolveInstrument($ticker);
        if (! $resolution['is_valid']) {
            return [
                'success' => false,
                'ticker' => $ticker,
                'message' => $resolution['message'] ?? "Simbol '{$ticker}' tidak terverifikasi sebagai emiten BEI resmi.",
            ];
        }

        $companyName = $resolution['company_name'];

        // 2. Idempotent storage
        $existing = UserWatchlist::where('user_id', $userId)
            ->where('ticker', $ticker)
            ->first();

        if ($existing && $existing->is_active) {
            $latestObs = MarketObservation::where('ticker', $ticker)->latest('observation_time')->first();
            $dataStatus = $latestObs ? "Tersedia EOD ({$latestObs->bar_date->format('d M Y')})" : 'Belum diunduh (On-Demand Ready)';

            return [
                'success' => true,
                'is_new' => false,
                'ticker' => $ticker,
                'company_name' => $companyName,
                'data_status' => $dataStatus,
                'message' => "Emiten {$ticker} ({$companyName}) sudah ada di watchlist Anda.",
            ];
        }

        $watchlist = UserWatchlist::updateOrCreate(
            ['user_id' => $userId, 'ticker' => $ticker],
            [
                'chat_id' => $chatId,
                'company_name' => $companyName,
                'notes' => $notes,
                'is_active' => true,
                'added_at' => Carbon::now('Asia/Jakarta'),
            ]
        );

        $latestObs = MarketObservation::where('ticker', $ticker)->latest('observation_time')->first();
        $dataStatus = $latestObs ? "Tersedia EOD ({$latestObs->bar_date->format('d M Y')})" : 'Belum diunduh (On-Demand Ready)';

        return [
            'success' => true,
            'is_new' => true,
            'ticker' => $ticker,
            'company_name' => $companyName,
            'data_status' => $dataStatus,
            'message' => "Berhasil menambahkan {$ticker} ({$companyName}) ke watchlist Anda.",
        ];
    }

    /**
     * List user watchlist with latest observed market data.
     */
    public function listWatchlist(string $userId): array
    {
        $items = UserWatchlist::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('added_at', 'asc')
            ->get();

        $result = [];
        foreach ($items as $item) {
            $latestObs = MarketObservation::where('ticker', $item->ticker)->latest('observation_time')->first();
            $result[] = [
                'ticker' => $item->ticker,
                'company_name' => $item->company_name,
                'added_at' => $item->added_at ? $item->added_at->format('d M Y') : 'N/A',
                'last_close' => $latestObs ? $latestObs->close_price : null,
                'last_date' => $latestObs ? $latestObs->bar_date->format('d M Y') : 'N/A',
            ];
        }

        return $result;
    }

    /**
     * Format watchlist for Telegram message display.
     */
    public function formatWatchlistMessage(string $userId): string
    {
        $list = $this->listWatchlist($userId);
        if (empty($list)) {
            return "WATCHLIST PENGGUNA AETHER\n"
                ."──────────────────────────────────────────\n"
                ."Watchlist Anda masih kosong.\n\n"
                ."Gunakan perintah bahasa alami untuk menambahkan emiten, contoh:\n"
                ."• \"tambahkan PTRO ke watchlist\"\n"
                ."• \"masukkan ICBP ke daftar pantau\"\n"
                ."• \"tambah BBCA\"\n"
                .'──────────────────────────────────────────';
        }

        $lines = [
            'WATCHLIST PENGGUNA AETHER ('.count($list).' Emiten)',
            '──────────────────────────────────────────',
        ];

        foreach ($list as $idx => $item) {
            $num = $idx + 1;
            $priceStr = $item['last_close'] !== null ? 'Rp '.number_format($item['last_close'], 0, ',', '.') : 'Belum diunduh';
            $dateStr = $item['last_date'] !== 'N/A' ? " (EOD {$item['last_date']})" : '';
            $lines[] = "{$num}. {$item['ticker']} — {$item['company_name']}";
            $lines[] = "   Harga: {$priceStr}{$dateStr}";
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = 'Ketik "analisis <TICKER>" untuk melihat data & narasi lengkap.';

        return implode("\n", $lines);
    }
}
