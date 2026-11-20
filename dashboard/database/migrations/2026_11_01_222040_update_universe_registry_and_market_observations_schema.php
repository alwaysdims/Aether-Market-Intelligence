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
        // 1. Expand idx_company_registries with discovery & backfill tracking columns
        Schema::table('idx_company_registries', function (Blueprint $table) {
            if (! Schema::hasColumn('idx_company_registries', 'identity_status')) {
                $table->string('identity_status', 32)->default('UNVERIFIED')->index();
            }
            if (! Schema::hasColumn('idx_company_registries', 'provider_availability')) {
                $table->string('provider_availability', 32)->default('UNKNOWN')->index();
            }
            if (! Schema::hasColumn('idx_company_registries', 'backfill_status')) {
                $table->string('backfill_status', 32)->default('PENDING')->index();
            }
            if (! Schema::hasColumn('idx_company_registries', 'first_bar_date')) {
                $table->date('first_bar_date')->nullable();
            }
            if (! Schema::hasColumn('idx_company_registries', 'last_bar_date')) {
                $table->date('last_bar_date')->nullable();
            }
            if (! Schema::hasColumn('idx_company_registries', 'last_success_at')) {
                $table->timestamp('last_success_at')->nullable();
            }
            if (! Schema::hasColumn('idx_company_registries', 'failure_reason')) {
                $table->text('failure_reason')->nullable();
            }
        });

        // 2. Update unique constraint on market_observations to include adjustment_mode
        Schema::table('market_observations', function (Blueprint $table) {
            try {
                $table->dropUnique('uq_obs_canonical_eod');
            } catch (Throwable $e) {
                // Ignore if not present
            }

            $table->unique(
                ['ticker', 'bar_date', 'interval', 'data_classification', 'source', 'adjustment_mode'],
                'uq_obs_canonical_eod_v2'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('market_observations', function (Blueprint $table) {
            try {
                $table->dropUnique('uq_obs_canonical_eod_v2');
                $table->unique(
                    ['ticker', 'bar_date', 'interval', 'data_classification', 'source'],
                    'uq_obs_canonical_eod'
                );
            } catch (Throwable $e) {
                // Ignore
            }
        });

        Schema::table('idx_company_registries', function (Blueprint $table) {
            $table->dropColumn([
                'identity_status',
                'provider_availability',
                'backfill_status',
                'first_bar_date',
                'last_bar_date',
                'last_success_at',
                'failure_reason',
            ]);
        });
    }
};
