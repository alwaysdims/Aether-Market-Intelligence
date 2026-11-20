<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('universe_discovery_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('status', 24)->default('DISCOVERING')->index();
            $table->unsignedInteger('provider_total')->default(0);
            $table->unsignedInteger('total_pages')->default(0);
            $table->unsignedInteger('next_page')->default(1);
            $table->unsignedInteger('raw_items_seen')->default(0);
            $table->json('failed_pages')->nullable();
            $table->text('validation_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('universe_discovery_items', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->index();
            $table->string('ticker', 10);
            $table->unsignedInteger('provider_page');
            $table->string('company_name')->nullable();
            $table->unsignedBigInteger('listed_shares')->nullable();
            $table->string('instrument_type', 16);
            $table->json('raw_payload');
            $table->timestamps();

            $table->unique(['run_id', 'ticker'], 'uq_universe_discovery_run_ticker');
            $table->foreign('run_id')->references('run_id')->on('universe_discovery_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universe_discovery_items');
        Schema::dropIfExists('universe_discovery_runs');
    }
};
