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

    'ai_router' => [
        'endpoint' => env('AI_ROUTER_ENDPOINT', 'http://127.0.0.1:20128/v1'),
        'model_id' => env('AI_MODEL_ID', 'ag/gemini-3.8-flash-high'),
        'api_key' => env('AI_API_KEY', ''),
        'timeout' => (int) env('AI_ROUTER_TIMEOUT', 30),
        'max_tokens' => (int) env('AI_ROUTER_MAX_TOKENS', 2500),
        'live_inference_enabled' => (bool) env('AI_LIVE_INFERENCE_ENABLED', false),
    ],

];
