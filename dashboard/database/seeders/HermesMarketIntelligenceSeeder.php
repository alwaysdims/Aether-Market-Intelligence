<?php

namespace Database\Seeders;

use App\Services\DataSourceRegistryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HermesMarketIntelligenceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Delegate Data Sources synchronization to DataSourceRegistryService
        app(DataSourceRegistryService::class)->syncRegistry();

        // Default Prompt Template (Version 1.0.0)
        DB::table('prompt_templates')->updateOrInsert(
            ['code' => 'MARKET_INTELLIGENCE_DAILY', 'version' => '1.0.0'],
            [
                'model_id' => 'ag/gemini-3.8-flash-high',
                'system_prompt' => 'You are Aether Market Intelligence AI. Evaluate Indonesian stock market condition objectively without fabricating price targets or guaranteed returns.',
                'user_prompt_template' => "Analisis data pasar dan berita BEI berikut:\n\n{market_summary}\n\nBerikan kesimpulan objektif dalam JSON: market_regime_summary, impact_direction (positive/negative/mixed/unclear), impact_horizon (intraday/multi_day/long_term), impacted_tickers, risks, unknowns.",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
