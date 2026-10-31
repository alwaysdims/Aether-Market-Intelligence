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
        Schema::create('user_watchlists', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 64)->index();
            $table->string('user_id', 64)->index();
            $table->string('ticker', 16)->index();
            $table->string('company_name', 255)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('added_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'ticker'], 'user_ticker_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_watchlists');
    }
};
