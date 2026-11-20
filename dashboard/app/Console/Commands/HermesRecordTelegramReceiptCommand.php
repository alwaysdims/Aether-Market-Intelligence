<?php

namespace App\Console\Commands;

use App\Models\TelegramConversation;
use Illuminate\Console\Command;

class HermesRecordTelegramReceiptCommand extends Command
{
    protected $signature = 'hermes:record-telegram-receipt
        {--update-id= : Numeric Telegram update ID}
        {--inbound-message-id= : Numeric inbound Telegram message ID}
        {--outbound-message-id= : Numeric outbound Telegram message ID}
        {--json : Emit machine-readable receipt}';

    protected $description = 'Persist a Telegram outbound receipt only after a numeric transport message ID exists';

    public function handle(): int
    {
        $updateId = (string) $this->option('update-id');
        $inboundMessageId = (string) $this->option('inbound-message-id');
        $outboundMessageId = (string) $this->option('outbound-message-id');

        foreach ([$updateId, $inboundMessageId, $outboundMessageId] as $value) {
            if (! ctype_digit($value)) {
                $this->writePayload(['status' => 'rejected', 'error' => 'Telegram receipt IDs must be numeric.']);

                return self::FAILURE;
            }
        }

        $conversation = TelegramConversation::where('telegram_update_id', $updateId)->first();
        if (! $conversation) {
            $this->writePayload(['status' => 'missing_inbound', 'telegram_update_id' => $updateId]);

            return self::FAILURE;
        }

        $provenance = is_array($conversation->provenance) ? $conversation->provenance : [];
        if (($provenance['inbound_message_id'] ?? null) !== $inboundMessageId) {
            $this->writePayload(['status' => 'inbound_mismatch', 'telegram_update_id' => $updateId]);

            return self::FAILURE;
        }

        $provenance['outbound_message_id'] = $outboundMessageId;
        $provenance['outbound_receipt_at'] = now()->utc()->toIso8601String();
        $conversation->update(['provenance' => $provenance]);

        $this->writePayload([
            'status' => 'recorded',
            'conversation_id' => $conversation->id,
            'telegram_update_id' => $updateId,
            'inbound_message_id' => $inboundMessageId,
            'outbound_message_id' => $outboundMessageId,
        ]);

        return self::SUCCESS;
    }

    private function writePayload(array $payload): void
    {
        if ($this->option('json')) {
            $this->output->write(json_encode($payload, JSON_THROW_ON_ERROR));

            return;
        }

        $this->line($payload['status']);
    }
}
