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
        Schema::table('market_observations', function (Blueprint $table) {
            $table->unique(
                ['ticker', 'bar_date', 'interval', 'data_classification', 'source'],
                'uq_obs_canonical_eod'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('market_observations', function (Blueprint $table) {
            $table->dropUnique('uq_obs_canonical_eod');
        });
    }
};
