<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'consultation_ai' => [
        'endpoint' => env('CONSULTATION_AI_ENDPOINT', env('AI_ROUTER_ENDPOINT', 'http://127.0.0.1:20128/v1')),
        'model_id' => env('CONSULTATION_MODEL_ID', 'gemini/gemini-3.6-flash'),
        'api_key' => env('CONSULTATION_AI_API_KEY', env('AI_API_KEY', '')),
        'protocol' => env('CONSULTATION_AI_PROTOCOL', 'openai_chat_completions'),
        'timeout' => (int) env('CONSULTATION_AI_TIMEOUT', env('AI_ROUTER_TIMEOUT', 25)),
        'max_retries' => (int) env('CONSULTATION_AI_MAX_RETRIES', 1),
        'configuration_source' => 'services.consultation_ai (CONSULTATION_MODEL_ID/CONSULTATION_AI_ENDPOINT/CONSULTATION_AI_API_KEY)',
    ],

    'ai_router' => [
        'endpoint' => env('AI_ROUTER_ENDPOINT', 'http://127.0.0.1:20128/v1'),
        'model_id' => env('AI_MODEL_ID', 'ag/gemini-3.8-flash-high'),
        'api_key' => env('AI_API_KEY', ''),
        'timeout' => (int) env('AI_ROUTER_TIMEOUT', 25),
        'max_tokens' => (int) env('AI_ROUTER_MAX_TOKENS', 600),
        'live_inference_enabled' => (bool) env('AI_LIVE_INFERENCE_ENABLED', false),
        'on_demand_enabled' => (bool) env('AI_LIVE_INFERENCE_ON_DEMAND_ENABLED', true),
        'eod_inference_enabled' => (bool) env('AI_LIVE_INFERENCE_EOD_ENABLED', false),
    ],

    'dashboard_ai' => [
        'provider' => env('DASHBOARD_AI_PROVIDER', 'google'),
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'base_url' => rtrim(env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'), '/'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 45),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 2500),
        'max_retries' => (int) env('GEMINI_MAX_RETRIES', 2),
        'on_demand_enabled' => (bool) env('DASHBOARD_AI_ON_DEMAND_ENABLED', false),
        'eod_inference_enabled' => (bool) env('AI_LIVE_INFERENCE_EOD_ENABLED', false),
    ],

    'dashboard_candidate' => [
        'read_only_health' => (bool) env('DASHBOARD_CANDIDATE_READ_ONLY_HEALTH', false),
    ],

    'idx_edge' => [
        'enabled' => (bool) env('IDX_EDGE_ENABLED', false),
        'base_url' => env('IDX_EDGE_BASE_URL', 'https://stock.arjum.com'),
        'api_key' => env('IDX_EDGE_API_KEY', ''),
        'timeout' => (int) env('IDX_EDGE_TIMEOUT', 15),
        'daily_budget' => (int) env('IDX_EDGE_DAILY_BUDGET_EFFECTIVE', max(6000, (int) env('IDX_EDGE_DAILY_BUDGET', 6000))),
        'reserve' => (int) env('IDX_EDGE_DAILY_RESERVE', 500),
        'eod_allocation' => (int) env('IDX_EDGE_EOD_ALLOCATION', 2500),
        'backfill_allocation' => (int) env('IDX_EDGE_BACKFILL_ALLOCATION', 3000),
        'requests_per_minute' => (int) env('IDX_EDGE_REQUESTS_PER_MINUTE', 60),
        'cache_ttl_seconds' => (int) env('IDX_EDGE_CACHE_TTL_SECONDS', 30),
        'endpoints' => [
            'history' => (bool) env('IDX_EDGE_HISTORY_ENABLED', true),
            'price' => (bool) env('IDX_EDGE_PRICE_ENABLED', false),
            'broker' => (bool) env('IDX_EDGE_BROKER_ENABLED', true),
            'fundamental' => (bool) env('IDX_EDGE_FUNDAMENTAL_ENABLED', true),
            'search' => (bool) env('IDX_EDGE_SEARCH_ENABLED', true),
            'market_cap' => (bool) env('IDX_EDGE_MARKET_CAP_ENABLED', true),
            'broker_accumulation' => (bool) env('IDX_EDGE_BROKER_ACCUMULATION_ENABLED', true),
            'insiders' => (bool) env('IDX_EDGE_INSIDERS_ENABLED', true),
            'screener' => (bool) env('IDX_EDGE_SCREENER_ENABLED', false),
            'movers' => (bool) env('IDX_EDGE_MOVERS_ENABLED', false),
            'price_ihsg' => (bool) env('IDX_EDGE_PRICE_IHSG_ENABLED', false),
            'chart' => (bool) env('IDX_EDGE_CHART_ENABLED', true),
            'chart_ihsg' => (bool) env('IDX_EDGE_CHART_IHSG_ENABLED', true),
            'ownership' => (bool) env('IDX_EDGE_OWNERSHIP_ENABLED', false),
        ],
    ],

    'screening' => [
        'broker_accumulation' => [
            'version' => env('SCREENING_BROKER_ACCUMULATION_VERSION', 'BROKER_ACCUMULATION_SCREENING_V1'),
            'min_avg_volume' => (int) env('SCREENING_MIN_AVG_VOLUME', 1000000), // Kontrak V1 resmi: >= 1M lembar
            'max_return_1m_pct' => (float) env('SCREENING_MAX_RETURN_1M_PCT', 5.0),
            'min_return_1m_pct' => (float) env('SCREENING_MIN_RETURN_1M_PCT', -30.0),
            'max_dist_from_low_pct' => (float) env('SCREENING_MAX_DIST_FROM_LOW_PCT', 15.0),
            'min_session_count' => (int) env('SCREENING_MIN_SESSION_COUNT', 10),
            'shortlist_limit' => (int) env('SCREENING_SHORTLIST_LIMIT', 5),
            'premarket_scalping_limit' => (int) env('PREMARKET_SCALPING_LIMIT', 2),
            'premarket_swing_limit' => (int) env('PREMARKET_SWING_LIMIT', 2),
            'top3_min_net_buy_idr' => (float) env('SCREENING_TOP3_MIN_NET_BUY_IDR', 1000000000.0),
            'top3_min_concentration_pct' => (float) env('SCREENING_TOP3_MIN_CONCENTRATION_PCT', 10.0),
        ],
    ],

    'hermes' => [
        'cli_command' => env('HERMES_CLI_COMMAND', 'hermes'),
        'orchestrator_enabled' => (bool) env('HERMES_ORCHESTRATOR_ENABLED', false),
        'profile' => env('HERMES_PROFILE', 'aether-prod'),
        'run_budget_seconds' => (int) env('HERMES_RUN_BUDGET_SECONDS', 60),
    ],

    'telegram' => [
        'chat_id' => env('TELEGRAM_CHAT_ID', '5923895922'),
    ],

];
