<?php

namespace Tests\Unit;

use App\Console\Commands\RefreshDailyEodCommand;
use ReflectionMethod;
use Tests\TestCase;

class RefreshDailyEodCommandTest extends TestCase
{
    public function test_retry_evidence_merge_preserves_prior_batch_and_appends_attempt_history(): void
    {
        $command = new RefreshDailyEodCommand;
        $method = new ReflectionMethod($command, 'mergeRetryEvidenceStats');
        $method->setAccessible(true);
        $existing = [
            'retry_evidence' => [
                'AAA' => ['session_date' => '2026-11-17', 'adjusted_request' => ['success' => false]],
            ],
            'retry_evidence_history' => [
                ['queue_attempt' => 1, 'evidence' => ['AAA' => ['session_date' => '2026-11-17']]],
            ],
        ];
        $incoming = [
            'AAA' => ['session_date' => '2026-11-17', 'adjusted_request' => ['success' => true]],
            'BBB' => ['session_date' => '2026-11-17', 'adjusted_request' => ['success' => false]],
        ];

        $merged = $method->invoke($command, $existing, $incoming, 2, '2026-11-17T17:05:00+00:00');

        $this->assertTrue($merged['retry_evidence']['AAA']['adjusted_request']['success']);
        $this->assertArrayHasKey('BBB', $merged['retry_evidence']);
        $this->assertCount(2, $merged['retry_evidence_history']);
        $this->assertSame(2, $merged['retry_evidence_history'][1]['queue_attempt']);
        $this->assertSame('2026-11-17T17:05:00+00:00', $merged['retry_evidence_history'][1]['recorded_at']);
        $this->assertSame($incoming, $merged['retry_evidence_history'][1]['evidence']);
    }
}
