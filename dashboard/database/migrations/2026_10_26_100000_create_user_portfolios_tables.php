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
        Schema::create('user_portfolios', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->unique();
            $table->string('chat_id')->nullable();
            $table->decimal('cash_balance', 18, 2)->default(0.00);
            $table->decimal('target_trading_pct', 5, 2)->default(50.00);
            $table->decimal('target_investment_pct', 5, 2)->default(50.00);
            $table->string('risk_profile', 32)->default('MODERATE');
            $table->timestamps();
        });

        Schema::create('user_portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained('user_portfolios')->onDelete('cascade');
            $table->string('user_id')->index();
            $table->string('ticker', 16)->index();
            $table->integer('lots')->default(0);
            $table->bigInteger('shares')->default(0);
            $table->decimal('avg_buy_price', 14, 2)->default(0.00);
            $table->string('allocation_type', 32)->default('TRADING'); // TRADING or INVESTMENT
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ticker', 'allocation_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_portfolio_items');
        Schema::dropIfExists('user_portfolios');
    }
};
