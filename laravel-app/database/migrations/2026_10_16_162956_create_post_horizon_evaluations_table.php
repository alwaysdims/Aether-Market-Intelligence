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
        Schema::create('post_horizon_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_analysis_id')->constrained('market_analyses')->cascadeOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained('analysis_runs')->nullOnDelete();
            $table->string('ticker');
            $table->date('decision_date');
            $table->timestamp('decision_timestamp');
            $table->decimal('reference_price', 12, 2);
            $table->date('reference_price_date');
            $table->integer('horizon_trading_days')->default(5);
            $table->date('target_eval_date');
            $table->string('predicted_direction')->default('unclear'); // positive, negative, mixed, unclear
            $table->decimal('predicted_confidence', 4, 2)->nullable();
            $table->string('model_name')->default('ag/gemini-3.8-flash-high');
            $table->string('prompt_version')->default('1.0.0');
            $table->decimal('actual_price', 12, 2)->nullable();
            $table->date('actual_price_date')->nullable();
            $table->decimal('actual_return_pct', 8, 4)->nullable();
            $table->string('actual_direction')->nullable(); // positive, negative, flat
            $table->string('status')->default('pending'); // pending, evaluated, cannot_evaluate
            $table->string('outcome')->nullable(); // correct, incorrect, neutral, missing_data, suspended
            $table->text('rubric_notes')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            // Idempotent uniqueness: one evaluation per analysis + ticker + horizon
            $table->unique(['market_analysis_id', 'ticker', 'horizon_trading_days'], 'uq_analysis_ticker_horizon');
            $table->index(['status', 'target_eval_date']);
            $table->index(['ticker', 'decision_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_horizon_evaluations');
    }
};
