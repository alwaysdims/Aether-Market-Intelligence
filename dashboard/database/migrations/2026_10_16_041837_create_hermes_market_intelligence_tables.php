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
        // 1. Data Sources
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type'); // market_data, broker_summary, news
            $table->string('data_classification')->default('end_of_day'); // historical, end_of_day, delayed, real_time, unknown
            $table->string('base_url')->nullable();
            $table->string('status')->default('active'); // active, degraded, inactive
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
        });

        // 2. Prompt Templates
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code'); // e.g. MARKET_INTELLIGENCE_DAILY
            $table->string('version')->default('1.0.0');
            $table->string('model_id')->default('ag/gemini-3.8-flash-high');
            $table->text('system_prompt');
            $table->text('user_prompt_template');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'version']);
        });

        // 3. Analysis Runs
        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('trigger_type')->default('scheduled'); // scheduled, manual, event
            $table->string('status')->default('pending'); // pending, running, completed, failed
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->float('duration_seconds')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
        });

        // 4. Market Snapshots
        Schema::create('market_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained('analysis_runs')->nullOnDelete();
            $table->string('ticker');
            $table->date('bar_date');
            $table->timestamp('source_timestamp');
            $table->decimal('close_price', 12, 2);
            $table->decimal('open_price', 12, 2)->nullable();
            $table->decimal('high_price', 12, 2)->nullable();
            $table->decimal('low_price', 12, 2)->nullable();
            $table->bigInteger('volume')->nullable();
            $table->jsonb('indicators')->nullable();
            $table->string('data_classification')->default('end_of_day');
            $table->timestamps();

            $table->index(['ticker', 'bar_date']);
        });

        // 5. News Items
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('url')->unique();
            $table->string('source_name');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('fetched_at');
            $table->text('summary')->nullable();
            $table->string('verification_status')->default('unverified'); // verified_fact, official_statement, opinion, rumor, unverified
            $table->jsonb('related_tickers')->nullable();
            $table->timestamps();

            $table->index('published_at');
        });

        // 6. Market Analyses
        Schema::create('market_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained('analysis_runs')->cascadeOnDelete();
            $table->foreignId('prompt_template_id')->nullable()->constrained('prompt_templates')->nullOnDelete();
            $table->string('model_name')->default('ag/gemini-3.8-flash-high');
            $table->string('prompt_version')->default('1.0.0');
            $table->text('market_regime_summary');
            $table->string('impact_direction')->default('unclear'); // positive, negative, mixed, unclear
            $table->string('impact_horizon')->default('multi_day'); // intraday, multi_day, long_term, unknown
            $table->decimal('confidence_score', 4, 2)->nullable();
            $table->jsonb('structured_output')->nullable();
            $table->string('validation_status')->default('valid_schema'); // valid_schema, invalid_schema, fallback
            $table->timestamps();

            $table->index('impact_direction');
        });

        // 7. Notification Logs
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->default('telegram'); // telegram, dashboard
            $table->string('destination'); // e.g. telegram:5923895922
            $table->foreignId('analysis_run_id')->nullable()->constrained('analysis_runs')->nullOnDelete();
            $table->foreignId('market_analysis_id')->nullable()->constrained('market_analyses')->nullOnDelete();
            $table->string('message_hash')->index();
            $table->text('message_payload');
            $table->string('status')->default('sent'); // sent, failed, skipped_duplicate
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // 8. System Health Checks
        Schema::create('system_health_checks', function (Blueprint $table) {
            $table->id();
            $table->string('component'); // database, hermes_agent, ai_model, telegram_bot, scheduler
            $table->string('status'); // healthy, degraded, down
            $table->integer('latency_ms')->nullable();
            $table->jsonb('details')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['component', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_health_checks');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('market_analyses');
        Schema::dropIfExists('news_items');
        Schema::dropIfExists('market_snapshots');
        Schema::dropIfExists('analysis_runs');
        Schema::dropIfExists('prompt_templates');
        Schema::dropIfExists('data_sources');
    }
};
