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
        Schema::table('telegram_outbox', function (Blueprint $table) {
            if (! Schema::hasColumn('telegram_outbox', 'due_at')) {
                $table->timestamp('due_at')->nullable()->index();
            }
            if (! Schema::hasColumn('telegram_outbox', 'trigger_source')) {
                $table->string('trigger_source', 64)->default('SCHEDULED')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_outbox', function (Blueprint $table) {
            $table->dropColumn(['due_at', 'trigger_source']);
        });
    }
};
