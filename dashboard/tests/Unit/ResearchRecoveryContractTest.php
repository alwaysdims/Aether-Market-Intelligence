<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ResearchRecoveryContractTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/resources/views/dashboard/research.blade.php');
    }

    public function test_query_request_id_has_priority_and_recovery_uses_capability_header(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("const queryRequestId = new URLSearchParams(window.location.search).get('request_id');", $source);
        $this->assertStringContainsString('const saved = queryRequestId || localStorage.getItem(storageKey);', $source);
        $this->assertStringContainsString("'X-Aether-Research-Auth': capability", $source);
    }

    public function test_terminal_status_stops_polling_and_clears_storage_after_render(): void
    {
        $source = $this->source();

        $renderPosition = strpos($source, 'renderData(data);');
        $terminalPosition = strpos($source, 'if (terminal.has(data.status))');
        $clearPosition = strpos($source, 'localStorage.removeItem(storageKey);');

        $this->assertNotFalse($renderPosition);
        $this->assertNotFalse($terminalPosition);
        $this->assertNotFalse($clearPosition);
        $this->assertLessThan($terminalPosition, $renderPosition);
        $this->assertLessThan($clearPosition, $terminalPosition);
        $this->assertStringContainsString('return;', substr($source, $terminalPosition, 160));
    }

    public function test_recovery_path_contains_no_provider_or_inference_call(): void
    {
        $source = $this->source();
        $recoveryStart = strpos($source, 'async function poll(id');
        $submitStart = strpos($source, 'form.addEventListener');
        $recoveryBlock = substr($source, $recoveryStart, $submitStart - $recoveryStart);

        $this->assertStringNotContainsString('chat/completions', $recoveryBlock);
        $this->assertStringNotContainsString('provider', strtolower($recoveryBlock));
        $this->assertStringContainsString('/api/research', $recoveryBlock);
    }
}
