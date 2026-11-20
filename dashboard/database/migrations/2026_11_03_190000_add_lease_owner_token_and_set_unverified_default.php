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
        Schema::table('eod_session_queues', function (Blueprint $table) {
            if (! Schema::hasColumn('eod_session_queues', 'lease_owner_token')) {
                $table->string('lease_owner_token', 64)->nullable()->index();
            }
        });

        Schema::table('market_observations', function (Blueprint $table) {
            // Change default of board_scope to UNVERIFIED so no implicit RG assumption exists
            $table->string('board_scope', 32)->default('UNVERIFIED')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eod_session_queues', function (Blueprint $table) {
            if (Schema::hasColumn('eod_session_queues', 'lease_owner_token')) {
                $table->dropColumn('lease_owner_token');
            }
        });

        Schema::table('market_observations', function (Blueprint $table) {
            $table->string('board_scope', 32)->default('RG')->change();
        });
    }
};
