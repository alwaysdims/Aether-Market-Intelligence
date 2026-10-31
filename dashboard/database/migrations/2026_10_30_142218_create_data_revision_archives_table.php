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
        Schema::create('data_revision_archives', function (Blueprint $table) {
            $table->id();
            $table->string('record_type', 50); // e.g., 'company_fundamental', 'broker_summary', 'market_observation'
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('ticker', 10)->index();
            $table->string('period_or_date', 30)->index();
            $table->unsignedInteger('revision_number')->default(1);
            $table->jsonb('previous_payload')->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->jsonb('new_payload')->nullable();
            $table->string('new_hash', 64)->nullable();
            $table->jsonb('changes')->nullable();
            $table->string('source', 50);
            $table->string('reason', 255)->default('DATA_REVISION_DETECTED');
            $table->timestamps();

            $table->index(['record_type', 'record_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_revision_archives');
    }
};
