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
            $table->timestampTz('started_at')->nullable()->after('scheduled_at');
            $table->timestampTz('analysis_completed_at')->nullable()->after('started_at');
            $table->timestampTz('sent_at')->nullable()->after('executed_at');
            $table->string('delivery_status')->nullable()->default('PENDING')->index()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_telegram_jobs', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'analysis_completed_at', 'sent_at', 'delivery_status']);
        });
    }
};
