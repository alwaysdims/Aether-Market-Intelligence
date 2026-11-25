<?php

namespace App\Services;

use Carbon\Carbon;
use UnexpectedValueException;

class IdxEdgeCapabilityParser
{
    /** @return array<string, mixed> */
    public function parsePriceIhsg(array $payload): array
    {
        $this->rejectError($payload);
        foreach (['last_price', 'change', 'change_pct'] as $field) {
            if (! is_numeric($payload[$field] ?? null)) {
                throw new UnexpectedValueException("IHSG {$field} invalid.");
            }
        }
        foreach (['previous_close', 'prev_close', 'open', 'high', 'low'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && ! is_numeric($payload[$field])) {
                throw new UnexpectedValueException("IHSG {$field} invalid.");
            }
        }
        if (strtoupper((string) ($payload['code'] ?? '')) !== 'IHSG'
            || ! is_string($payload['market_state'] ?? null)
            || ! is_string($payload['served_ts'] ?? null)) {
            throw new UnexpectedValueException('IHSG snapshot schema drift.');
        }

        $sourceDate = $payload['source_date'] ?? null;
        if ($sourceDate !== null && (! is_string($sourceDate) || ! $this->validDate($sourceDate))) {
            throw new UnexpectedValueException('IHSG source date invalid.');
        }

        $dataTimestamp = $payload['data_ts'] ?? null;
        if ($dataTimestamp !== null && ! is_string($dataTimestamp)) {
            throw new UnexpectedValueException('IHSG data timestamp invalid.');
        }

        return [
            'ticker' => 'IHSG',
            'last_price' => (float) $payload['last_price'],
            'change' => (float) $payload['change'],
            'change_pct' => (float) $payload['change_pct'],
            'previous_close' => isset($payload['previous_close']) ? (float) $payload['previous_close'] : (isset($payload['prev_close']) ? (float) $payload['prev_close'] : null),
            'open' => array_key_exists('open', $payload) && $payload['open'] !== null ? (float) $payload['open'] : null,
            'high' => array_key_exists('high', $payload) && $payload['high'] !== null ? (float) $payload['high'] : null,
            'low' => array_key_exists('low', $payload) && $payload['low'] !== null ? (float) $payload['low'] : null,
            'as_of_date' => $sourceDate,
            'data_timestamp' => $dataTimestamp,
            'served_timestamp' => $payload['served_ts'],
            'market_state' => $payload['market_state'],
            'market_label' => (string) ($payload['market_label'] ?? ''),
            'freshness_status' => $sourceDate === null
                ? 'EVENT_DATE_UNVERIFIED'
                : ($dataTimestamp === null ? 'PROVIDER_TIMESTAMP_UNAVAILABLE' : 'PROVIDER_TIMESTAMP_AVAILABLE'),
            'board_scope' => 'INDEX',
            'data_classification' => 'PROVIDER_SUPPLIED',
        ];
    }

    /** @return array<string, mixed> */
    public function parseChart(array $payload): array
    {
        $this->rejectError($payload);
        if (! preg_match('/^[A-Z0-9^.-]{1,10}$/', (string) ($payload['code'] ?? ''))
            || ! is_numeric($payload['interval'] ?? null)
            || ! is_array($payload['bars'] ?? null)
            || ! is_array($payload['market'] ?? null)
            || ! $this->validDate((string) ($payload['date'] ?? ''))) {
            throw new UnexpectedValueException('Chart schema drift.');
        }

        $bars = [];
        $previousTimestamp = null;
        $seenTimestamps = [];
        foreach ($payload['bars'] as $bar) {
            if (! is_array($bar) || count($bar) < 6) {
                throw new UnexpectedValueException('Chart bar shape invalid.');
            }
            [$timestamp, $open, $high, $low, $close, $volume] = array_values($bar);
            if (isset($seenTimestamps[(string) $timestamp]) || ($previousTimestamp !== null && (int) $timestamp <= $previousTimestamp)) {
                throw new UnexpectedValueException('Chart timestamp order or duplicate invalid.');
            }
            $seenTimestamps[(string) $timestamp] = true;
            $previousTimestamp = (int) $timestamp;
            foreach ([$timestamp, $open, $high, $low, $close, $volume] as $value) {
                if (! is_numeric($value)) {
                    throw new UnexpectedValueException('Chart bar value invalid.');
                }
            }
            if ((float) $high < max((float) $open, (float) $close, (float) $low)
                || (float) $low > min((float) $open, (float) $close, (float) $high)
                || (float) $volume < 0) {
                throw new UnexpectedValueException('Chart OHLCV geometry invalid.');
            }
            $bars[] = [
                'timestamp' => (int) $timestamp,
                'open' => (float) $open,
                'high' => (float) $high,
                'low' => (float) $low,
                'close' => (float) $close,
                'volume' => (float) $volume,
            ];
        }

        if ((int) ($payload['count'] ?? count($bars)) !== count($bars)) {
            throw new UnexpectedValueException('Chart count mismatch.');
        }

        return [
            'ticker' => strtoupper((string) $payload['code']),
            'interval_seconds' => (int) $payload['interval'],
            'native_interval_seconds' => (int) ($payload['native_interval'] ?? $payload['interval']),
            'as_of_date' => $payload['date'],
            'source' => (string) ($payload['source'] ?? 'NOT_DOCUMENTED'),
            'bars' => $bars,
            'market' => $payload['market'],
            'volume_unit' => 'NOT_DOCUMENTED',
            'board_scope' => 'UNVERIFIED',
            'data_classification' => 'PROVIDER_SUPPLIED',
        ];
    }

    /** @return array<string, mixed> */
    public function parseFinancialStatements(array $payload): array
    {
        $this->rejectError($payload);
        $items = $payload['items'] ?? null;
        if (! is_array($items) && is_array($payload['reports'] ?? null)) {
            $items = [];
            foreach ($payload['reports'] as $period => $data) {
                $items[] = is_array($data) && isset($data['data'])
                    ? array_merge(['period' => (string) $period], $data)
                    : ['period' => (string) $period, 'year' => (string) ($payload['year'] ?? 'UNKNOWN'), 'quarter' => 'UNKNOWN', 'fetched_at' => (string) ($payload['fetched_at'] ?? ''), 'data' => is_array($data) ? $data : []];
            }
        }
        if (is_array($items) && ! isset($payload['count'])) {
            $payload['count'] = count($items);
        }
        if (! preg_match('/^[A-Z0-9^.-]{1,10}$/', (string) ($payload['stock_code'] ?? ''))
            || ! is_string($payload['report_type'] ?? null)
            || ! is_string($payload['period'] ?? null)
            || ! is_array($items)
            || ! is_numeric($payload['count'] ?? null)
            || (int) $payload['count'] !== count($items)) {
            throw new UnexpectedValueException('Financial statement schema drift.');
        }
        foreach ($items as $item) {
            if (! is_array($item)
                || ! is_string($item['year'] ?? null)
                || ! is_string($item['quarter'] ?? null)
                || ! is_string($item['fetched_at'] ?? null)
                || ! is_array($item['data'] ?? null)) {
                throw new UnexpectedValueException('Financial statement item invalid.');
            }
        }

        return [
            'ticker' => strtoupper((string) $payload['stock_code']),
            'report_type' => $payload['report_type'],
            'period' => $payload['period'],
            'count' => count($items),
            'items' => array_values($items),
            'currency' => 'NOT_DOCUMENTED',
            'scale' => 'NOT_DOCUMENTED',
            'consolidation_scope' => 'NOT_DOCUMENTED',
            'audit_status' => 'NOT_DOCUMENTED',
            'data_classification' => 'PROVIDER_SUPPLIED',
        ];
    }

    /** @return array<string, mixed> */
    public function parseBrokerAccumulation(array $payload): array
    {
        $this->rejectError($payload);
        if (! preg_match('/^[A-Z0-9^.-]{1,10}$/', (string) ($payload['code'] ?? ''))
            || ! $this->validDate((string) ($payload['start_date'] ?? ''))
            || ! $this->validDate((string) ($payload['end_date'] ?? ''))
            || ! is_array($payload['series'] ?? null)
            || ! is_array($payload['top_buyers'] ?? null)
            || ! is_array($payload['top_sellers'] ?? null)) {
            throw new UnexpectedValueException('Broker accumulation schema drift.');
        }

        $series = [];
        foreach ($payload['series'] as $broker) {
            if (! is_array($broker)
                || ! preg_match('/^[A-Z0-9]{2,4}$/', (string) ($broker['broker_code'] ?? ''))
                || ! is_array($broker['points'] ?? null)) {
                throw new UnexpectedValueException('Broker accumulation series invalid.');
            }
            $points = [];
            foreach ($broker['points'] as $point) {
                if (! is_array($point) || ! $this->validDate((string) ($point['date'] ?? ''))) {
                    throw new UnexpectedValueException('Broker accumulation point date invalid.');
                }
                foreach (['nval', 'nvol', 'cum_nval'] as $field) {
                    if (! is_numeric($point[$field] ?? null)) {
                        throw new UnexpectedValueException("Broker accumulation {$field} invalid.");
                    }
                }
                foreach (['bavg', 'savg'] as $field) {
                    if (($point[$field] ?? null) !== null && ! is_numeric($point[$field])) {
                        throw new UnexpectedValueException("Broker accumulation {$field} invalid.");
                    }
                }
                $points[] = [
                    'date' => $point['date'],
                    'net_value' => (float) $point['nval'],
                    'net_volume' => (float) $point['nvol'],
                    'cumulative_net_value' => (float) $point['cum_nval'],
                    'buy_average' => isset($point['bavg']) && is_numeric($point['bavg']) ? (float) $point['bavg'] : null,
                    'sell_average' => isset($point['savg']) && is_numeric($point['savg']) ? (float) $point['savg'] : null,
                ];
            }
            $series[] = [
                'broker_code' => strtoupper((string) $broker['broker_code']),
                'broker_name' => (string) ($broker['broker_name'] ?? ''),
                'points' => $points,
            ];
        }

        return [
            'ticker' => strtoupper((string) $payload['code']),
            'period_start' => $payload['start_date'],
            'period_end' => $payload['end_date'],
            'series' => $series,
            'top_buyers' => $this->parseBrokerTotals($payload['top_buyers']),
            'top_sellers' => $this->parseBrokerTotals($payload['top_sellers']),
            'value_currency' => 'IDR',
            'volume_unit' => 'NOT_DOCUMENTED',
            'board_scope' => 'UNVERIFIED',
            'data_classification' => 'PROVIDER_SUPPLIED',
        ];
    }

    /** @return array<string, mixed> */
    public function parseInsiders(array $payload): array
    {
        $this->rejectError($payload);
        $items = $payload['items'] ?? null;
        if (! is_array($items) || ! isset($payload['stock_code'], $payload['count'], $payload['total'], $payload['page'], $payload['page_size'], $payload['total_pages'])) {
            throw new UnexpectedValueException('Insider schema drift.');
        }
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['date'] ?? null) || ! Carbon::hasFormat($item['date'], 'Y-m-d')) {
                throw new UnexpectedValueException('Insider item date invalid.');
            }
        }

        return [
            'ticker' => strtoupper((string) $payload['stock_code']),
            'count' => (int) $payload['count'],
            'total' => (int) $payload['total'],
            'page' => (int) $payload['page'],
            'page_size' => (int) $payload['page_size'],
            'total_pages' => (int) $payload['total_pages'],
            'items' => array_map(static fn (array $item): array => $item + ['unit' => 'NOT_DOCUMENTED'], $items),
            'board_scope' => 'UNVERIFIED',
        ];
    }

    /** @return array<string, mixed> */
    public function parseScreener(array $payload): array
    {
        $this->rejectError($payload);
        $rows = $payload['rows'] ?? null;
        if (! is_array($rows) || ! is_string($payload['date'] ?? null)) {
            throw new UnexpectedValueException('Screener schema drift.');
        }
        $asOfDate = $this->parseIndonesianDate($payload['date']);
        foreach ($rows as $row) {
            if (! is_array($row) || ! preg_match('/^[A-Z0-9^.-]{1,10}$/', (string) ($row['stock_code'] ?? ''))) {
                throw new UnexpectedValueException('Screener row ticker invalid.');
            }
        }

        return [
            'as_of_date' => $asOfDate,
            'source' => (string) ($payload['source'] ?? 'NOT_DOCUMENTED'),
            'count' => count($rows),
            'items' => array_map(static fn (array $row): array => $row + ['ticker' => strtoupper((string) $row['stock_code'])], array_values($rows)),
            'data_classification' => 'PROVIDER_SUPPLIED',
            'board_scope' => 'UNVERIFIED',
        ];
    }

    /** @return array<string, mixed> */
    public function parseMovers(array $payload): array
    {
        $this->rejectError($payload);
        foreach (['gainers', 'losers', 'market'] as $key) {
            if (! is_array($payload[$key] ?? null)) {
                throw new UnexpectedValueException('Movers schema drift.');
            }
        }
        foreach (['gainers', 'losers'] as $group) {
            foreach ($payload[$group] as $item) {
                if (! is_array($item) || ! preg_match('/^[A-Z0-9^.-]{1,10}$/', (string) ($item['code'] ?? '')) || ! is_numeric($item['lot'] ?? null)) {
                    throw new UnexpectedValueException('Movers item invalid.');
                }
            }
        }
        if (! is_string($payload['market']['status'] ?? null)) {
            throw new UnexpectedValueException('Movers market status invalid.');
        }

        return [
            'sort' => (string) ($payload['by'] ?? 'NOT_DOCUMENTED'),
            'gainers' => array_values($payload['gainers']),
            'losers' => array_values($payload['losers']),
            'count' => (int) ($payload['count'] ?? 0),
            'market' => $payload['market'],
            'volume_unit' => 'PROVIDER_LOT_FIELD_UNCONVERTED',
            'board_scope' => 'UNVERIFIED',
        ];
    }

    /** @param array<int, mixed> $items */
    private function parseBrokerTotals(array $items): array
    {
        return array_map(function (mixed $item): array {
            if (! is_array($item)
                || ! preg_match('/^[A-Z0-9]{2,4}$/', (string) ($item['broker_code'] ?? ''))
                || ! is_numeric($item['total_nval'] ?? null)) {
                throw new UnexpectedValueException('Broker total item invalid.');
            }

            return [
                'broker_code' => strtoupper((string) $item['broker_code']),
                'broker_name' => (string) ($item['broker_name'] ?? ''),
                'total_net_value' => (float) $item['total_nval'],
            ];
        }, array_values($items));
    }

    private function parseIndonesianDate(string $value): string
    {
        $months = [
            'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
            'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
            'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        ];
        if (! preg_match('/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/u', trim($value), $match)) {
            throw new UnexpectedValueException('Screener provider date invalid.');
        }
        $month = $months[strtolower($match[2])] ?? null;
        if ($month === null || ! checkdate($month, (int) $match[1], (int) $match[3])) {
            throw new UnexpectedValueException('Screener provider date invalid.');
        }

        return Carbon::createFromDate((int) $match[3], $month, (int) $match[1])->toDateString();
    }

    private function validDate(string $value): bool
    {
        return Carbon::hasFormat($value, 'Y-m-d');
    }

    private function rejectError(array $payload): void
    {
        if (isset($payload['error'])) {
            throw new UnexpectedValueException('Provider error response.');
        }
    }
}
