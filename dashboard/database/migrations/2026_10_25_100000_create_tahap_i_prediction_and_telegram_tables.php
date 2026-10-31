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
        // 1. Evidence Snapshots
        Schema::create('evidence_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id')->unique();
            $table->string('ticker', 10)->index();
            $table->date('decision_date')->index();
            $table->foreignId('market_snapshot_id')->nullable()->constrained('market_snapshots')->nullOnDelete();
            $table->string('market_regime')->default('SIDEWAYS');
            $table->jsonb('features_snapshot');
            $table->jsonb('related_news_snapshot')->nullable();
            $table->string('data_hash', 64);
            $table->timestamps();
        });

        // 2. Prediction Signals (PostgreSQL primary store for forward predictions)
        Schema::create('prediction_signals', function (Blueprint $table) {
            $table->id();
            $table->string('prediction_id')->unique();
            $table->string('parent_prediction_id')->nullable()->index();
            $table->string('ticker', 10)->index();
            $table->string('signal_status')->index(); // CANDIDATE, PUBLISHED_DIRECTIONAL, NO_SIGNAL_ABSTAIN
            $table->string('direction')->index(); // UP, DOWN, ABSTAIN
            $table->decimal('probability_up', 6, 4);
            $table->decimal('probability_down', 6, 4);
            $table->decimal('max_probability', 6, 4);
            $table->decimal('confidence_score', 6, 4);
            $table->decimal('threshold_applied', 6, 4)->default(0.7000);
            $table->string('model_name');
            $table->string('model_version');
            $table->string('feature_schema_hash', 32);
            $table->text('reason_for_abstain')->nullable();
            $table->date('decision_date')->index();
            $table->timestamp('decision_timestamp')->index();
            $table->decimal('reference_price', 12, 2);
            $table->date('reference_price_date');
            $table->integer('predicted_horizon_days')->default(5);
            $table->date('evaluation_due_date')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('expires_at')->index(); // Max 14 calendar days from published_at
            $table->string('monitoring_status')->default('NOT_MONITORED')->index(); // NOT_MONITORED, ACTIVE, TARGET_HIT, INVALIDATED, AMBIGUOUS, EXPIRED, CANCELLED
            $table->string('evaluation_status')->default('PENDING')->index(); // PENDING, EVALUATED_CORRECT, EVALUATED_INCORRECT, EVALUATED_NEUTRAL, CANNOT_EVALUATE_MISSING_DATA
            $table->decimal('target_price', 12, 2)->nullable();
            $table->decimal('invalidation_price', 12, 2)->nullable();
            $table->foreignId('evidence_snapshot_id')->nullable()->constrained('evidence_snapshots')->nullOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained('analysis_runs')->nullOnDelete();
            $table->string('telegram_message_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['monitoring_status', 'expires_at']);
            $table->index(['evaluation_status', 'evaluation_due_date']);
        });

        // 3. Market Observations (Price & Source Finality tracking)
        Schema::create('market_observations', function (Blueprint $table) {
            $table->id();
            $table->string('ticker', 10)->index();
            $table->timestamp('observation_time')->index();
            $table->date('bar_date')->index();
            $table->decimal('open_price', 12, 2);
            $table->decimal('high_price', 12, 2);
            $table->decimal('low_price', 12, 2);
            $table->decimal('close_price', 12, 2);
            $table->bigInteger('volume')->default(0);
            $table->string('source')->default('Yahoo Finance');
            $table->boolean('is_eod_final')->default(false);
            $table->integer('delay_minutes')->default(15);
            $table->string('data_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['ticker', 'bar_date', 'observation_time'], 'uq_obs_ticker_date_time');
        });

        // 4. Monitoring Events (Auditable trail of events during 14-day window)
        Schema::create('monitoring_events', function (Blueprint $table) {
            $table->id();
            $table->string('prediction_id')->index();
            $table->string('event_type')->index(); // STARTED, PRICE_UPDATE, TARGET_HIT, INVALIDATED, AMBIGUOUS_HIT, EXPIRED, LATE_EVIDENCE, GAP_OFFLINE_DETECTED
            $table->timestamp('event_time')->index();
            $table->timestamp('first_observed_at');
            $table->decimal('price_observed', 12, 2)->nullable();
            $table->text('description');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        // 5. Telegram Outbox (Deduplicated queue for Telegram message delivery)
        Schema::create('telegram_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('dedup_key')->unique();
            $table->string('prediction_id')->nullable()->index();
            $table->string('chat_id')->index();
            $table->string('reply_to_message_id')->nullable();
            $table->string('message_type')->index(); // INITIAL_SIGNAL, NO_SIGNAL_DIGEST, MONITORING_UPDATE, HORIZON_RECAP, INBOUND_REPLY, SYSTEM_ALERT
            $table->text('payload_text');
            $table->string('status')->default('QUEUED')->index(); // QUEUED, SENT, FAILED, DELIVERY_UNKNOWN
            $table->timestamp('sent_at')->nullable();
            $table->string('telegram_message_id')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('retry_after')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        // 6. Telegram Conversations (Inbound queries & provenance-backed answers)
        Schema::create('telegram_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_update_id')->unique();
            $table->string('chat_id')->index();
            $table->string('user_id')->index();
            $table->string('user_name')->nullable();
            $table->text('inbound_text');
            $table->string('detected_intent')->index();
            $table->string('context_signal_id')->nullable()->index();
            $table->text('outbound_reply_text');
            $table->jsonb('provenance')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_conversations');
        Schema::dropIfExists('telegram_outbox');
        Schema::dropIfExists('monitoring_events');
        Schema::dropIfExists('market_observations');
        Schema::dropIfExists('prediction_signals');
        Schema::dropIfExists('evidence_snapshots');
    }
};
