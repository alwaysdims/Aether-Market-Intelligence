<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prediction_signals') && ! Schema::hasColumn('prediction_signals', 'technical_context')) {
            Schema::table('prediction_signals', function (Blueprint $table): void {
                $table->jsonb('technical_context')->nullable()->after('invalidation_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('prediction_signals') && Schema::hasColumn('prediction_signals', 'technical_context')) {
            Schema::table('prediction_signals', function (Blueprint $table): void {
                $table->dropColumn('technical_context');
            });
        }
    }
};
