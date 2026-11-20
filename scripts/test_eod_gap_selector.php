<?php

require_once __DIR__.'/eod_gap_selector.php';

$now = new DateTimeImmutable('2026-11-18T00:10:00+07:00');
$rows = [
    ['ticker' => 'AAAA', 'eod_target_session' => '2026-11-17', 'eod_retry_attempts' => 2, 'eod_next_retry_at' => '2026-11-18T01:00:00+07:00'],
    ['ticker' => 'BBBB', 'eod_target_session' => '2026-11-16', 'eod_retry_attempts' => 5, 'eod_next_retry_at' => null],
    ['ticker' => 'CCCC', 'eod_target_session' => '2026-11-17', 'eod_retry_attempts' => 1, 'eod_next_retry_at' => '2026-11-17T17:00:00+00:00'],
    ['ticker' => 'DDDD', 'eod_target_session' => '2026-11-17', 'eod_retry_attempts' => 5, 'eod_next_retry_at' => null],
    ['ticker' => 'EEEE', 'eod_target_session' => null, 'eod_retry_attempts' => 0, 'eod_next_retry_at' => null],
];

$result = eod_gap_selector_partition($rows, '2026-11-17', $now);
$selected = eod_gap_selector_select($result, 20, 0);

if (array_column($selected, 'ticker') !== ['BBBB', 'EEEE', 'CCCC']) {
    throw new RuntimeException('NEW then DUE selection failed');
}
if (array_column($result['deferred'], 'ticker') !== ['AAAA']) {
    throw new RuntimeException('DEFERRED classification failed');
}
if (array_column($result['exhausted'], 'ticker') !== ['DDDD']) {
    throw new RuntimeException('EXHAUSTED classification failed');
}
if ($result['total_gap_count'] !== 5 || $result['eligible_count'] !== 3) {
    throw new RuntimeException('gap counts failed');
}

echo "EOD_GAP_SELECTOR_SELF_TEST=PASS cases=5\n";
