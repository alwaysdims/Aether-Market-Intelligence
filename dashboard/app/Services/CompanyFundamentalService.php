<?php

namespace App\Services;

use App\Models\BrokerSummary;
use App\Models\CompanyAnalysisMetric;
use App\Models\CompanyCorporateAction;
use App\Models\CompanyFundamental;
use App\Models\IdxCompanyRegistry;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use Carbon\Carbon;

class CompanyFundamentalService
{
    public function __construct(
        protected CanonicalMarketReadService $marketRead,
        protected IdxFinancialIngestionService $ingestionService
    ) {}

    /**
     * Format a complete fundamental and technical analysis for a given ticker.
     */
    public function getAnalysisData(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));
        $asOfDate = Carbon::now('Asia/Jakarta')->toDateString();

        $registry = IdxCompanyRegistry::where('ticker', $ticker)->first();
        $metrics = CompanyAnalysisMetric::where('ticker', $ticker)
            ->latest('as_of_date')
            ->first();

        // Avoid falling back to old Yahoo Finance python collector if provider is not available
        // Return existing DB state; operational pipeline strictly requires IDX Edge PRO
        $latestObs = MarketObservation::where('ticker', $ticker)
            ->latest('bar_date')
            ->first();

        $fundamentals = CompanyFundamental::where('ticker', $ticker)
            ->where('report_type', 'ANNUAL')
            ->where('source', 'IDX Edge PRO')
            ->orderBy('fiscal_year', 'desc')
            ->take(4)
            ->get();

        $actions = CompanyCorporateAction::where('ticker', $ticker)
            ->orderBy('execution_date', 'desc')
            ->take(5)
            ->get();

        return [
            'ticker' => $ticker,
            'registry' => $registry,
            'metrics' => $metrics,
            'latest_observation' => $latestObs,
            'fundamentals' => $fundamentals,
            'corporate_actions' => $actions,
            'has_data' => $metrics !== null || $latestObs !== null,
        ];
    }

    /**
     * Format human-readable structured response for Telegram and Dashboard.
     */
    public function formatFundamentalReport(string $ticker): string
    {
        $data = $this->getAnalysisData($ticker);
        if (! $data['has_data']) {
            return "Data fundamental dan teknikal untuk emiten {$ticker} belum tersedia di database.\n"
                .'Gunakan perintah "analisis '.$ticker.'" untuk memicu pengambilan data.';
        }

        $reg = $data['registry'];
        $m = $data['metrics'];
        $obs = $data['latest_observation'];
        $funds = $data['fundamentals'];
        $acts = $data['corporate_actions'];

        $companyName = $reg?->company_name ?? ($obs?->company_name ?? $ticker);
        $sector = $reg?->sector ?? 'General';
        $board = $reg?->listing_board ?? 'Utama';

        $lines = [];
        $lines[] = "ANALISIS FUNDAMENTAL & TEKNIKAL — {$ticker}";
        $lines[] = '──────────────────────────────────────────';
        $lines[] = "• Perusahaan : {$companyName}";
        $lines[] = "• Sektor     : {$sector}";

        $boardDateStr = $reg?->snapshot_date ? $reg->snapshot_date->format('d M Y') : 'N/A';
        $boardSource = $reg?->source ?? 'Direktori Resmi BEI';
        $lines[] = "• Status Papan: Papan {$board} [Sumber: {$boardSource}, Tanggal Verifikasi: {$boardDateStr}]";

        if ($obs) {
            $closePrice = (float) $obs->close_price;
            $fraction = BeiUniverseRegistry::resolvePriceFraction($closePrice, $board);
            $lines[] = '• Tingkat Harga & Fraksi: Rp '.number_format($closePrice, 0, ',', '.')." (Fraksi Tick: Rp {$fraction['tick_size']} — {$fraction['rules']})";
        }
        $lines[] = '• Instrumen  : Saham Biasa Perusahaan (Bukan Indeks/Waran)';

        if ($obs) {
            $closeFmt = number_format($obs->close_price, 2, ',', '.');
            $barDateStr = $obs->bar_date->format('d M Y');
            $srcName = $obs->source ?: 'IDX Edge PRO';
            $valStatus = $obs->validation_status ?: 'VALID';
            $lines[] = "• Harga Penutupan: Rp {$closeFmt} (Bar: {$barDateStr}, Observation ID: {$obs->id}, Sumber: {$srcName}, Status: {$valStatus})";

            $marketRead = app(CanonicalMarketReadService::class);
            $pc = $marketRead->priceChanges($obs);
            if ($pc['is_previous_close_available'] && $pc['daily_change_pct'] !== null) {
                $dSign = $pc['daily_change'] >= 0 ? '+' : '';
                $lines[] = "• Perubahan Harian: {$dSign}Rp ".number_format($pc['daily_change'], 2, ',', '.')." ({$dSign}".number_format($pc['daily_change_pct'], 2, ',', '.').'% vs Prev Close Rp '.number_format($pc['previous_close'], 2, ',', '.')." [{$pc['previous_bar_date']}])";
            } else {
                $lines[] = '• Perubahan Harian: N/A (Basis sesi sebelumnya belum tersedia)';
            }
            if ($pc['change_vs_open_pct'] !== null) {
                $oSign = $pc['change_vs_open'] >= 0 ? '+' : '';
                $lines[] = "• Perubahan vs Open: {$oSign}Rp ".number_format($pc['change_vs_open'], 2, ',', '.')." ({$oSign}".number_format($pc['change_vs_open_pct'], 2, ',', '.')."% vs Open bar {$barDateStr})";
            }
        }

        $flags = $m?->data_quality_flags ?? [];
        $meta = $flags['sufficiency'] ?? [];
        $inputBars = $flags['input_bars'] ?? ($obs ? MarketObservation::where('ticker', $ticker)->count() : 0);

        $lines[] = '';
        $lines[] = "1. KONDISI TEKNIKAL (DETERMINISTIK — {$inputBars} Candle EOD)";
        if ($m && in_array($m->technical_status, ['CALCULATED', 'PARTIAL_CALCULATED'], true)) {
            $lines[] = "• Tren Pasar      : {$m->technical_trend}";
            $sma20Str = $m->sma_20 ? 'Rp '.number_format($m->sma_20, 2, ',', '.') : 'N/A (Histori < 20 bar)';
            $sma50Str = $m->sma_50 ? 'Rp '.number_format($m->sma_50, 2, ',', '.') : 'N/A (Histori < 50 bar)';
            $sma200Str = $m->sma_200 ? 'Rp '.number_format($m->sma_200, 2, ',', '.') : 'N/A (Histori < 200 bar)';
            $lines[] = "• MA (20/50/200)  : {$sma20Str} / {$sma50Str} / {$sma200Str}";
            $rsiStr = $m->rsi_14 !== null ? "{$m->rsi_14}".($m->rsi_14 < 30 ? ' (Oversold)' : ($m->rsi_14 > 70 ? ' (Overbought)' : ' (Netral)')) : 'N/A (Histori < 15 bar)';
            $lines[] = "• RSI 14 (Wilder) : {$rsiStr} [Seed: 14-period SMA initial]";
            $macdStr = $m->macd !== null ? "Line {$m->macd} | Signal {$m->macd_signal} | Hist {$m->macd_hist}" : 'N/A (Histori < 35 bar)';
            $lines[] = "• MACD (12,26,9)  : {$macdStr}";
            $atrStr = $m->atr_14 !== null ? 'Rp '.number_format($m->atr_14, 2, ',', '.')." ({$m->atr_14_pct}% rentang bar) [Seed: 14-period TR initial]" : 'N/A (Histori < 15 bar)';
            $lines[] = "• Volatilitas ATR : {$atrStr}";

            $ddVal = $m->max_drawdown_1y_pct;
            $lines[] = $ddVal !== null ? "• Max Drawdown 1Y : {$ddVal}%" : '• Max Drawdown 1Y : N/A (Histori < 200 bar)';

            $adtvVal = $flags['adtv_20'] ?? null;
            $adtvStr = $adtvVal ? 'Rp '.number_format($adtvVal / 1000000000, 2, ',', '.').' M' : 'N/A';
            $rvolStr = $m->rvol_20 ? "{$m->rvol_20}x" : 'N/A';
            $lines[] = "• Proksi Aktivitas: Estimasi Nilai Transaksi (ADTV20) {$adtvStr} | RVOL {$rvolStr}";
            $lines[] = '  (Catatan: Estimasi Close × Volume, bukan nilai turnover kliring bursa; bukan bukti spread sempit atau kemudahan eksekusi)';
            $lines[] = '• Support/Resist  : Rp '.number_format($m->support_price, 2, ',', '.').' / Rp '.number_format($m->resistance_price, 2, ',', '.').' (Rolling 20-day Point-in-Time)';
        } else {
            $lines[] = '• Status Teknikal : INSUFFICIENT_HISTORY (Data riwayat candle belum cukup untuk kalkulasi teknikal penuh)';
        }

        $lines[] = '';
        $lines[] = '2. KONDISI FUNDAMENTAL & VALUASI';
        if ($m && $m->fundamental_status !== 'NO_DATA') {
            $sharesOut = $flags['shares_outstanding'] ?? $reg?->shares_outstanding;
            $weightedShares = $flags['weighted_average_shares'] ?? null;
            $sharesOutStr = $sharesOut ? number_format($sharesOut, 0, ',', '.').' lembar' : 'N/A';
            $weightedSharesStr = $weightedShares ? number_format($weightedShares, 0, ',', '.').' lembar' : 'N/A';
            $lines[] = "• Saham Beredar   : Akhir Periode {$sharesOutStr} (Basis BVPS) | Rata-rata Tertimbang {$weightedSharesStr} (Basis EPS)";

            $epsSrc = $srcs['eps_source'] ?? 'CALCULATED_FROM_FINANCIALS';
            $bvpsSrc = $srcs['bvps_source'] ?? 'CALCULATED_FROM_FINANCIALS';
            $epsStr = $m->eps ? 'Rp '.number_format($m->eps, 2, ',', '.')." [{$epsSrc}]" : 'N/A';
            $bvpsStr = $m->bvps ? 'Rp '.number_format($m->bvps, 2, ',', '.')." [{$bvpsSrc}]" : 'N/A';
            $lines[] = "• EPS / BVPS      : {$epsStr} / {$bvpsStr}";

            $peSrc = $srcs['pe_source'] ?? 'CALCULATED_FROM_FINANCIALS';
            $peStr = $m->pe_ratio !== null
                ? "{$m->pe_ratio}x [{$peSrc}]"
                : ((($flags['pe_metric_status'] ?? null) === 'NOT_APPLICABLE_NEGATIVE_EARNINGS' || ($m->eps !== null && $m->eps <= 0))
                    ? 'N/A [NOT_APPLICABLE: Emiten Mencatat Rugi Bersih]'
                    : 'N/A');
            $pbvSrc = $srcs['pbv_source'] ?? 'CALCULATED_FROM_FINANCIALS';
            $pbvStr = $m->pbv_ratio !== null ? "{$m->pbv_ratio}x [{$pbvSrc}]" : 'N/A';
            $lines[] = "• PER / PBV       : {$peStr} / {$pbvStr}";

            $roeStr = $m->roe_pct !== null ? "{$m->roe_pct}%" : 'N/A';
            $roaStr = $m->roa_pct !== null ? "{$m->roa_pct}%" : 'N/A';
            $lines[] = "• ROE / ROA       : {$roeStr} / {$roaStr}";

            $derSrc = $srcs['der_source'] ?? 'INTEREST_BEARING_DEBT_TO_EQUITY';
            $derStr = $m->der_ratio !== null ? "{$m->der_ratio}x (Utang Berbunga / Ekuitas Induk)" : ($m->sector_template === 'BANKING' ? 'N/A (Metrik Perbankan Dikecualikan — DPK dicatat liabilitas operasional)' : 'N/A');
            $pureDer = $flags['der_pure_debt_ratio'] ?? null;
            $pureDerStr = $pureDer !== null ? " | DER Pinjaman Murni: {$pureDer}x" : '';
            $liabEqVal = $flags['liabilities_to_equity_ratio'] ?? null;
            $liabEqStr = $liabEqVal !== null ? "{$liabEqVal}x" : 'N/A';
            $lines[] = "• Solvabilitas    : Rasio Total Liabilitas / Total Ekuitas: {$liabEqStr} | DER Utang Berbunga: {$derStr}{$pureDerStr}";
            $lines[] = '  (Peringatan Akuntansi: Rasio total liabilitas terhadap ekuitas BUKAN rasio utang pinjaman berbunga/DER murni; mencakup seluruh kewajiban operasional, usaha, dan akrual).';

            $npmStr = $m->net_profit_margin_pct !== null ? "{$m->net_profit_margin_pct}%" : 'N/A';
            $lines[] = "• Net Margin      : {$npmStr}";

            $divVal = $flags['dividend_cash_amount'] ?? null;
            $divDate = $flags['latest_dividend_date'] ?? null;
            $divYieldStr = $m->dividend_yield_pct !== null ? "{$m->dividend_yield_pct}%" : '0,00%';
            $divSrc = $srcs['dividend_source'] ?? 'PROVIDER_SUPPLIED';
            $lines[] = '• Dividen Kas     : '.($divVal ? "Rp {$divVal}/lembar (Ex-Date: {$divDate})" : 'N/A')." | Trailing Yield: {$divYieldStr} [Status: {$divSrc}]";

            $lines[] = '• Status Bukti    : PROVIDER_SUPPLIED (Feed IDX Edge PRO / Emiten, belum diverifikasi fisik dokumen audit resmi BEI)';
        } else {
            $lines[] = '• Status Rasio    : LAPORAN_KEUANGAN_PENDING (Laporan keuangan tahunan/interim belum tersedia)';
        }

        if ($funds->count() > 0) {
            $lines[] = '';
            $lines[] = "3. HISTORI KINERJA TAHUNAN ({$funds->count()} Periode Laporan Fiskal Tahunan)";
            foreach ($funds as $f) {
                $curr = strtoupper(trim((string) ($f->currency ?: 'IDR')));
                $rawPayload = is_array($f->raw_payload) ? $f->raw_payload : [];
                $convMeta = $rawPayload['conversion_metadata'] ?? [];
                $isUnknownConversion = ($convMeta['conversion_method'] ?? '') === 'UNKNOWN_PROVIDER_CONVERSION';

                if ($isUnknownConversion) {
                    $origCurr = $convMeta['original_currency'] ?? 'VALAS';
                    $lines[] = "• Tahun {$f->fiscal_year}: NOMINAL_INTERPRETATION_BLOCKED [Mata uang asal: {$origCurr}, penyedia mengonversi tanpa nilai kurs resmi. Perbandingan nominal IDR/USD diblokir. Kualitas: {$f->data_quality}]";

                    continue;
                }

                $scale = strtoupper(trim((string) ($f->unit ?: 'IDR')));
                if ($scale !== 'IDR' && $scale !== 'USD' && $scale !== 'IDR_FULL' && $scale !== 'USD_FULL') {
                    $lines[] = "• Tahun {$f->fiscal_year}: NOMINAL_INTERPRETATION_BLOCKED [Skala/unit '{$scale}' tidak terverifikasi pasti. Interpretasi nominal diblokir untuk mencegah distorsi. Kualitas: {$f->data_quality}]";

                    continue;
                }

                $isUsd = $curr === 'USD';
                $prefix = $isUsd ? 'US$ ' : 'Rp ';
                $revStr = $f->total_revenue !== null ? $prefix.number_format($f->total_revenue / 1e9, 2, ',', '.').' M' : '-';
                $niStr = $f->net_income !== null ? $prefix.number_format($f->net_income / 1e9, 2, ',', '.').' M' : '-';
                $parentStr = $f->attribution_parent !== null ? ' | Entitas Induk: '.$prefix.number_format($f->attribution_parent / 1e9, 2, ',', '.').' M' : '';
                $lines[] = "• Tahun {$f->fiscal_year}: Pendapatan {$revStr} | Laba Bersih {$niStr}{$parentStr} [Mata Uang: {$curr}, Kualitas: {$f->data_quality}]";
            }
            $lines[] = '  (Catatan Kinerja: Peningkatan laba bersih periode berjalan belum membuktikan perbaikan laba operasional atau arus kas operasional; rincian pos luar biasa mengacu pada rilis dokumen audit).';
        } else {
            $lines[] = '';
            $lines[] = '3. HISTORI KINERJA TAHUNAN';
            $lines[] = '• Status Data: Laporan tahunan resmi IDX Edge PRO belum tersinkronisasi di basis data (Arsip Yahoo Finance dikecualikan dari jalur operasional untuk mencegah distorsi skala/mata uang).';
        }

        // Broker Summary (Pasar Reguler)
        $latestBrokers = BrokerSummary::where('ticker', $ticker)
            ->where('flow_type', 'all')
            ->orderByDesc('summary_date')
            ->get();
        if ($latestBrokers->isNotEmpty()) {
            $bsDate = $latestBrokers->first()->summary_date?->toDateString();
            $topBuyers = $latestBrokers->where('net_value', '>', 0)->sortByDesc('net_value')->take(3);
            $topSellers = $latestBrokers->where('net_value', '<', 0)->sortBy('net_value')->take(3);

            $lines[] = '';
            $lines[] = "4. AKTIVITAS BROKER PASAR REGULER (Sesi {$bsDate} — IDX Edge PRO)";
            $lines[] = '• Catatan Metodologi: Aktivitas broker mengagregasikan volume per sekuritas di Pasar Reguler (RG), bukan bukti kepemilikan definitif entitas bandar tunggal.';
            if ($topBuyers->isNotEmpty()) {
                $bList = [];
                foreach ($topBuyers as $tb) {
                    $valM = round($tb->net_value / 1e9, 2);
                    $bList[] = "{$tb->broker_code} (+Rp {$valM} M @ avg Rp ".number_format($tb->buy_avg_price ?? 0, 0, ',', '.').')';
                }
                $lines[] = '• Top Net Buyers   : '.implode(', ', $bList);
            }
            if ($topSellers->isNotEmpty()) {
                $sList = [];
                foreach ($topSellers as $ts) {
                    $valM = round(abs($ts->net_value) / 1e9, 2);
                    $sList[] = "{$ts->broker_code} (-Rp {$valM} M @ avg Rp ".number_format($ts->sell_avg_price ?? 0, 0, ',', '.').')';
                }
                $lines[] = '• Top Net Sellers  : '.implode(', ', $sList);
            }
        }

        if ($acts->count() > 0) {
            $lines[] = '';
            $lines[] = '5. AKSI KORPORASI TERAKHIR';
            foreach ($acts as $a) {
                $lines[] = "• {$a->action_type}: Nilai {$a->amount_or_ratio} (Tanggal: {$a->execution_date->format('d M Y')})";
            }
        }

        $lines[] = '──────────────────────────────────────────';

        // 5. Kesimpulan AI & Analisis Risiko (jika tersedia dari pipeline grounded)
        if (config('database.default') === 'sqlite') {
            $latestAnalysis = MarketAnalysis::where('structured_output', 'like', "%\"ticker\":\"{$ticker}\"%")
                ->orWhere('structured_output', 'like', "%\"ticker\": \"{$ticker}\"%")
                ->latest('id')
                ->first();
        } else {
            $latestAnalysis = MarketAnalysis::whereRaw('structured_output::text ilike ?', ["%\"ticker\": \"{$ticker}\"%"])
                ->orWhereRaw('structured_output::text ilike ?', ["%\"ticker\":\"{$ticker}\"%"])
                ->latest('id')
                ->first();
        }

        if ($latestAnalysis && ! empty($latestAnalysis->structured_output)) {
            $struct = $latestAnalysis->structured_output;
            $evidenceId = $struct['evidence_snapshot_id'] ?? 'N/A';
            $analysisBarDate = $struct['bar_date'] ?? $struct['decision_date'] ?? 'N/A';
            $refClose = isset($struct['reference_close']) && $struct['reference_close'] ? 'Rp '.number_format($struct['reference_close'], 0, ',', '.') : 'N/A';

            $lines[] = "5. KESIMPULAN AI & ANALISIS RISIKO (Analysis ID: #{$latestAnalysis->id})";
            $lines[] = "• Model Narasi : {$latestAnalysis->model_name} (Evidence ID: #{$evidenceId}, Bar Acuan: {$analysisBarDate}, Close Acuan: {$refClose})";

            if ($obs && $analysisBarDate !== 'N/A' && $obs->bar_date->toDateString() !== $analysisBarDate) {
                $lines[] = "• Catatan Periode: Analisis dievaluasi pada bar historis {$analysisBarDate} (Close Acuan: {$refClose}). Harga penutupan terkini sesi pasar {$obs->bar_date->toDateString()} adalah Rp ".number_format($obs->close_price, 0, ',', '.').'.';
            }

            if (! empty($struct['generated_narrative'])) {
                $narrative = $struct['generated_narrative'];
                if (preg_match('/INTERPRETASI MODEL & STATUS VALIDASI\s*(.*?)(?=\Z|SKENARIO DAMPAK|FAKTA PASAR)/is', $narrative, $im)) {
                    $cleanIm = trim(strip_tags(str_replace('**', '', $im[1])));
                    $lines[] = "• Kesimpulan Model:\n  ".trim(preg_replace('/^[•\-\*]\s*/m', '  • ', $cleanIm));
                }
                if (preg_match('/SKENARIO DAMPAK & RISIKO\s*(.*?)(?=\Z|INTERPRETASI MODEL|FAKTA PASAR)/is', $narrative, $rm)) {
                    $cleanRm = trim(strip_tags(str_replace('**', '', $rm[1])));
                    $lines[] = "• Faktor Risiko & Ketidakpastian:\n  ".trim(preg_replace('/^[•\-\*]\s*/m', '  • ', $cleanRm));
                }
            } elseif (! empty($latestAnalysis->market_regime_summary)) {
                $lines[] = "• Ringkasan Eksekutif: {$latestAnalysis->market_regime_summary}";
            }
            $lines[] = '──────────────────────────────────────────';
        }

        $lines[] = '• Batasan & Integritas: Perhitungan deterministik tanpa halusinasi LLM.';
        $lines[] = '• Model ML: Model prediktif ML Aether hanya divalidasi pada 10 konstituen inti; emiten lain berstatus MODEL_NOT_VALIDATED_FOR_INSTRUMENT.';

        return implode("\n", $lines);
    }
}
