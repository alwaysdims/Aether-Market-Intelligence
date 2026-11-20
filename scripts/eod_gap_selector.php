<?php

/**
 * Partition canonical DB gaps without changing total-gap semantics.
 *
 * @param  array<int, array<string, mixed>>  $rows
 * @return array{new: array<int, array<string, mixed>>, due: array<int, array<string, mixed>>, deferred: array<int, array<string, mixed>>, exhausted: array<int, array<string, mixed>>, total_gap_count: int, eligible_count: int}
 */
function eod_gap_selector_partition(array $rows, string $targetSession, DateTimeImmutable $now): array
{
    $partitions = ['new' => [], 'due' => [], 'deferred' => [], 'exhausted' => []];
    foreach ($rows as $row) {
        $rowSession = $row['eod_target_session'] ?? null;
        $attempts = (int) ($row['eod_retry_attempts'] ?? 0);
        $nextRetry = ! empty($row['eod_next_retry_at']) ? new DateTimeImmutable((string) $row['eod_next_retry_at']) : null;

        if ($rowSession !== $targetSession) {
            $partitions['new'][] = $row;
        } elseif ($attempts >= 5) {
            $partitions['exhausted'][] = $row;
        } elseif ($nextRetry !== null && $nextRetry > $now) {
            $partitions['deferred'][] = $row;
        } else {
            $partitions['due'][] = $row;
        }
    }
    foreach (array_keys($partitions) as $key) {
        usort($partitions[$key], static fn (array $a, array $b): int => strcmp((string) $a['ticker'], (string) $b['ticker']));
    }

    return $partitions + [
        'total_gap_count' => count($rows),
        'eligible_count' => count($partitions['new']) + count($partitions['due']),
    ];
}

/** @return array<int, array<string, mixed>> */
function eod_gap_selector_select(array $partitions, int $limit, int $offset = 0): array
{
    return array_slice(array_merge($partitions['new'], $partitions['due']), $offset, $limit);
}
