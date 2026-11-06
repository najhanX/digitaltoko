<?php

return [
    'forwarder' => [
        'shared_broker' => env('HANSDEV_SHARED_QRIS_BROKER', false),
        'apk_path' => env('HANSDEV_FORWARDER_APK_PATH') ?: storage_path('app/releases/HansDev-Forwarder.apk'),
        'tools' => [
            'windows' => env('HANSDEV_FORWARDER_TOOLS_WINDOWS_PATH') ?: storage_path('app/releases/HansDev-Listener-windows.zip'),
            'macos' => env('HANSDEV_FORWARDER_TOOLS_MACOS_PATH') ?: storage_path('app/releases/HansDev-Listener-macos.zip'),
            'linux' => env('HANSDEV_FORWARDER_TOOLS_LINUX_PATH') ?: storage_path('app/releases/HansDev-Listener-linux.zip'),
        ],
    ],

    'game_identity' => [
        'url' => env('GAME_IDENTITY_URL', 'https://api.isan.eu.org/nickname'),
    ],

    'bot_manager' => [
        'url' => env('BOT_MANAGER_URL', 'http://127.0.0.1:3099'),
        'token' => env('BOT_MANAGER_TOKEN'),
    ],

    'hanshop' => [
        'url' => env('HANSHOP_API_URL'),
        'token' => env('HANSHOP_IDENTITY_TOKEN'),
    ],

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

];
