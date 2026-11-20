<?php

namespace Tests\Feature;

use App\Models\TelegramConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HermesTelegramChatCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_command_emits_persisted_inbound_receipt_as_json(): void
    {
        $exit = Artisan::call('hermes:telegram-chat', [
            '--text' => '/help',
            '--user' => '5923895922',
            '--update-id' => '910001',
            '--message-id' => '810001',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('success', $payload['status']);
        $this->assertSame('910001', $payload['telegram_update_id']);
        $this->assertSame('810001', $payload['inbound_message_id']);
        $this->assertIsInt($payload['conversation_id']);
        $this->assertDatabaseHas('telegram_conversations', [
            'id' => $payload['conversation_id'],
            'telegram_update_id' => '910001',
        ]);
    }

    public function test_receipt_command_records_numeric_outbound_message_id(): void
    {
        TelegramConversation::create([
            'telegram_update_id' => '910002',
            'chat_id' => '5923895922',
            'user_id' => '5923895922',
            'inbound_text' => '/help',
            'detected_intent' => 'COMMAND_HELP',
            'outbound_reply_text' => 'reply',
            'provenance' => ['inbound_message_id' => '810002'],
        ]);

        $exit = Artisan::call('hermes:record-telegram-receipt', [
            '--update-id' => '910002',
            '--inbound-message-id' => '810002',
            '--outbound-message-id' => '710002',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $conversation = TelegramConversation::where('telegram_update_id', '910002')->firstOrFail();

        $this->assertSame(0, $exit);
        $this->assertSame('recorded', $payload['status']);
        $this->assertSame('710002', $payload['outbound_message_id']);
        $this->assertSame('710002', $conversation->provenance['outbound_message_id']);
        $this->assertSame('810002', $conversation->provenance['inbound_message_id']);
    }
}
