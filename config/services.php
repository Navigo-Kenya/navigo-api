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

    'google' => [
        'client_id'         => env('GOOGLE_CLIENT_ID'),
        'client_id_ios'     => env('GOOGLE_CLIENT_ID_IOS'),
        'client_id_android' => env('GOOGLE_CLIENT_ID_ANDROID'),
        // Google Maps Platform key (Geocoding, Places, Weather).
        // Read via config() so it survives `php artisan config:cache`.
        'maps_key'          => env('GOOGLE_MAPS_API_KEY'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
    ],

    'africastalking' => [
        'api_key'   => env('AT_API_KEY'),
        'username'  => env('AT_USERNAME', 'sandbox'),
        'sender_id' => env('AT_SENDER_ID', 'HOPLN'),
        'sandbox'   => env('AT_SANDBOX', true),
    ],

    'expo' => [
        'push_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    'google_cloud' => [
        'project_id' => env('GCP_PROJECT_ID'),
        'key_path'   => env('GCP_KEY_PATH', 'storage/app/gcp-key.json'),
    ],

];
