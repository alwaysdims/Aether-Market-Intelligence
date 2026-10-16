<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HermesMarketIntelligenceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Default Data Sources
        $sources = [
            [
                'name' => 'Yahoo Finance Daily EOD',
                'type' => 'market_data',
                'data_classification' => 'end_of_day',
                'base_url' => 'https://query1.finance.yahoo.com/v8/finance/chart/',
                'status' => 'active',
                'last_success_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Index Alpha Broker Summary',
                'type' => 'broker_summary',
                'data_classification' => 'end_of_day',
                'base_url' => 'https://api.indexalpha.id/stocks/broker-summary',
                'status' => 'active',
                'last_success_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'IDX Official Announcements & News',
                'type' => 'news',
                'data_classification' => 'delayed',
                'base_url' => 'https://www.idx.co.id',
                'status' => 'active',
                'last_success_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($sources as $source) {
            DB::table('data_sources')->updateOrInsert(['name' => $source['name']], $source);
        }

        // Default Prompt Template (Version 1.0.0)
        DB::table('prompt_templates')->updateOrInsert(
            ['code' => 'MARKET_INTELLIGENCE_DAILY', 'version' => '1.0.0'],
            [
                'model_id' => 'ag/gemini-3.8-flash-high',
                'system_prompt' => 'You are Hermes Market Intelligence AI. Evaluate Indonesian stock market condition objectively without fabricating price targets or guaranteed returns.',
                'user_prompt_template' => "Analisis data pasar dan berita BEI berikut:\n\n{market_summary}\n\nBerikan kesimpulan objektif dalam JSON: market_regime_summary, impact_direction (positive/negative/mixed/unclear), impact_horizon (intraday/multi_day/long_term), impacted_tickers, risks, unknowns.",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
