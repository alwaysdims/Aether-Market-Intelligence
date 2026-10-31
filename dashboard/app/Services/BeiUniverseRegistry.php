<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BeiUniverseRegistry
{
    /**
     * Initial 10 constituent tickers with validated ML models & shadow signal tracking.
     */
    public const VALIDATED_ML_UNIVERSE = [
        'BBCA' => 'PT Bank Central Asia Tbk',
        'BBRI' => 'PT Bank Rakyat Indonesia (Persero) Tbk',
        'BMRI' => 'PT Bank Mandiri (Persero) Tbk',
        'BBNI' => 'PT Bank Negara Indonesia (Persero) Tbk',
        'ASII' => 'PT Astra International Tbk',
        'TLKM' => 'PT Telkom Indonesia (Persero) Tbk',
        'MAPI' => 'PT Mitra Adiperkasa Tbk',
        'ARTO' => 'PT Bank Jago Tbk',
        'MEDC' => 'PT Medco Energi Internasional Tbk',
        'SMRA' => 'PT Summarecon Agung Tbk',
    ];

    /**
     * Known BEI / IDX equity constituents with official corporate registry names.
     */
    public const KNOWN_BEI_ISSUERS = [
        // Validated Universe
        'BBCA' => 'PT Bank Central Asia Tbk',
        'BBRI' => 'PT Bank Rakyat Indonesia (Persero) Tbk',
        'BMRI' => 'PT Bank Mandiri (Persero) Tbk',
        'BBNI' => 'PT Bank Negara Indonesia (Persero) Tbk',
        'ASII' => 'PT Astra International Tbk',
        'TLKM' => 'PT Telkom Indonesia (Persero) Tbk',
        'MAPI' => 'PT Mitra Adiperkasa Tbk',
        'ARTO' => 'PT Bank Jago Tbk',
        'MEDC' => 'PT Medco Energi Internasional Tbk',
        'SMRA' => 'PT Summarecon Agung Tbk',

        // Benchmark
        '^JKSE' => 'Indeks Harga Saham Gabungan (IHSG)',

        // Consumer Goods & Retail
        'ICBP' => 'PT Indofood CBP Sukses Makmur Tbk',
        'INDF' => 'PT Indofood Sukses Makmur Tbk',
        'UNVR' => 'PT Unilever Indonesia Tbk',
        'MYOR' => 'PT Mayora Indah Tbk',
        'CMRY' => 'PT Cisarua Mountain Dairy Tbk',
        'SIDO' => 'PT Industri Jamu dan Farmasi Sido Muncul Tbk',
        'KLBF' => 'PT Kalbe Farma Tbk',
        'CPIN' => 'PT Charoen Pokphand Indonesia Tbk',
        'JPFA' => 'PT Japfa Comfeed Indonesia Tbk',
        'ACES' => 'PT Aspirasi Hidup Indonesia Tbk (ex-Ace Hardware)',
        'MAPA' => 'PT MAP Aktif Adiperkasa Tbk',
        'ERAA' => 'PT Erajaya Swasembada Tbk',
        'AMRT' => 'PT Sumber Alfaria Trijaya Tbk',

        // Technology & Digital
        'GOTO' => 'PT GoTo Gojek Tokopedia Tbk',
        'BUKA' => 'PT Bukalapak.com Tbk',
        'EMTK' => 'PT Elang Mahkota Teknologi Tbk',
        'SCMA' => 'PT Surya Citra Media Tbk',
        'MTDL' => 'PT Metrodata Electronics Tbk',

        // Banking & Financial Services
        'BRIS' => 'PT Bank Syariah Indonesia Tbk',
        'BBTN' => 'PT Bank Tabungan Negara (Persero) Tbk',
        'BNGA' => 'PT Bank CIMB Niaga Tbk',
        'BDMN' => 'PT Bank Danamon Indonesia Tbk',
        'NISP' => 'PT Bank OCBC NISP Tbk',
        'BJBR' => 'PT Bank Pembangunan Daerah Jawa Barat dan Banten Tbk',
        'BJTM' => 'PT Bank Pembangunan Daerah Jawa Timur Tbk',
        'BTPN' => 'PT Bank SMBC Indonesia Tbk',
        'BBYB' => 'PT Bank Neo Commerce Tbk',
        'AGRO' => 'PT Bank Raya Indonesia Tbk',

        // Energy, Mining & Commodities
        'PTRO' => 'PT Petrosea Tbk',
        'DEWA' => 'PT Darma Henwa Tbk',
        'ADRO' => 'PT Adaro Energy Indonesia Tbk',
        'AMMN' => 'PT Amman Mineral Internasional Tbk',
        'ANTM' => 'PT Aneka Tambang Tbk',
        'INCO' => 'PT Vale Indonesia Tbk',
        'PTBA' => 'PT Bukit Asam Tbk',
        'PGAS' => 'PT Perusahaan Gas Negara Tbk',
        'ITMG' => 'PT Indo Tambangraya Megah Tbk',
        'HRUM' => 'PT Harum Energy Tbk',
        'INDY' => 'PT Indika Energy Tbk',
        'DOID' => 'PT Delta Dunia Makmur Tbk',
        'MBMA' => 'PT Merdeka Battery Materials Tbk',
        'MDKA' => 'PT Merdeka Copper Gold Tbk',
        'NCKL' => 'PT Trimegah Bangun Persada Tbk',

        // Basic Materials & Chemical
        'BRPT' => 'PT Barito Pacific Tbk',
        'BREN' => 'PT Barito Renewables Energy Tbk',
        'TPIA' => 'PT Chandra Asri Pacific Tbk',
        'INKP' => 'PT Indah Kiat Pulp & Paper Tbk',
        'TKIM' => 'PT Pabrik Kertas Tjiwi Kimia Tbk',
        'SMGR' => 'PT Semen Indonesia (Persero) Tbk',
        'INTP' => 'PT Indocement Tunggal Prakarsa Tbk',

        // Infrastructure & Telecommunications
        'ISAT' => 'PT Indosat Tbk',
        'EXCL' => 'PT XL Axiata Tbk',
        'TOWR' => 'PT Sarana Menara Nusantara Tbk',
        'TBIG' => 'PT Tower Bersama Infrastructure Tbk',
        'JSMR' => 'PT Jasa Marga (Persero) Tbk',
        'UNTR' => 'PT United Tractors Tbk',

        // Property & Real Estate
        'BSDE' => 'PT Bumi Serpong Damai Tbk',
        'CTRA' => 'PT Ciputra Development Tbk',
        'PWON' => 'PT Pakuwon Jati Tbk',
        'ASRI' => 'PT Alam Sutera Realty Tbk',
        'LPKR' => 'PT Lippo Karawaci Tbk',
        'KIJA' => 'PT Kawasan Industri Jababeka Tbk',

        // Healthcare
        'MIKA' => 'PT Mitra Keluarga Karyasehat Tbk',
        'HEAL' => 'PT Medikaloka Hermina Tbk',
        'SILO' => 'PT Siloam International Hospitals Tbk',
    ];

    /**
     * Common foreign / non-IDX tickers to block immediately with clear feedback.
     */
    public const KNOWN_FOREIGN_INSTRUMENTS = [
        'AAPL', 'TSLA', 'MSFT', 'NVDA', 'GOOG', 'GOOGL', 'AMZN', 'META',
        'BTC', 'ETH', 'SOL', 'USDT', 'BNB', 'XRP', 'DOGE',
        'SPY', 'QQQ', 'DIA', 'VIX',
    ];

    /**
     * Normalize query and extract verified IDX ticker.
     */
    public function extractAndNormalizeTicker(string $text): array
    {
        $clean = strtoupper(trim($text));

        // 1. Direct benchmark check
        if (preg_match('/\b(IHSG|\^JKSE)\b/i', $text)) {
            return [
                'valid' => true,
                'ticker' => '^JKSE',
                'company_name' => self::KNOWN_BEI_ISSUERS['^JKSE'],
                'exchange' => 'IDX',
                'is_ml_validated' => false,
                'is_benchmark' => true,
            ];
        }

        // 2. Foreign / Crypto rejection check
        foreach (self::KNOWN_FOREIGN_INSTRUMENTS as $foreign) {
            if (preg_match('/\b'.preg_quote($foreign, '/').'\b/i', $text)) {
                return [
                    'valid' => false,
                    'error_type' => 'FOREIGN_OR_NON_IDX',
                    'ticker' => $foreign,
                    'message' => "Instrumen '{$foreign}' adalah instrumen bursa luar negeri/kripto. Aether Market Intelligence beroperasi eksklusif untuk instrumen resmi Bursa Efek Indonesia (BEI/IDX).",
                ];
            }
        }

        // 3. Known BEI catalog check
        foreach (self::KNOWN_BEI_ISSUERS as $ticker => $name) {
            if (preg_match('/\b'.preg_quote($ticker, '/').'\b/i', $text)) {
                return [
                    'valid' => true,
                    'ticker' => $ticker,
                    'company_name' => $name,
                    'exchange' => 'IDX',
                    'is_ml_validated' => array_key_exists($ticker, self::VALIDATED_ML_UNIVERSE),
                    'is_benchmark' => ($ticker === '^JKSE'),
                ];
            }
        }

        // 4. Pattern check for generic 4-letter uppercase BEI tickers (e.g. "analisis emiten BBTN", "analisis PGEO")
        if (preg_match('/\b(?:emiten|saham|analisis|analisa|kondisi|bedah)?\s*([A-Z]{4})\b/i', $text, $matches)) {
            $candidate = strtoupper($matches[1]);
            // Skip common Indonesian/English words that happen to be 4 letters
            $ignoreWords = [
                'HARI', 'SAAT', 'DARI', 'AKAN', 'BISA', 'TIDAK', 'INFO', 'POST', 'CHAT', 'USER', 'DATA',
                'RUPS', 'IHGS', 'BANK', 'NEWS', 'YANG', 'TADI', 'LALU', 'DULU', 'KITA', 'KAMI', 'SAYA',
                'KAMU', 'PADA', 'OLEH', 'BUAT', 'JUGA', 'AGAR', 'BAGI', 'BILA', 'MAKA', 'KATA', 'APEL',
                'IKUT', 'MAAF', 'SIAP', 'AUTO', 'OPEN', 'HIGH', 'CALL', 'PULL', 'TEST',
            ];
            if (! in_array($candidate, $ignoreWords, true)) {
                $isKnown = array_key_exists($candidate, self::KNOWN_BEI_ISSUERS);
                $hasObservation = false;
                try {
                    $hasObservation = DB::table('market_observations')
                        ->where('ticker', $candidate)
                        ->exists();
                } catch (\Throwable $e) {
                    $hasObservation = false;
                }

                if ($isKnown || $hasObservation) {
                    return [
                        'valid' => true,
                        'ticker' => $candidate,
                        'company_name' => self::KNOWN_BEI_ISSUERS[$candidate] ?? "Emiten Terdaftar BEI ({$candidate})",
                        'exchange' => 'IDX',
                        'is_ml_validated' => array_key_exists($candidate, self::VALIDATED_ML_UNIVERSE),
                        'is_benchmark' => false,
                        'resolution_provenance' => [
                            'source' => $isKnown ? 'KNOWN_BEI_ISSUERS_CATALOG' : 'DATABASE_MARKET_OBSERVATIONS',
                            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
                        ],
                    ];
                }
            }
        }

        return [
            'valid' => false,
            'error_type' => 'UNRECOGNIZED_OR_AMBIGUOUS',
            'ticker' => null,
            'message' => 'Kode emiten tidak ditemukan atau tidak spesifik. Mohon sertakan 4 huruf kode saham BEI (contoh: BBCA, SMRA, ICBP, UNVR, GOTO).',
        ];
    }

    /**
     * Parse complete request contract (Section B).
     */
    public function parseRequestContract(string $text, ?string $userId = null, ?string $chatId = null, ?string $messageId = null): array
    {
        $tickerInfo = $this->extractAndNormalizeTicker($text);
        if (! $tickerInfo['valid']) {
            return $tickerInfo;
        }

        $lower = strtolower($text);

        // Analysis Type
        $analysisType = 'current_condition';
        if (str_contains($lower, 'fundamental') || str_contains($lower, 'laporan keuangan') || str_contains($lower, 'kinerja keuangan') || str_contains($lower, 'valuasi') || str_contains($lower, 'pbv') || str_contains($lower, 'roe')) {
            $analysisType = 'fundamental';
        } elseif (str_contains($lower, 'berita') || str_contains($lower, 'news') || str_contains($lower, 'kabar')) {
            $analysisType = 'news';
        } elseif (str_contains($lower, 'historis') || str_contains($lower, 'riwayat') || str_contains($lower, 'sejarah')) {
            $analysisType = 'historical';
        } elseif (str_contains($lower, 'prediksi') || str_contains($lower, 'sinyal') || str_contains($lower, 'signal') || str_contains($lower, 'arah')) {
            $analysisType = 'prediction';
        }

        // Data Type Requested
        $dataTypeRequested = 'eod';
        if (str_contains($lower, 'intraday') || str_contains($lower, 'menit') || str_contains($lower, 'jam')) {
            $dataTypeRequested = 'delayed'; // Public free tier has 15m delay
        } elseif (str_contains($lower, 'realtime') || str_contains($lower, 'real-time') || str_contains($lower, 'live')) {
            $dataTypeRequested = 'realtime'; // Clarified as delayed on free tier
        }

        // Horizon
        $horizon = 'multi_day';
        if (str_contains($lower, 'hari ini') || str_contains($lower, 'intraday')) {
            $horizon = 'intraday';
        } elseif (str_contains($lower, 'swing') || str_contains($lower, 'mingguan') || str_contains($lower, '5 hari')) {
            $horizon = '5_days';
        }

        return [
            'valid' => true,
            'ticker' => $tickerInfo['ticker'],
            'company_name' => $tickerInfo['company_name'],
            'exchange' => $tickerInfo['exchange'],
            'is_ml_validated' => $tickerInfo['is_ml_validated'],
            'is_benchmark' => $tickerInfo['is_benchmark'],
            'analysis_type' => $analysisType,
            'data_type_requested' => $dataTypeRequested,
            'horizon' => $horizon,
            'as_of_time' => now('Asia/Jakarta'),
            'user_id' => $userId,
            'chat_id' => $chatId,
            'inbound_message_id' => $messageId,
        ];
    }

    /**
     * Resolve BEI instrument dynamically.
     */
    public function resolveInstrument(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '^JKSE' || $ticker === 'IHSG') {
            return [
                'is_valid' => true,
                'ticker' => '^JKSE',
                'company_name' => self::KNOWN_BEI_ISSUERS['^JKSE'],
                'exchange' => 'IDX',
                'is_ml_validated' => false,
                'is_benchmark' => true,
            ];
        }

        if (in_array($ticker, self::KNOWN_FOREIGN_INSTRUMENTS, true)) {
            return [
                'is_valid' => false,
                'error_type' => 'FOREIGN_OR_NON_IDX',
                'ticker' => $ticker,
                'company_name' => null,
                'message' => "Instrumen '{$ticker}' adalah instrumen bursa luar negeri/kripto. Aether hanya memproses emiten resmi Bursa Efek Indonesia (BEI/IDX).",
            ];
        }

        if (array_key_exists($ticker, self::KNOWN_BEI_ISSUERS)) {
            return [
                'is_valid' => true,
                'ticker' => $ticker,
                'company_name' => self::KNOWN_BEI_ISSUERS[$ticker],
                'exchange' => 'IDX',
                'is_ml_validated' => array_key_exists($ticker, self::VALIDATED_ML_UNIVERSE),
                'is_benchmark' => false,
            ];
        }

        // Check if 4 capital letters - validate against registered universe
        if (preg_match('/^[A-Z]{4}$/', $ticker)) {
            $isKnown = array_key_exists($ticker, self::KNOWN_BEI_ISSUERS);
            $hasObservation = false;
            try {
                $hasObservation = DB::table('market_observations')
                    ->where('ticker', $ticker)
                    ->exists();
            } catch (\Throwable $e) {
                $hasObservation = false;
            }

            if ($isKnown || $hasObservation) {
                return [
                    'is_valid' => true,
                    'ticker' => $ticker,
                    'company_name' => self::KNOWN_BEI_ISSUERS[$ticker] ?? "Emiten Terdaftar BEI ({$ticker})",
                    'exchange' => 'IDX',
                    'is_ml_validated' => array_key_exists($ticker, self::VALIDATED_ML_UNIVERSE),
                    'is_benchmark' => false,
                    'resolution_provenance' => [
                        'source' => $isKnown ? 'KNOWN_BEI_ISSUERS_CATALOG' : 'DATABASE_MARKET_OBSERVATIONS',
                        'checked_at' => now('Asia/Jakarta')->toIso8601String(),
                    ],
                ];
            }

            return [
                'is_valid' => false,
                'error_type' => 'UNREGISTERED_BEI_TICKER',
                'ticker' => $ticker,
                'company_name' => null,
                'message' => "Kode '{$ticker}' tidak terdaftar dalam semesta emiten BEI yang aktif atau tercatat di database.",
                'resolution_provenance' => [
                    'source' => 'REGISTRY_VALIDATION_FAILED',
                    'checked_at' => now('Asia/Jakarta')->toIso8601String(),
                ],
            ];
        }

        return [
            'is_valid' => false,
            'error_type' => 'INVALID_FORMAT',
            'ticker' => $ticker,
            'company_name' => null,
            'message' => "Kode emiten '{$ticker}' tidak valid. Mohon sertakan 4 huruf kode saham BEI.",
        ];
    }

    /**
     * Determine official IDX price fraction (tick size) and maximum price movement step.
     * Rules per BEI trading regulation:
     * - Papan Pemantauan Khusus: Rp 1 (berlaku untuk seluruh rentang harga, Auto Rejection 10%)
     * - Papan Reguler / Utama / Pengembangan:
     *   < Rp 200: Rp 1
     *   Rp 200 - < Rp 500: Rp 2
     *   Rp 500 - < Rp 2.000: Rp 5
     *   Rp 2.000 - < Rp 5.000: Rp 10
     *   >= Rp 5.000: Rp 25
     *
     * @return array{tick_size: int, board: string, rules: string}
     */
    public static function resolvePriceFraction(float $price, string $board = 'Utama'): array
    {
        $normBoard = strtoupper(trim($board));
        $isWatchlist = str_contains($normBoard, 'PEMANTAUAN') || str_contains($normBoard, 'KHUSUS');

        if ($isWatchlist) {
            return [
                'tick_size' => 1,
                'board' => 'PAPAN_PEMANTAUAN_KHUSUS',
                'rules' => 'Fraksi Rp 1 flat untuk seluruh rentang harga (Full Call Auction / Notasi Khusus BEI)',
            ];
        }

        if ($price < 200) {
            $tick = 1;
        } elseif ($price < 500) {
            $tick = 2;
        } elseif ($price < 2000) {
            $tick = 5;
        } elseif ($price < 5000) {
            $tick = 10;
        } else {
            $tick = 25;
        }

        return [
            'tick_size' => $tick,
            'board' => $normBoard,
            'rules' => "Fraksi Rp {$tick} (Pasar Reguler / Papan {$normBoard})",
        ];
    }

    /**
     * Get list of pure equity tickers (excluding indices).
     */
    public static function getEquityTickers(): array
    {
        return array_values(array_filter(array_keys(self::KNOWN_BEI_ISSUERS), fn ($t) => $t !== '^JKSE'));
    }

    /**
     * Get list of benchmark index tickers.
     */
    public static function getIndexTickers(): array
    {
        return ['^JKSE'];
    }

    /**
     * Total count of equity stocks (excluding index).
     */
    public static function countStocks(): int
    {
        return count(self::getEquityTickers());
    }

    /**
     * Total count of index benchmarks.
     */
    public static function countIndices(): int
    {
        return count(self::getIndexTickers());
    }
}
