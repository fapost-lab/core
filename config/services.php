<?php

declare(strict_types=1);

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
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        // Bot API origin. Only a load test points it elsewhere (tools/loadtest/telegram-stub.php).
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    ],

    'hcaptcha' => [
        'site_key'   => env('HCAPTCHA_SITE_KEY', '10000000-ffff-ffff-ffff-000000000001'),
        'secret_key' => env('HCAPTCHA_SECRET_KEY', '0x0000000000000000000000000000000000000000'),
        'verify_url' => 'https://api.hcaptcha.com/siteverify',
    ],

];
