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
            $table->timestampTz('market_session_closed_at')->nullable()->after('data_cutoff_at');
            $table->timestampTz('evidence_available_at')->nullable()->after('market_session_closed_at');
            $table->timestampTz('analysis_information_cutoff')->nullable()->after('evidence_available_at');
            $table->string('worker_process_created_at', 64)->nullable()->after('worker_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_telegram_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'market_session_closed_at',
                'evidence_available_at',
                'analysis_information_cutoff',
                'worker_process_created_at',
            ]);
        });
    }
};
