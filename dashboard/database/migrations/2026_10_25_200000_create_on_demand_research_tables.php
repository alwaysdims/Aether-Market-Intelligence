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
        // 1. Additive columns for market_observations (Section E - Provenance, Delays, Finality)
        Schema::table('market_observations', function (Blueprint $table) {
            if (! Schema::hasColumn('market_observations', 'company_name')) {
                $table->string('company_name', 255)->nullable()->after('ticker');
            }
            if (! Schema::hasColumn('market_observations', 'listing_status')) {
                $table->string('listing_status', 20)->default('ACTIVE')->after('company_name');
            }
            if (! Schema::hasColumn('market_observations', 'source_url')) {
                $table->text('source_url')->nullable()->after('source');
            }
            if (! Schema::hasColumn('market_observations', 'raw_payload_path')) {
                $table->text('raw_payload_path')->nullable()->after('source_url');
            }
            if (! Schema::hasColumn('market_observations', 'raw_payload_hash')) {
                $table->string('raw_payload_hash', 64)->nullable()->after('raw_payload_path');
            }
            if (! Schema::hasColumn('market_observations', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('raw_payload_hash');
            }
            if (! Schema::hasColumn('market_observations', 'first_seen_at')) {
                $table->timestamp('first_seen_at')->nullable()->after('published_at');
            }
            if (! Schema::hasColumn('market_observations', 'fetched_at')) {
                $table->timestamp('fetched_at')->nullable()->after('first_seen_at');
            }
            if (! Schema::hasColumn('market_observations', 'available_at')) {
                $table->timestamp('available_at')->nullable()->after('fetched_at');
            }
            if (! Schema::hasColumn('market_observations', 'timezone')) {
                $table->string('timezone', 30)->default('Asia/Jakarta')->after('available_at');
            }
            if (! Schema::hasColumn('market_observations', 'currency')) {
                $table->string('currency', 10)->default('IDR')->after('timezone');
            }
            if (! Schema::hasColumn('market_observations', 'interval')) {
                $table->string('interval', 10)->default('1d')->after('currency');
            }
            if (! Schema::hasColumn('market_observations', 'data_classification')) {
                $table->string('data_classification', 50)->default('PUBLIC_MARKET_EOD')->after('interval');
            }
            if (! Schema::hasColumn('market_observations', 'finality_basis')) {
                $table->string('finality_basis', 100)->nullable()->after('is_eod_final');
            }
            if (! Schema::hasColumn('market_observations', 'adjustment_mode')) {
                $table->string('adjustment_mode', 50)->default('UNADJUSTED')->after('finality_basis');
            }
            if (! Schema::hasColumn('market_observations', 'validation_status')) {
                $table->string('validation_status', 50)->default('VALID')->after('adjustment_mode');
            }
            if (! Schema::hasColumn('market_observations', 'conflict_reason')) {
                $table->text('conflict_reason')->nullable()->after('validation_status');
            }
            if (! Schema::hasColumn('market_observations', 'research_request_id')) {
                $table->string('research_request_id', 64)->nullable()->index()->after('data_hash');
            }
        });

        // 2. On-Demand Research Requests table (Section B, G, H)
        Schema::create('research_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->string('chat_id', 64)->nullable()->index();
            $table->string('user_id', 64)->nullable();
            $table->string('inbound_message_id', 64)->nullable();
            $table->string('ack_message_id', 64)->nullable();
            $table->string('ticker', 10)->index();
            $table->string('company_name', 255)->nullable();
            $table->string('exchange', 20)->default('IDX');
            $table->string('analysis_type', 50)->default('current_condition'); // current_condition, news, historical, prediction
            $table->string('data_type_requested', 50)->default('eod'); // eod, delayed, realtime
            $table->string('horizon', 50)->default('multi_day');
            $table->timestamp('as_of_time')->index();
            $table->string('status', 50)->default('pending')->index(); // pending, collecting, validating, analyzing, completed, failed, partial
            $table->string('freshness_classification', 50)->default('MISSING')->index(); // AVAILABLE_FRESH, AVAILABLE_BUT_INSUFFICIENT_FOR_REQUEST, STALE, MISSING, CONFLICTED, SOURCE_UNAVAILABLE
            $table->foreignId('evidence_snapshot_id')->nullable()->constrained('evidence_snapshots')->nullOnDelete();
            $table->foreignId('market_analysis_id')->nullable()->constrained('market_analyses')->nullOnDelete();
            $table->foreignId('outbox_id')->nullable()->constrained('telegram_outbox')->nullOnDelete();
            $table->string('error_reason', 100)->nullable(); // source_unavailable, provider_timeout, validation_failure, ai_unavailable, data_insufficient
            $table->jsonb('research_metadata')->nullable(); // plan, steps, quota_usage, latencies, validation_details
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('research_requests');

        Schema::table('market_observations', function (Blueprint $table) {
            $columns = [
                'company_name',
                'listing_status',
                'source_url',
                'raw_payload_path',
                'raw_payload_hash',
                'published_at',
                'first_seen_at',
                'fetched_at',
                'available_at',
                'timezone',
                'currency',
                'interval',
                'data_classification',
                'finality_basis',
                'adjustment_mode',
                'validation_status',
                'conflict_reason',
                'research_request_id',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('market_observations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
