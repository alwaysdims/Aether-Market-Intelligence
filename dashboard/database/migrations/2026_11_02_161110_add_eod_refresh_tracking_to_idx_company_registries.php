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
        Schema::table('idx_company_registries', function (Blueprint $table) {
            $table->string('eod_refresh_status', 32)->default('IDLE')->index(); // IDLE, FRESH_ALREADY, UPDATED, WAITING_FOR_SESSION, RETRY_SCHEDULED, MAX_RETRIES_EXCEEDED, FAILED
            $table->date('eod_target_session')->nullable()->index();
            $table->unsignedSmallInteger('eod_retry_attempts')->default(0);
            $table->timestampTz('eod_next_retry_at')->nullable()->index();
            $table->timestampTz('eod_last_attempt_at')->nullable();
            $table->string('eod_last_error', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('idx_company_registries', function (Blueprint $table) {
            $table->dropColumn([
                'eod_refresh_status',
                'eod_target_session',
                'eod_retry_attempts',
                'eod_next_retry_at',
                'eod_last_attempt_at',
                'eod_last_error',
            ]);
        });
    }
};
