<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->timestampTz('first_seen_at')->nullable()->index();
            $table->timestampTz('available_at')->nullable()->index();
            $table->string('raw_payload_path')->nullable();
            $table->string('raw_payload_hash', 64)->nullable()->index();
            $table->string('source_feed_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn([
                'first_seen_at',
                'available_at',
                'raw_payload_path',
                'raw_payload_hash',
                'source_feed_url',
            ]);
        });
    }
};
