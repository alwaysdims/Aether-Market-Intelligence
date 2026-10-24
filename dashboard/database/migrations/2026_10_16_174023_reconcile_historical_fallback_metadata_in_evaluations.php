<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Reconciles historical post_horizon_evaluations records created before the is_fallback column migration
     * to match the parent market_analyses.validation_status.
     */
    public function up(): void
    {
        DB::table('post_horizon_evaluations')
            ->whereIn('market_analysis_id', function ($query) {
                $query->select('id')
                    ->from('market_analyses')
                    ->where('validation_status', 'fallback');
            })
            ->where('is_fallback', false)
            ->update([
                'is_fallback' => true,
                'validation_status' => 'fallback',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert records created on or before 2026-10-16 16:34:51
        DB::table('post_horizon_evaluations')
            ->where('market_analysis_id', 3)
            ->update([
                'is_fallback' => false,
                'validation_status' => 'valid_schema',
                'updated_at' => now(),
            ]);
    }
};
