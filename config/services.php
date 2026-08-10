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

    'meta' => [
        'app_secret'           => env('META_APP_SECRET'),
        'access_token'         => env('META_ACCESS_TOKEN'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'graph_api_version'    => env('META_GRAPH_API_VERSION', 'v21.0'),
        'instagram_account_id'      => env('META_INSTAGRAM_ACCOUNT_ID'),
        'whatsapp_phone_number_id'  => env('META_WHATSAPP_PHONE_NUMBER_ID'),

        /*
        |----------------------------------------------------------------------
        | WhatsApp Flows
        |
        | Par RSA 2048 que cifra el canal de datos del endpoint. Se genera con
        | `php artisan flows:generate-keys` y la pública se sube con
        | `php artisan flows:upload-key`.
        |
        | La privada va SOLO en el .env del servidor, nunca en git. En el .env
        | debe ir entre comillas dobles con los saltos escapados como \n.
        |----------------------------------------------------------------------
        */
        'flows' => [
            'private_key' => env('FLOWS_PRIVATE_KEY'),
            'passphrase'  => env('FLOWS_PASSPHRASE', ''),
        ],
    ],

];
