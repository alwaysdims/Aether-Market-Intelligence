<?php

namespace App\Services;

use App\Models\UserPortfolio;
use App\Models\UserPortfolioItem;
use Carbon\Carbon;

class UserPortfolioService
{
    public function __construct(
        protected CanonicalMarketReadService $marketRead,
        protected BeiUniverseRegistry $registry
    ) {}

    /**
     * Get or create portfolio entity for an allowlisted user.
     */
    public function getOrCreatePortfolio(string $userId, ?string $chatId = null): UserPortfolio
    {
        return UserPortfolio::firstOrCreate(
            ['user_id' => $userId],
            [
                'chat_id' => $chatId ?: $userId,
                'cash_balance' => 0.00,
                'target_trading_pct' => 50.00,
                'target_investment_pct' => 50.00,
                'risk_profile' => 'MODERATE',
            ]
        );
    }

    /**
     * Calculate comprehensive deterministic metrics for user portfolio.
     */
    public function calculatePortfolioMetrics(string $userId): array
    {
        $portfolio = $this->getOrCreatePortfolio($userId);
        $items = $portfolio->items()->get();

        $cash = (float) $portfolio->cash_balance;
        $totalMarketValue = 0.0;
        $totalCostBasis = 0.0;
        $tradingValue = 0.0;
        $investmentValue = 0.0;
        $positions = [];

        foreach ($items as $item) {
            $ticker = strtoupper($item->ticker);
            $lots = (int) $item->lots;
            $shares = $lots * 100;
            $avgBuy = (float) $item->avg_buy_price;
            $costBasis = $shares * $avgBuy;
            $totalCostBasis += $costBasis;

            $latestObs = $this->marketRead->latest($ticker);
            $currentPrice = $latestObs ? (float) $latestObs->close_price : $avgBuy;
            $marketValue = $shares * $currentPrice;
            $unrealizedPnl = $marketValue - $costBasis;
            $pnlPct = $costBasis > 0 ? ($unrealizedPnl / $costBasis) * 100 : 0.0;

            $totalMarketValue += $marketValue;

            if (strtoupper($item->allocation_type) === 'INVESTMENT') {
                $investmentValue += $marketValue;
            } else {
                $tradingValue += $marketValue;
            }

            $positions[] = [
                'ticker' => $ticker,
                'company_name' => BeiUniverseRegistry::KNOWN_BEI_ISSUERS[$ticker] ?? $ticker,
                'allocation_type' => $item->allocation_type,
                'lots' => $lots,
                'shares' => $shares,
                'avg_buy_price' => $avgBuy,
                'current_price' => $currentPrice,
                'has_live_price' => $latestObs !== null,
                'cost_basis' => $costBasis,
                'market_value' => $marketValue,
                'unrealized_pnl' => $unrealizedPnl,
                'pnl_pct' => $pnlPct,
                'observation_id' => $latestObs?->id,
                'bar_date' => $latestObs?->bar_date?->toDateString(),
            ];
        }

        $totalEquity = $totalMarketValue + $cash;

        // Calculate weights and concentration
        $maxConcentration = 0.0;
        $maxConcentrationTicker = null;
        foreach ($positions as &$pos) {
            $weight = $totalEquity > 0 ? ($pos['market_value'] / $totalEquity) * 100 : 0.0;
            $pos['weight_pct'] = round($weight, 2);
            if ($weight > $maxConcentration) {
                $maxConcentration = $weight;
                $maxConcentrationTicker = $pos['ticker'];
            }
        }
        unset($pos);

        $actualTradingPct = $totalEquity > 0 ? ($tradingValue / $totalEquity) * 100 : 0.0;
        $actualInvestmentPct = $totalEquity > 0 ? ($investmentValue / $totalEquity) * 100 : 0.0;
        $actualCashPct = $totalEquity > 0 ? ($cash / $totalEquity) * 100 : 100.0;

        $totalUnrealizedPnl = $totalMarketValue - $totalCostBasis;
        $totalPnlPct = $totalCostBasis > 0 ? ($totalUnrealizedPnl / $totalCostBasis) * 100 : 0.0;

        return [
            'has_positions' => count($positions) > 0,
            'position_count' => count($positions),
            'cash_balance' => $cash,
            'total_cost_basis' => $totalCostBasis,
            'total_market_value' => $totalMarketValue,
            'total_equity' => $totalEquity,
            'total_unrealized_pnl' => $totalUnrealizedPnl,
            'total_pnl_pct' => $totalPnlPct,
            'target_trading_pct' => (float) $portfolio->target_trading_pct,
            'target_investment_pct' => (float) $portfolio->target_investment_pct,
            'actual_trading_pct' => round($actualTradingPct, 2),
            'actual_investment_pct' => round($actualInvestmentPct, 2),
            'actual_cash_pct' => round($actualCashPct, 2),
            'max_concentration_pct' => round($maxConcentration, 2),
            'max_concentration_ticker' => $maxConcentrationTicker,
            'positions' => $positions,
        ];
    }

    /**
     * Add or update a position in the portfolio deterministically.
     */
    public function upsertPosition(
        string $userId,
        string $ticker,
        int $lots,
        float $avgBuyPrice,
        string $allocationType = 'TRADING',
        ?string $notes = null
    ): array {
        $ticker = strtoupper(trim($ticker));
        $allocationType = strtoupper(trim($allocationType)) === 'INVESTMENT' ? 'INVESTMENT' : 'TRADING';

        if ($lots <= 0 || $avgBuyPrice <= 0) {
            return [
                'success' => false,
                'message' => 'Jumlah lot dan harga beli rata-rata harus lebih besar dari 0.',
            ];
        }

        $portfolio = $this->getOrCreatePortfolio($userId);

        $item = UserPortfolioItem::updateOrCreate(
            [
                'user_id' => $userId,
                'ticker' => $ticker,
                'allocation_type' => $allocationType,
            ],
            [
                'portfolio_id' => $portfolio->id,
                'lots' => $lots,
                'shares' => $lots * 100,
                'avg_buy_price' => $avgBuyPrice,
                'notes' => $notes,
            ]
        );

        return [
            'success' => true,
            'item' => $item,
            'message' => "Posisi {$ticker} ({$lots} lot @ Rp ".number_format($avgBuyPrice, 2, ',', '.').") berhasil dicatat untuk alokasi {$allocationType}.",
        ];
    }

    /**
     * Format a clean, grounded Indonesian consultation message.
     */
    public function formatPortfolioSummary(string $userId): string
    {
        $metrics = $this->calculatePortfolioMetrics($userId);
        $nowWib = Carbon::now('Asia/Jakarta')->format('d M Y, H:i').' WIB';

        $lines = [
            'KONSULTASI ANALITIS PORTOFOLIO SAHAM BEI',
            '──────────────────────────────────────────',
            "• Waktu Analisis : {$nowWib}",
            '• Preferensi Awal: Target 50% Trading / 50% Investasi (Konfigurasi Pengguna)',
        ];

        if (! $metrics['has_positions']) {
            $lines[] = '• Status Data    : Belum ada kepemilikan saham yang dicatat.';
            $lines[] = '• Komposisi Riil : Belum diketahui (menunggu input saham Anda).';
            $lines[] = '──────────────────────────────────────────';
            $lines[] = 'CARA MEMASUKKAN PORTOFOLIO:';
            $lines[] = 'Ketik langsung rincian saham Anda dalam format alami, contoh:';
            $lines[] = '• "Catat portofolio saya: BBCA 50 lot harga 5000 untuk investasi"';
            $lines[] = '• "Saya pegang PTRO 100 lot avg 3000 untuk swing trading"';
            $lines[] = '• "Kas saya Rp 50.000.000"';
            $lines[] = '──────────────────────────────────────────';
            $lines[] = 'Aether akan menghitung bobot posisi, floating PnL, konsentrasi risiko, serta rekomendasi penyeimbangan risiko tanpa transaksi broker.';

            return implode("\n", $lines);
        }

        $equityFmt = 'Rp '.number_format($metrics['total_equity'], 2, ',', '.');
        $costFmt = 'Rp '.number_format($metrics['total_cost_basis'], 2, ',', '.');
        $mktFmt = 'Rp '.number_format($metrics['total_market_value'], 2, ',', '.');
        $cashFmt = 'Rp '.number_format($metrics['cash_balance'], 2, ',', '.');
        $pnlSign = $metrics['total_unrealized_pnl'] >= 0 ? '+' : '';
        $pnlFmt = $pnlSign.'Rp '.number_format($metrics['total_unrealized_pnl'], 2, ',', '.')." ({$pnlSign}".number_format($metrics['total_pnl_pct'], 2, ',', '.').'%)';

        $lines[] = "• Total Nilai Portofolio : {$equityFmt}";
        $lines[] = "• Modal Pokok (Cost)     : {$costFmt}";
        $lines[] = "• Nilai Saham Pasar      : {$mktFmt}";
        $lines[] = "• Saldo Kas Tersimpan    : {$cashFmt} ({$metrics['actual_cash_pct']}%)";
        $lines[] = "• Total Floating PnL     : {$pnlFmt}";
        $lines[] = '──────────────────────────────────────────';
        $lines[] = 'ALOKASI AKTUAL VS TARGET:';
        $lines[] = "• Trading   : {$metrics['actual_trading_pct']}% (Target: {$metrics['target_trading_pct']}%)";
        $lines[] = "• Investasi : {$metrics['actual_investment_pct']}% (Target: {$metrics['target_investment_pct']}%)";
        $lines[] = "• Konsentrasi Tertinggi  : {$metrics['max_concentration_ticker']} ({$metrics['max_concentration_pct']}%)";
        $lines[] = '──────────────────────────────────────────';
        $lines[] = 'RINCIAN POSISI SAHAM:';

        foreach ($metrics['positions'] as $p) {
            $pnlPosSign = $p['unrealized_pnl'] >= 0 ? '+' : '';
            $posPnlStr = $pnlPosSign.'Rp '.number_format($p['unrealized_pnl'], 2, ',', '.')." ({$pnlPosSign}".number_format($p['pnl_pct'], 2, ',', '.').'%)';
            $lines[] = "• [{$p['allocation_type']}] {$p['ticker']} — {$p['lots']} lot ({$p['weight_pct']}% bobot)";
            $lines[] = '  Avg: Rp '.number_format($p['avg_buy_price'], 2, ',', '.').' | Close: Rp '.number_format($p['current_price'], 2, ',', '.')." | PnL: {$posPnlStr}";
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = 'EVALUASI RISIKO DETERMINISTIK:';
        if ($metrics['max_concentration_pct'] > 35.0) {
            $lines[] = "⚠️ Konsentrasi tinggi pada {$metrics['max_concentration_ticker']} ({$metrics['max_concentration_pct']}% > 35%). Disarankan diversifikasi untuk membatasi risiko fluktuasi tunggal.";
        } else {
            $lines[] = '✓ Diversifikasi aset terkendali (konsentrasi saham maksimal < 35%).';
        }
        if (abs($metrics['actual_trading_pct'] - $metrics['target_trading_pct']) > 20.0) {
            $lines[] = '⚠️ Deviasi alokasi trading melebihi 20% dari target 50/50. Pertimbangkan penyesuaian porsi bertahap.';
        } else {
            $lines[] = '✓ Komposisi trading dan investasi mendekati sasaran seimbang 50/50.';
        }

        return implode("\n", $lines);
    }
}
