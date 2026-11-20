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
        if (! Schema::hasTable('eod_session_queues')) {
            Schema::create('eod_session_queues', function (Blueprint $table) {
                $table->id();
                $table->date('session_date')->index();
                $table->string('status', 32)->default('PENDING')->index(); // PENDING, PROCESSING, QUEUED_WAITING_LOCK, COMPLETED, FAILED
                $table->unsignedSmallInteger('priority')->default(0);
                $table->jsonb('target_tickers')->nullable();
                $table->unsignedInteger('lock_owner_pid')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->text('last_error')->nullable();
                $table->jsonb('stats')->nullable();
                $table->timestamps();

                $table->unique(['session_date', 'status'], 'uq_session_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eod_session_queues');
    }
};
