<?php

namespace App\Services;

use Carbon\Carbon;

class NewsIntelligenceService
{
    /**
     * Format structured 5-point news intelligence output separating facts from hypotheses.
     */
    public static function formatStructuredNewsAnalysis(array $article): string
    {
        $title = $article['title'] ?? 'Judul Berita';
        $source = $article['source_name'] ?? $article['source'] ?? 'LKBN ANTARA';
        $url = $article['url'] ?? '-';
        $pubDate = isset($article['published_at']) ? Carbon::parse($article['published_at'])->format('d M Y, H:i').' WIB' : 'N/A';
        $summary = $article['summary'] ?? 'Ringkasan tidak tersedia.';
        $tickers = isset($article['related_tickers']) ? (is_array($article['related_tickers']) ? implode(', ', $article['related_tickers']) : $article['related_tickers']) : 'IHSG_MACRO';
        $impactDir = strtoupper($article['impact_direction'] ?? 'NEUTRAL');
        $horizon = $article['impact_horizon'] ?? '1-2 Minggu';
        $mechanism = $article['impact_mechanism'] ?? 'Informasi bersifat makro-umum atau sentimen industri tanpa transmisi laba langsung.';
        $counter = $article['counter_scenario'] ?? 'Volatilitas pasar eksternal atau tren suku bunga global berpotensi meniadakan sentimen ini.';

        $lines = [];
        $lines[] = '📰 INTELEJENSI BERITA — AETHER MARKET INTELLIGENCE';
        $lines[] = '════════════════════════════════════════════════════';
        $lines[] = "Judul   : {$title}";
        $lines[] = "Sumber  : {$source} ({$pubDate})";
        $lines[] = "URL     : {$url}";
        $lines[] = "Instrumen Terkait: \${$tickers}";
        $lines[] = '';
        $lines[] = '1️⃣ FAKTA TERLAPOR (Reported Facts):';
        $lines[] = "   {$summary}";
        $lines[] = '';
        $lines[] = '2️⃣ KESIMPULAN ANALITIS (Analytical Takeaway):';
        $lines[] = '   Peristiwa ini mencerminkan dinamika sektor finansial/makro terkini.';
        $lines[] = '';
        $lines[] = '3️⃣ HIPOTESIS DAMPAK PASAR (Impact Direction):';
        $lines[] = "   {$impactDir} (Catatan: Sentimen positif bukan jaminan harga pasti naik)";
        $lines[] = '';
        $lines[] = '4️⃣ MEKANISME & HORIZON TRANSMISI (Channel & Horizon):';
        $lines[] = "   Horizon: {$horizon}";
        $lines[] = "   Mekanisme: {$mechanism}";
        $lines[] = '';
        $lines[] = '5️⃣ SKENARIO ALTERNATIF / PEMBATAL (Counter-Scenario):';
        $lines[] = "   {$counter}";
        $lines[] = '════════════════════════════════════════════════════';

        return implode("\n", $lines);
    }
}
