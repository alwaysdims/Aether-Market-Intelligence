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
        Schema::table('data_sources', function (Blueprint $table) {
            if (! Schema::hasColumn('data_sources', 'endpoint')) {
                $table->string('endpoint')->nullable()->after('base_url');
            }
            if (! Schema::hasColumn('data_sources', 'is_enabled')) {
                $table->boolean('is_enabled')->default(false)->after('status');
            }
            if (! Schema::hasColumn('data_sources', 'health')) {
                $table->string('health')->default('unknown')->after('is_enabled');
            }
            if (! Schema::hasColumn('data_sources', 'data_quality')) {
                $table->string('data_quality')->default('NOT_VERIFIED')->after('health');
            }
            if (! Schema::hasColumn('data_sources', 'quarantine_reason')) {
                $table->text('quarantine_reason')->nullable()->after('data_quality');
            }
            if (! Schema::hasColumn('data_sources', 'last_attempt_at')) {
                $table->timestamp('last_attempt_at')->nullable()->after('quarantine_reason');
            }
            if (! Schema::hasColumn('data_sources', 'metadata')) {
                $table->jsonb('metadata')->nullable()->after('last_attempt_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn([
                'endpoint',
                'is_enabled',
                'health',
                'data_quality',
                'quarantine_reason',
                'last_attempt_at',
                'metadata',
            ]);
        });
    }
};
