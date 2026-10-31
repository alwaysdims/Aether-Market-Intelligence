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
        Schema::table('scheduled_telegram_jobs', function (Blueprint $table) {
            $table->timestampTz('data_cutoff_at')->nullable()->after('analysis_completed_at');
            $table->integer('lateness_seconds')->nullable()->after('latency_ms');
            $table->integer('worker_pid')->nullable()->after('delivery_status');
            $table->timestampTz('heartbeat_at')->nullable()->after('worker_pid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_telegram_jobs', function (Blueprint $table) {
            $table->dropColumn(['data_cutoff_at', 'lateness_seconds', 'worker_pid', 'heartbeat_at']);
        });
    }
};
