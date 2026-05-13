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

   'theowlet' => [
        'api_url' => env('THEOWLET_API_URL', 'https://the-owlet.com/api/v2'),
        'api_key' => env('THEOWLET_API_KEY'),
    ],

    'ogaviral' => [
        'api_url' => env('OGAVIRAL_API_URL', 'https://ogaviral.com/api/v2'),
        'api_key' => env('OGAVIRAL_API_KEY'),
    ],

    'brevo' => [
        'key' => env('BREVO_KEY'),
    ],

    'korapay' => [
        'secret_key' => env('KORAPAY_SECRET_KEY'),
        'public_key' => env('KORAPAY_PUBLIC_KEY'),
        'base_url' => env('KORAPAY_BASE_URL', 'https://api.korapay.com/merchant'),
        'currency' => env('KORAPAY_CURRENCY', 'NGN'),
    ],

    'tiktok' => [
        'pixel_code' => env('TIKTOK_PIXEL_CODE'),
        'access_token' => env('TIKTOK_ACCESS_TOKEN'),
        'enabled' => env('TIKTOK_ENABLED', false),
    ],

];
