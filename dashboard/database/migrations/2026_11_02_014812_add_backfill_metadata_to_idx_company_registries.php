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
            $table->unsignedInteger('adjusted_bars_count')->default(0)->after('last_bar_date');
            $table->unsignedInteger('raw_bars_count')->default(0)->after('adjusted_bars_count');
            $table->jsonb('backfill_metadata')->nullable()->after('failure_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('idx_company_registries', function (Blueprint $table) {
            $table->dropColumn(['adjusted_bars_count', 'raw_bars_count', 'backfill_metadata']);
        });
    }
};
