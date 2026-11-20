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
            $table->dropUnique('uq_obs_ticker_date_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('market_observations', function (Blueprint $table) {
            $table->unique(['ticker', 'bar_date', 'observation_time'], 'uq_obs_ticker_date_time');
        });
    }
};
