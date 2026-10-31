<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for IDX Full Expansion: Registry, Fundamentals, Actions, Metrics & Ingestion Jobs.
     */
    public function up(): void
    {
        // 1. IDX Company Registry
        if (! Schema::hasTable('idx_company_registries')) {
            Schema::create('idx_company_registries', function (Blueprint $table) {
                $table->id();
                $table->string('ticker', 10)->unique();
                $table->string('company_name');
                $table->date('listing_date')->nullable();
                $table->string('listing_board', 50)->default('Utama');
                $table->string('sector', 100)->nullable();
                $table->string('industry', 150)->nullable();
                $table->bigInteger('shares_outstanding')->nullable();
                $table->string('instrument_type', 30)->default('EQUITY'); // EQUITY, INDEX, ETF, WARRANT
                $table->string('listing_status', 30)->default('ACTIVE'); // ACTIVE, SUSPENDED, DELISTED
                $table->boolean('is_validated_ml')->default(false);
                $table->string('source', 100)->default('IDX Official Directory (Mirror / Fallback)');
                $table->string('source_url')->nullable();
                $table->date('snapshot_date');
                $table->string('raw_hash', 64)->nullable();
                $table->timestamps();

                $table->index('instrument_type');
                $table->index('listing_status');
                $table->index('sector');
            });
        }

        // 2. Company Fundamentals (Annual & Interim Statements)
        if (! Schema::hasTable('company_fundamentals')) {
            Schema::create('company_fundamentals', function (Blueprint $table) {
                $table->id();
                $table->string('ticker', 10)->index();
                $table->string('report_type', 20); // ANNUAL, QUARTERLY
                $table->integer('fiscal_year');
                $table->integer('fiscal_quarter')->nullable(); // 1, 2, 3, 4
                $table->date('period_end_date');
                $table->date('filing_date')->nullable();
                $table->string('currency', 10)->default('IDR');
                $table->string('unit', 20)->default('IDR');
                $table->boolean('is_audited')->default(false);
                $table->boolean('is_restated')->default(false);
                $table->double('total_revenue')->nullable();
                $table->double('gross_profit')->nullable();
                $table->double('operating_income')->nullable();
                $table->double('net_income')->nullable();
                $table->double('total_assets')->nullable();
                $table->double('total_liabilities')->nullable();
                $table->double('total_equity')->nullable();
                $table->double('total_debt')->nullable();
                $table->double('operating_cash_flow')->nullable();
                $table->double('capital_expenditure')->nullable();
                $table->double('free_cash_flow')->nullable();
                $table->bigInteger('shares_outstanding')->nullable();
                $table->string('source', 100);
                $table->string('raw_data_hash', 64)->nullable();
                $table->jsonb('raw_payload')->nullable();
                $table->timestamps();

                $table->unique(['ticker', 'report_type', 'fiscal_year', 'fiscal_quarter'], 'idx_fundamentals_unique_period');
            });
        }

        // 3. Corporate Actions (Dividends & Stock Splits)
        if (! Schema::hasTable('company_corporate_actions')) {
            Schema::create('company_corporate_actions', function (Blueprint $table) {
                $table->id();
                $table->string('ticker', 10)->index();
                $table->string('action_type', 30); // DIVIDEND, STOCK_SPLIT, RIGHTS_ISSUE
                $table->date('execution_date');
                $table->double('amount_or_ratio');
                $table->jsonb('details')->nullable();
                $table->string('source', 100);
                $table->timestamps();

                $table->unique(['ticker', 'action_type', 'execution_date'], 'idx_actions_unique');
            });
        }

        // 4. Company Analysis Metrics (Deterministic Technical & Fundamental Metrics)
        if (! Schema::hasTable('company_analysis_metrics')) {
            Schema::create('company_analysis_metrics', function (Blueprint $table) {
                $table->id();
                $table->string('ticker', 10)->index();
                $table->date('as_of_date');

                // Technical Indicators
                $table->double('close_price')->nullable();
                $table->double('return_1d_pct')->nullable();
                $table->double('return_5d_pct')->nullable();
                $table->double('return_20d_pct')->nullable();
                $table->double('sma_20')->nullable();
                $table->double('sma_50')->nullable();
                $table->double('sma_200')->nullable();
                $table->double('rsi_14')->nullable();
                $table->double('macd')->nullable();
                $table->double('macd_signal')->nullable();
                $table->double('macd_hist')->nullable();
                $table->double('atr_14')->nullable();
                $table->double('atr_14_pct')->nullable();
                $table->double('rvol_20')->nullable();
                $table->double('max_drawdown_1y_pct')->nullable();
                $table->double('support_price')->nullable();
                $table->double('resistance_price')->nullable();
                $table->string('technical_trend', 30)->default('SIDEWAYS'); // BULLISH, BEARISH, SIDEWAYS, N/A
                $table->string('technical_status', 30)->default('NO_DATA'); // CALCULATED, INSUFFICIENT_HISTORY, NO_DATA

                // Fundamental Ratios
                $table->double('pe_ratio')->nullable();
                $table->double('pbv_ratio')->nullable();
                $table->double('roe_pct')->nullable();
                $table->double('roa_pct')->nullable();
                $table->double('der_ratio')->nullable();
                $table->double('net_profit_margin_pct')->nullable();
                $table->double('operating_margin_pct')->nullable();
                $table->double('revenue_growth_yoy_pct')->nullable();
                $table->double('net_income_growth_yoy_pct')->nullable();
                $table->double('dividend_yield_pct')->nullable();
                $table->double('eps')->nullable();
                $table->double('bvps')->nullable();
                $table->string('sector_template', 30)->default('GENERAL'); // GENERAL, BANKING, MINING, PROPERTY
                $table->string('fundamental_status', 30)->default('NO_DATA'); // CALCULATED, PARTIAL, NO_DATA
                $table->jsonb('data_quality_flags')->nullable();
                $table->timestamps();

                $table->unique(['ticker', 'as_of_date'], 'idx_metrics_unique_date');
            });
        }

        // 5. Ingestion Pipeline & Checkpoint Recovery Jobs
        if (! Schema::hasTable('idx_ingestion_jobs')) {
            Schema::create('idx_ingestion_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('job_type', 30); // REGISTRY, PRICE_HISTORY, FUNDAMENTALS, METRICS, NARRATIVE
                $table->string('ticker', 10)->nullable()->index();
                $table->string('status', 30)->default('PENDING'); // PENDING, RUNNING, COMPLETED, PARTIAL, FAILED, BLOCKED_SOURCE
                $table->integer('progress_pct')->default(0);
                $table->integer('attempt_count')->default(0);
                $table->integer('max_attempts')->default(3);
                $table->text('error_message')->nullable();
                $table->jsonb('checkpoint_data')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('lease_token', 64)->nullable();
                $table->timestamp('lease_expires_at')->nullable();
                $table->timestamps();

                $table->index(['job_type', 'status']);
                $table->index('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idx_ingestion_jobs');
        Schema::dropIfExists('company_analysis_metrics');
        Schema::dropIfExists('company_corporate_actions');
        Schema::dropIfExists('company_fundamentals');
        Schema::dropIfExists('idx_company_registries');
    }
};
