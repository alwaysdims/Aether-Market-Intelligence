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
        if (! Schema::hasTable('document_chunks')) {
            Schema::create('document_chunks', function (Blueprint $table) {
                $table->id();
                $table->string('document_id', 128)->index();
                $table->string('document_version', 32)->default('v1');
                $table->string('ticker', 10)->nullable()->index();
                $table->string('source', 50)->default('IDX_FILING'); // IDX_FILING, INVESTOR_RELATIONS, NEWS_LKBN, ANNUAL_REPORT
                $table->text('source_url')->nullable();
                $table->string('fiscal_period', 20)->nullable(); // e.g. FY2024, FY2025, Q1-2026
                $table->timestamp('published_at')->nullable()->index();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('fetched_at')->nullable();
                $table->timestamp('available_at')->nullable()->index(); // Strict Point-in-Time cutoff boundary
                $table->string('content_hash', 64)->index();
                $table->string('section_page', 50)->nullable(); // e.g. Section 4, Page 12
                $table->string('chunk_type', 30)->default('PARAGRAPH'); // HEADING, PARAGRAPH, TABLE_FINANCIAL, DISCLOSURE
                $table->text('chunk_text');
                $table->string('extraction_status', 30)->default('PARSED_TEXT'); // PARSED_TEXT, UNRELIABLE_TABLE, VERIFIED
                $table->jsonb('metadata')->nullable(); // currency, scale, unit, table_columns, etc.
                $table->timestamps();

                $table->index(['ticker', 'available_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
