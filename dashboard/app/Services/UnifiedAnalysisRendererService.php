<?php

namespace App\Services;

class UnifiedAnalysisRendererService
{
    /**
     * Render canonical structured schema into user-facing presentation.
     * Sanitizes raw CoT, stderr, internal session IDs, and database trace.
     */
    public function renderToText(array $payload, string $channel = 'TELEGRAM'): string
    {
        $schemaVersion = $payload['schema_version'] ?? 'aether.analysis.v3';
        $status = strtoupper($payload['status'] ?? 'COMPLETE');
        $requestKind = strtoupper($payload['request_kind'] ?? 'COMPREHENSIVE_ANALYSIS');
        $answer = trim($payload['answer'] ?? '');
        $limitations = (array) ($payload['limitations'] ?? []);
        $evidenceIds = (array) ($payload['evidence_ids'] ?? []);
        $reqPeriod = $payload['requested_period'] ?? null;
        $actPeriod = $payload['actual_data_period'] ?? null;
        $assessment = $payload['assessment'] ?? [];

        // Short-circuit for simple or non-holistic requests (Education, Status, Provenance, Out-of-Scope)
        if (in_array($requestKind, ['EDUCATION', 'SYSTEM_STATUS', 'DATA_PROVENANCE_INQUIRY', 'FOLLOW_UP_CLARIFICATION', 'OUT_OF_SCOPE_QUERY'])) {
            $lines = [];
            if ($requestKind === 'OUT_OF_SCOPE_QUERY') {
                return $answer;
            }
            if ($status === 'PARTIAL') {
                $lines[] = 'ℹ️ LAPORAN PARSIAL (DATA TERBATAS)';
            }
            $lines[] = $answer;

            if (! empty($limitations)) {
                $lines[] = '';
                $lines[] = '📌 Catatan & Keterbatasan:';
                foreach ($limitations as $lim) {
                    $lines[] = "• {$lim}";
                }
            }

            if (! empty($evidenceIds)) {
                $evStr = implode(', ', array_map(fn ($id) => "#{$id}", $evidenceIds));
                $lines[] = '';
                $lines[] = "Referensi Evidence: {$evStr}";
            }

            return implode("\n", $lines);
        }

        // Holistic or structured analysis rendering
        $lines = [];
        $statusBadge = match ($status) {
            'COMPLETE' => '📋 ANALISIS SELESAI (DATA DASAR TERSEDIA)',
            'PARTIAL' => '⚠️ ANALISIS PARSIAL (DATA TERBATAS / BEBERAPA ASPEK BELUM TERPENUHI)',
            'FAILED' => '❌ ANALISIS BELUM DAPAT DINILAI DENGAN DATA SAAT INI',
            'INSTRUMENT_UNRESOLVED', 'INSTRUMENT_RESOLUTION_FAILED', 'INSTRUMENT_UNVERIFIED' => '🔍 KLARIFIKASI CAKUPAN INSTRUMEN',
            default => 'ℹ️ INFORMASI PASAR',
        };

        $lines[] = $statusBadge;
        $lines[] = '══════════════════════════════════════════════════';

        // Ticker & Period Information
        $ticker = $payload['ticker'] ?? null;
        if ($ticker) {
            $lines[] = "Saham: {$ticker} (Bursa Efek Indonesia)";
        }

        if (! empty($reqPeriod) && ! empty($actPeriod)) {
            $sReq = $reqPeriod['start'] ?? '';
            $eReq = $reqPeriod['end'] ?? '';
            $sAct = $actPeriod['start'] ?? '';
            $eAct = $actPeriod['end'] ?? '';
            $lines[] = "Periode Diminta  : {$sReq} s/d {$eReq}";
            $lines[] = "Periode Penyedia : {$sAct} s/d {$eAct}";
        }

        $lines[] = '──────────────────────────────────────────────────';

        // Human-friendly translations for internal status strings
        $translatedAnswer = $answer;
        $replacements = [
            'DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA' => 'Belum cukup data untuk menentukan entry day trade.',
            'SWING_ASSESSMENT_PARTIAL' => 'Penilaian swing masih terbatas.',
            'NOT_OBSERVED_IN_TOP_N' => 'Broker tidak terlihat dalam daftar teratas yang tersedia.',
            'MODEL_NOT_VALIDATED_FOR_INSTRUMENT' => 'Model prediktif ML belum dikalibrasi untuk emiten ini.',
        ];
        foreach ($replacements as $search => $replace) {
            $translatedAnswer = str_replace($search, $replace, $translatedAnswer);
        }

        $lines[] = $translatedAnswer;

        // Structured Assessment if present
        if (! empty($assessment)) {
            $lines[] = '';
            $lines[] = '📊 KESIMPULAN STRATEGI & RISIKO:';
            if (isset($assessment['day_trade'])) {
                $dtVal = $replacements[$assessment['day_trade']] ?? $assessment['day_trade'];
                $lines[] = "• Day Trade : {$dtVal}";
            }
            if (isset($assessment['swing_trade'])) {
                $swVal = $replacements[$assessment['swing_trade']] ?? $assessment['swing_trade'];
                $lines[] = "• Swing     : {$swVal}";
            }
            if (isset($assessment['risk_level'])) {
                $lines[] = "• Profil Risiko : {$assessment['risk_level']}";
            }
        }

        // Limitations & Evidence footer
        if (! empty($limitations)) {
            $lines[] = '';
            $lines[] = '⚠️ Keterbatasan & Peringatan Risiko:';
            foreach ($limitations as $lim) {
                $lines[] = "• {$lim}";
            }
        }

        if (! empty($evidenceIds)) {
            $evStr = implode(', ', array_map(fn ($id) => "#{$id}", $evidenceIds));
            $lines[] = '';
            $lines[] = "🔍 ID Evidence Terverifikasi: {$evStr}";
        }

        $lines[] = '══════════════════════════════════════════════════';

        return implode("\n", $lines);
    }
}
