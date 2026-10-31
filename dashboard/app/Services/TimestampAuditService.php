<?php

namespace App\Services;

use Carbon\Carbon;

class TimestampAuditService
{
    /**
     * Parse timestamp string safely into UTC Carbon instance.
     * Identifies offsets, known source zones, unknown zones, and future anomalies.
     */
    public function parseTimestamp(string $rawString, ?string $knownSourceZone = null): array
    {
        $raw = trim($rawString);
        if (empty($raw)) {
            return [
                'carbon' => null,
                'status' => 'EMPTY',
                'detected_zone' => null,
                'is_anomaly' => true,
                'anomaly_reason' => 'Empty timestamp string',
            ];
        }

        $hasExplicitOffset = preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $raw);

        if ($hasExplicitOffset) {
            // Case 1 & 2: UTC or other timezone with explicit offset
            $carbon = Carbon::parse($raw)->setTimezone('UTC');
            $detectedZone = 'EXPLICIT_OFFSET';
            $status = 'VALID_OFFSET';
        } elseif ($knownSourceZone !== null && $knownSourceZone !== '') {
            // Case 3: Naive timestamp with known source timezone
            $carbon = Carbon::parse($raw, $knownSourceZone)->setTimezone('UTC');
            $detectedZone = $knownSourceZone;
            $status = 'VALID_KNOWN_ZONE';
        } else {
            // Case 4: Naive timestamp with UNKNOWN timezone - MUST NOT GUESS
            return [
                'carbon' => null,
                'status' => 'ZONE_UNKNOWN',
                'detected_zone' => 'UNKNOWN',
                'is_anomaly' => true,
                'anomaly_reason' => 'Naive timestamp without explicit offset and without declared source timezone. Guessing is prohibited.',
                'raw' => $raw,
            ];
        }

        // Future timestamp audit anomaly check
        $nowUtc = Carbon::now('UTC');
        $isFuture = $carbon->isAfter($nowUtc->copy()->addMinutes(5));

        return [
            'carbon' => $carbon,
            'status' => $status,
            'detected_zone' => $detectedZone,
            'is_anomaly' => $isFuture,
            'anomaly_reason' => $isFuture ? 'FUTURE_TIMESTAMP: Timestamp is ahead of current server UTC time' : null,
            'raw' => $raw,
        ];
    }
}
