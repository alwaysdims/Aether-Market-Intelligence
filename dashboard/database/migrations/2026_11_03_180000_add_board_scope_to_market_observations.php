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
            if (! Schema::hasColumn('market_observations', 'board_scope')) {
                $table->string('board_scope', 32)->default('RG')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('market_observations', function (Blueprint $table) {
            if (Schema::hasColumn('market_observations', 'board_scope')) {
                $table->dropColumn('board_scope');
            }
        });
    }
};
