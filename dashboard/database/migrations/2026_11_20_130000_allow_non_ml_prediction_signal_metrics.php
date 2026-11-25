<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prediction_signals')) {
            return;
        }

        Schema::table('prediction_signals', function (Blueprint $table): void {
            $table->decimal('probability_up', 6, 4)->nullable()->change();
            $table->decimal('probability_down', 6, 4)->nullable()->change();
            $table->decimal('max_probability', 6, 4)->nullable()->change();
            $table->decimal('confidence_score', 6, 4)->nullable()->change();
            $table->decimal('threshold_applied', 6, 4)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('prediction_signals')) {
            return;
        }

        Schema::table('prediction_signals', function (Blueprint $table): void {
            $table->decimal('probability_up', 6, 4)->nullable(false)->change();
            $table->decimal('probability_down', 6, 4)->nullable(false)->change();
            $table->decimal('max_probability', 6, 4)->nullable(false)->change();
            $table->decimal('confidence_score', 6, 4)->nullable(false)->change();
            $table->decimal('threshold_applied', 6, 4)->default(0.7000)->nullable(false)->change();
        });
    }
};
