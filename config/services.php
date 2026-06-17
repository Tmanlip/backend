<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'google_calendar' => [
        'calendar_id' => env('GOOGLE_CALENDAR_ID'),
        'credentials_json' => env('GOOGLE_CALENDAR_CREDENTIALS_JSON'),
        'delegated_user' => env('GOOGLE_CALENDAR_DELEGATED_USER'),
    ],

    'webpubsub' => [
        'enabled' => (bool) env('AZURE_WEBPUBSUB_ENABLED', false),
        'connection_string' => env('AZURE_WEBPUBSUB_CONNECTION_STRING'),
        'hub' => env('AZURE_WEBPUBSUB_HUB', 'aslaw-notifications'),
        'token_ttl_seconds' => (int) env('AZURE_WEBPUBSUB_TOKEN_TTL_SECONDS', 3600),
        'api_version' => env('AZURE_WEBPUBSUB_API_VERSION', '2024-01-01'),
    ],

    'microsoft' => [
        'enabled' => (bool) env('ENTRA_SSO_ENABLED', false),
        'tenant_id' => env('ENTRA_TENANT_ID', 'common'),
        'client_id' => env('ENTRA_CLIENT_ID'),
        'client_secret' => env('ENTRA_CLIENT_SECRET'),
        'prompt' => env('ENTRA_PROMPT', 'login'),
        'redirect_uri' => env('ENTRA_REDIRECT_URI', env('APP_URL', 'http://localhost:8000') . '/api/sso/entra/callback'),
        'frontend_callback_path' => env('ENTRA_FRONTEND_CALLBACK_PATH', '/sso/callback'),
    ],

];
