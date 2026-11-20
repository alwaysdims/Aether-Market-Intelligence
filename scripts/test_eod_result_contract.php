<?php

require_once __DIR__.'/eod_result_classifier.php';

$cases = [
    [
        'name' => 'exception overrides child zero',
        'stdout' => "Starting Daily EOD Refresh\nSQLSTATE[42501]: permission denied for table idx_company_registries\n",
        'stderr' => '',
        'child_exit' => 0,
        'status' => 'FAILED',
        'exit' => 1,
    ],
    [
        'name' => 'waiting stays distinct',
        'stdout' => "EOD Refresh completed for session: 2026-11-16\n- Waiting for session data: 499\n- Fetch errors: 0\n- Execution complete: YES\n- Selected scope complete: NO\n- Data coverage complete: NO (Universe incomplete)\n",
        'stderr' => '',
        'child_exit' => 0,
        'status' => 'WAITING',
        'exit' => 75,
    ],
    [
        'name' => 'complete requires all gates',
        'stdout' => "EOD Refresh completed for session: 2026-11-16\n- Waiting for session data: 0\n- Fetch errors: 0\n- Execution complete: YES\n- Selected scope complete: YES\n- Data coverage complete: YES\n",
        'stderr' => '',
        'child_exit' => 0,
        'status' => 'COMPLETE',
        'exit' => 0,
    ],
    [
        'name' => 'child nonzero is failed',
        'stdout' => '',
        'stderr' => 'provider process stopped',
        'child_exit' => 7,
        'status' => 'FAILED',
        'exit' => 1,
    ],
    [
        'name' => 'child waiting survives wrapper exit 75',
        'stdout' => "EOD child_semantic_status=WAITING exit_code=75 reason=WAITING_PROVIDER\n",
        'stderr' => '',
        'child_exit' => 75,
        'status' => 'WAITING',
        'exit' => 75,
    ],
];

foreach ($cases as $case) {
    $actual = classify_eod_result($case['stdout'], $case['stderr'], $case['child_exit']);
    assert($actual['semantic_status'] === $case['status'], $case['name']);
    assert($actual['exit_code'] === $case['exit'], $case['name']);
}

echo "eod_result_contract=PASS cases=".count($cases).PHP_EOL;
