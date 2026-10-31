<?php

namespace App\Console\Commands;

use App\Services\AiToolExecutorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class HermesMcpServerCommand extends Command
{
    protected $signature = 'hermes:mcp-server';

    protected $description = 'Thin STDIO MCP Server exposing governed Aether tools to Hermes Agent';

    public function handle(AiToolExecutorService $toolExecutor): int
    {
        // Set unbuffered UTF-8 stdin/stdout
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        $stdin = fopen('php://stdin', 'r');
        $stdout = fopen('php://stdout', 'w');

        while (! feof($stdin)) {
            $line = fgets($stdin);
            if ($line === false || trim($line) === '') {
                continue;
            }

            $request = json_decode(trim($line), true);
            if (! is_array($request) || ! isset($request['method'])) {
                continue;
            }

            $response = $this->handleMcpRequest($request, $toolExecutor);
            if ($response !== null) {
                fwrite($stdout, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                fflush($stdout);
            }
        }

        fclose($stdin);
        fclose($stdout);

        return 0;
    }

    protected function handleMcpRequest(array $req, AiToolExecutorService $toolExecutor): ?array
    {
        $id = $req['id'] ?? null;
        $method = $req['method'] ?? '';
        $params = $req['params'] ?? [];

        switch ($method) {
            case 'initialize':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => [
                            'tools' => new \stdClass,
                        ],
                        'serverInfo' => [
                            'name' => 'aether-core',
                            'version' => '1.0.0',
                        ],
                    ],
                ];

            case 'notifications/initialized':
            case 'initialized':
                return null;

            case 'tools/list':
                $toolDefs = $toolExecutor->getToolSchemas();
                $mcpTools = [];

                // Filter out any unsafe / non-consultative tools if any
                $allowed = [
                    'get_broker_summary',
                    'get_company_fundamentals',
                    'get_instrument_analysis',
                    'get_related_news',
                    'search_latest_disclosures_and_filings',
                    'screen_stocks',
                    'screen_broker_accumulation',
                    'compare_stocks',
                    'get_prediction_recap',
                    'get_system_status',
                ];

                foreach ($toolDefs as $t) {
                    $fn = $t['function'] ?? [];
                    $name = $fn['name'] ?? '';
                    if (in_array($name, $allowed, true)) {
                        $mcpTools[] = [
                            'name' => $name,
                            'description' => $fn['description'] ?? '',
                            'inputSchema' => $fn['parameters'] ?? [
                                'type' => 'object',
                                'properties' => new \stdClass,
                            ],
                        ];
                    }
                }

                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'tools' => $mcpTools,
                    ],
                ];

            case 'tools/call':
                $name = $params['name'] ?? '';
                $args = $params['arguments'] ?? [];
                $provenance = [
                    'orchestrator' => 'HERMES_MCP_SERVER',
                    'called_at' => now('Asia/Jakarta')->toIso8601String(),
                ];

                try {
                    $resultText = $toolExecutor->executeTool(
                        $name,
                        $args,
                        config('services.telegram.chat_id', '5923895922'),
                        config('services.telegram.chat_id', '5923895922'),
                        $provenance
                    );

                    return [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => $resultText,
                                ],
                            ],
                            'isError' => false,
                            '_meta' => [
                                'evidence_id' => $provenance['ai_evidence_id'] ?? null,
                                'analysis_id' => $provenance['ai_analysis_id'] ?? null,
                                'quota_cost' => $provenance['quota_cost'] ?? 0,
                            ],
                        ],
                    ];
                } catch (\Throwable $e) {
                    Log::error("[HermesMcpServer] Tool {$name} execution failed: ".$e->getMessage());

                    return [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => "Error executing tool {$name}: ".$e->getMessage(),
                                ],
                            ],
                            'isError' => true,
                        ],
                    ];
                }

            default:
                if ($id !== null) {
                    return [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'error' => [
                            'code' => -32601,
                            'message' => "Method not found: {$method}",
                        ],
                    ];
                }

                return null;
        }
    }
}
