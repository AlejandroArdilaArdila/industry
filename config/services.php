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

    'dolibarr' => [
        'instance_key' => env('DOLIBARR_INSTANCE_KEY'),
    ],

    'wss' => [
        'url' => env('WSS_URL', 'http://127.0.0.1:3036'),
    ],


    // Sistema anterior (Dolibarr): los documentos que aún no tienen pantalla en este panel se abren allí.
    'dolibarr' => [
        'url' => rtrim(env('DOLIBARR_URL', 'https://solu5.waplicaciones.co'), '/'),
    ],

];
