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
            $table->string('worker_token', 64)->nullable()->after('worker_pid');
            $table->string('bar_time', 32)->nullable()->after('data_cutoff_at');
            $table->timestampTz('data_available_at')->nullable()->after('bar_time');
            $table->integer('tolerance_minutes')->default(60)->after('lateness_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_telegram_jobs', function (Blueprint $table) {
            $table->dropColumn(['worker_token', 'bar_time', 'data_available_at', 'tolerance_minutes']);
        });
    }
};
