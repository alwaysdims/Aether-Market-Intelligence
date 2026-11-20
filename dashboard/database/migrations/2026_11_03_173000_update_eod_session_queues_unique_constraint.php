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
            $table->dropUnique('uq_session_status');
            $table->unique('session_date', 'uq_session_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eod_session_queues', function (Blueprint $table) {
            $table->dropUnique('uq_session_date');
            $table->unique(['session_date', 'status'], 'uq_session_status');
        });
    }
};
