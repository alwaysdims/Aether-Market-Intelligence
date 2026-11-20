<?php

function classify_eod_result(string $stdout, string $stderr, int $childExitCode): array
{
    $text = $stdout."\n".$stderr;
    if (preg_match('/EOD semantic_status=FAILED exit_code=1 reason=INTERNAL_VERIFICATION_FAILED/i', $text)) {
        return ['semantic_status' => 'FAILED', 'exit_code' => 1, 'reason' => 'INTERNAL_VERIFICATION_FAILED'];
    }

    $semanticLine = preg_match('/EOD child_semantic_status=(COMPLETE|WAITING|FAILED)(?: exit_code=(\d+))?(?: reason=([A-Z_]+))?/i', $text, $childMatch)
        ? $childMatch
        : null;
    if ($semanticLine === null && preg_match_all('/EOD semantic_status=(COMPLETE|WAITING|FAILED)(?: exit_code=(\d+))?(?: reason=([A-Z_]+))?/i', $text, $matches, PREG_SET_ORDER)) {
        $semanticLine = $matches[0];
    }
    if ($semanticLine !== null) {
        $status = strtoupper($semanticLine[1]);
        if ($status === 'WAITING') {
            $reason = $semanticLine[3] ?: 'BACKLOG_REMAINING';

            return ['semantic_status' => 'WAITING', 'exit_code' => 75, 'reason' => $reason];
        }
        if ($status === 'COMPLETE' && $childExitCode === 0) {
            return ['semantic_status' => 'COMPLETE', 'exit_code' => 0, 'reason' => $semanticLine[3] ?: 'ALL_REPORTED_GATES_PASS'];
        }
        return ['semantic_status' => 'FAILED', 'exit_code' => 1, 'reason' => $semanticLine[3] ?: 'CHILD_FAILURE'];
    }

    if ($childExitCode !== 0 || preg_match('/SQLSTATE\[|QueryException|permission denied|Exception|Fatal error/i', $text)) {
        return ['semantic_status' => 'FAILED', 'exit_code' => 1, 'reason' => 'CHILD_FAILURE'];
    }

    if (preg_match('/lock exists|QUEUED_WAITING_LOCK|owner.*active/i', $text)) {
        return ['semantic_status' => 'WAITING', 'exit_code' => 75, 'reason' => 'BLOCKED_OVERLAP'];
    }

    $waiting = preg_match('/Waiting for session data:\s*(\d+)/i', $stdout, $waitingMatch)
        && (int) $waitingMatch[1] > 0;
    $complete = preg_match('/Execution complete:\s*YES/i', $stdout)
        && preg_match('/Selected scope complete:\s*YES/i', $stdout)
        && preg_match('/Data coverage complete:\s*YES/i', $stdout)
        && ! $waiting
        && preg_match('/Fetch errors:\s*0/i', $stdout);

    if ($complete) {
        return ['semantic_status' => 'COMPLETE', 'exit_code' => 0, 'reason' => 'ALL_REPORTED_GATES_PASS'];
    }
    if ($waiting) {
        return ['semantic_status' => 'WAITING', 'exit_code' => 75, 'reason' => 'BACKLOG_REMAINING'];
    }

    return ['semantic_status' => 'FAILED', 'exit_code' => 1, 'reason' => 'INCOMPLETE_OR_UNCLASSIFIED_RESULT'];
}
