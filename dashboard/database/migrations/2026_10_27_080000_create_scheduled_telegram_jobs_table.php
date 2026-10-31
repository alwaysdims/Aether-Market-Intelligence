<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scheduled_telegram_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->index();
            $table->string('telegram_user_id')->index();
            $table->string('telegram_update_id')->nullable()->index();
            $table->string('inbound_message_id')->nullable()->index();
            $table->text('original_prompt');
            $table->string('request_type')->default('MARKET_ANALYSIS_AND_RECOMMENDATION')->index();
            $table->jsonb('parameters')->nullable();
            $table->timestampTz('scheduled_at')->index();
            $table->string('timezone')->default('Asia/Jakarta');
            $table->string('status')->default('PENDING')->index(); // PENDING, PROCESSING, COMPLETED, MISSED, FAILED, CANCELLED
            $table->string('idempotency_key')->unique();
            $table->timestampTz('executed_at')->nullable();
            $table->unsignedBigInteger('analysis_id')->nullable();
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->unsignedBigInteger('outbox_id')->nullable();
            $table->string('outbound_message_id')->nullable();
            $table->text('result_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_telegram_jobs');
    }
};
