<?php

require_once __DIR__.'/eod_auto_orchestrator.php';

$targets = [
    ['target_session' => '2026-11-18', 'queue_status' => 'PENDING'],
    ['target_session' => '2026-11-17', 'queue_status' => 'PENDING'],
    ['target_session' => '2026-11-16', 'queue_status' => 'COMPLETED'],
    ['target_session' => '2026-11-15', 'queue_status' => 'QUEUED_WAITING_LOCK'],
];

$selected = eod_orchestrator_select_prior_pending_session($targets, '2026-11-18');
if ($selected !== '2026-11-17') {
    throw new RuntimeException('nearest eligible backlog session must continue first');
}

$selected = eod_orchestrator_select_prior_pending_session($targets, '2026-11-17');
if ($selected !== '2026-11-15') {
    throw new RuntimeException('regular target must not replace older pending session');
}

$selected = eod_orchestrator_select_prior_pending_session([
    ['target_session' => '2026-11-18', 'queue_status' => 'PENDING'],
], '2026-11-18');
if ($selected !== null) {
    throw new RuntimeException('current target must not self-continue');
}

$selected = eod_orchestrator_select_prior_pending_session([
    ['target_session' => '2026-11-15', 'queue_status' => 'PENDING', 'needs_recovery' => false],
    ['target_session' => '2026-11-14', 'queue_status' => 'PENDING', 'needs_recovery' => true],
], '2026-11-18');
if ($selected !== '2026-11-14') {
    throw new RuntimeException('completed or exhausted backlog must not block eligible continuation');
}

echo "EOD_CONTINUATION_PLANNER_SELF_TEST=PASS cases=4\n";
