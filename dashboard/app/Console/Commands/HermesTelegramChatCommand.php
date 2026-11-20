<?php

namespace App\Console\Commands;

use App\Services\TelegramTwoWayService;
use Illuminate\Console\Command;

class HermesTelegramChatCommand extends Command
{
    protected $signature = 'hermes:telegram-chat {--text= : Inbound text from user} {--user=5923895922 : Telegram User ID} {--reply-to= : Telegram reply-to message ID} {--update-id= : Telegram update ID} {--message-id= : Telegram inbound message ID} {--raw : Output plain reply text only} {--json : Output persisted inbound receipt as JSON} {--sync : Execute synchronously without queueing}';

    protected $description = 'Simulate or execute two-way Telegram conversation with grounded database provenance';

    public function handle(TelegramTwoWayService $chatService): int
    {
        $text = $this->option('text');
        $userId = $this->option('user') ?: '5923895922';
        $replyTo = $this->option('reply-to');
        $updateId = $this->option('update-id') ?: ('cli_'.time());
        $messageId = $this->option('message-id') ?: (time() % 1000000);
        $raw = $this->option('raw');
        $sync = (bool) $this->option('sync');

        if (! $text) {
            $text = '/help';
        }

        $update = [
            'update_id' => $updateId,
            '_sync' => $sync,
            'message' => [
                'message_id' => $messageId,
                'from' => ['id' => $userId, 'username' => 'CLI_User'],
                'chat' => ['id' => $userId],
                'text' => $text,
                'reply_to_message' => $replyTo ? ['message_id' => $replyTo] : null,
            ],
        ];

        try {
            $res = $chatService->handleInboundMessage($update);
        } catch (\Throwable $e) {
            $diagnostic = preg_replace('/(?:password|token|secret|api[_ -]?key|authorization)\\s*[=:]\\s*[^\\s,;}]+/i', '[REDACTED]', $e->getMessage()) ?: 'Inbound processing failed';
            $diagnostic = mb_substr($diagnostic, 0, 1000);

            if ($this->option('json')) {
                $this->output->write(json_encode([
                    'status' => 'error',
                    'error_type' => 'INBOUND_PROCESSING_ERROR',
                    'error' => $diagnostic,
                    'telegram_update_id' => (string) $updateId,
                    'inbound_message_id' => (string) $messageId,
                ], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }

            $this->error('Inbound processing failed: '.$diagnostic);

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->write(json_encode([
                'status' => $res['status'],
                'intent' => $res['intent'] ?? null,
                'conversation_id' => $res['conversation_id'] ?? null,
                'telegram_update_id' => (string) $updateId,
                'inbound_message_id' => (string) $messageId,
                'reply_text' => $res['reply_text'],
            ], JSON_THROW_ON_ERROR));

            return $res['status'] === 'success' || $res['status'] === 'duplicate' ? self::SUCCESS : self::FAILURE;
        }

        if ($raw) {
            $this->output->write($res['reply_text']);

            return 0;
        }

        $this->info('=== HASIL PERCAKAPAN DUA ARAH ===');
        $this->line('Status   : '.strtoupper($res['status']));
        $this->line('Intent   : '.($res['intent'] ?? 'UNKNOWN'));
        $this->line('Durasi   : '.($res['duration_ms'] ?? 0).' ms');
        $this->line('──────────────────────────────────────────');
        $this->line($res['reply_text']);
        $this->line('──────────────────────────────────────────');

        return 0;
    }
}
