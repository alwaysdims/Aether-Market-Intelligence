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
        Schema::table('post_horizon_evaluations', function (Blueprint $table) {
            $table->boolean('is_fallback')->default(false)->after('prompt_version');
            $table->string('validation_status')->default('valid_schema')->after('is_fallback');
            $table->index(['is_fallback', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_horizon_evaluations', function (Blueprint $table) {
            $table->dropIndex(['is_fallback', 'status']);
            $table->dropColumn(['is_fallback', 'validation_status']);
        });
    }
};
