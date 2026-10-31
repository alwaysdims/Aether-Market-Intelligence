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
        if (! Schema::hasTable('broker_summaries')) {
            Schema::create('broker_summaries', function (Blueprint $table) {
                $table->id();
                $table->string('ticker', 10)->index();
                $table->date('summary_date')->index();
                $table->string('broker_code', 10)->index();
                $table->string('broker_name', 100)->nullable();
                $table->string('board', 10)->default('RG');
                $table->bigInteger('buy_volume')->default(0);
                $table->double('buy_value')->default(0);
                $table->integer('buy_frequency')->default(0);
                $table->double('buy_avg_price')->nullable();
                $table->bigInteger('sell_volume')->default(0);
                $table->double('sell_value')->default(0);
                $table->integer('sell_frequency')->default(0);
                $table->double('sell_avg_price')->nullable();
                $table->bigInteger('net_volume')->default(0);
                $table->double('net_value')->default(0);
                $table->string('flow_type', 10)->default('all');
                $table->string('unit', 20)->default('SHARES');
                $table->string('currency', 10)->default('IDR');
                $table->string('source', 50)->default('IDX Edge PRO');
                $table->string('data_quality', 30)->default('VERIFIED_ON_SAMPLE');
                $table->timestamp('fetched_at')->nullable();
                $table->jsonb('raw_payload')->nullable();
                $table->string('data_hash', 64)->nullable();
                $table->timestamps();

                $table->unique(['ticker', 'summary_date', 'broker_code', 'flow_type'], 'idx_broker_summaries_unique_entry');
                $table->index(['ticker', 'summary_date'], 'idx_broker_summaries_ticker_date');
            });
        }

        Schema::table('company_fundamentals', function (Blueprint $table) {
            if (! Schema::hasColumn('company_fundamentals', 'data_quality')) {
                $table->string('data_quality', 40)->default('PROVIDER_SUPPLIED')->after('source');
            }
            if (! Schema::hasColumn('company_fundamentals', 'attribution_parent')) {
                $table->double('attribution_parent')->nullable()->after('net_income');
            }
            if (! Schema::hasColumn('company_fundamentals', 'attribution_non_controlling')) {
                $table->double('attribution_non_controlling')->nullable()->after('attribution_parent');
            }
            if (! Schema::hasColumn('company_fundamentals', 'eps_basic')) {
                $table->double('eps_basic')->nullable()->after('shares_outstanding');
            }
            if (! Schema::hasColumn('company_fundamentals', 'ebt')) {
                $table->double('ebt')->nullable()->after('operating_income');
            }
            if (! Schema::hasColumn('company_fundamentals', 'tax_expense')) {
                $table->double('tax_expense')->nullable()->after('ebt');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broker_summaries');

        Schema::table('company_fundamentals', function (Blueprint $table) {
            $cols = ['data_quality', 'attribution_parent', 'attribution_non_controlling', 'eps_basic', 'ebt', 'tax_expense'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('company_fundamentals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
