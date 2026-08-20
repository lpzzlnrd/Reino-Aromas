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
        'app_id'               => env('META_APP_ID'),
        'app_secret'           => env('META_APP_SECRET'),
        'access_token'         => env('META_ACCESS_TOKEN'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'graph_api_version'    => env('META_GRAPH_API_VERSION', 'v21.0'),
        'instagram_account_id'      => env('META_INSTAGRAM_ACCOUNT_ID'),
        'whatsapp_phone_number_id'  => env('META_WHATSAPP_PHONE_NUMBER_ID'),

        /*
        |----------------------------------------------------------------------
        | Embedded Signup (el popup de vinculación)
        |
        | Cada canal necesita su propia "configuración" de Facebook Login for
        | Business, creada en el dashboard de Meta. El id resultante lo pide el
        | JS SDK como `config_id` al abrir el popup — sin él no arranca.
        |
        | Se pasan al frontend por GET /api/meta/accounts y NO por variables
        | VITE_*: esas quedan congeladas en el bundle al compilar, así que
        | cambiar de app exigiría un rebuild.
        |
        | OJO: Embedded Signup v2 se deprecia el 15 de octubre de 2026. Estas
        | configuraciones deben crearse como v4.
        |----------------------------------------------------------------------
        */
        'signup' => [
            'whatsapp_config_id'  => env('META_SIGNUP_WHATSAPP_CONFIG_ID'),
            'instagram_config_id' => env('META_SIGNUP_INSTAGRAM_CONFIG_ID'),
            'facebook_config_id'  => env('META_SIGNUP_FACEBOOK_CONFIG_ID'),
        ],

        /*
        |----------------------------------------------------------------------
        | Facebook Messenger
        |
        | page_id distingue quién habla en cada evento del webhook: Meta manda
        | el mismo formato para los mensajes del cliente y para los que envía
        | la Página, y sin este id ProcessMetaWebhookJob no sabe cuál de los
        | dos participantes es el contacto.
        |
        | page_access_token es el token de la Página (no el de la app) que usa
        | FacebookMessagingService para responder.
        |----------------------------------------------------------------------
        */
        'facebook' => [
            'page_id'           => env('META_FACEBOOK_PAGE_ID'),
            'page_access_token' => env('META_FACEBOOK_PAGE_ACCESS_TOKEN'),

            // Solo para el flujo OAuth de vinculación de la Página
            // (GET /api/meta/facebook/auth-url). Debe coincidir EXACTAMENTE
            // con la URI registrada en el dashboard de Meta.
            'redirect_uri'      => env('FACEBOOK_REDIRECT_URI'),
        ],

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

            /*
            |------------------------------------------------------------------
            | Flow de bienvenida
            |
            | Se envía solo cuando escribe un contacto nuevo, reemplazando el
            | saludo y la pregunta de ciudad que hoy manda un agente a mano.
            |
            | Si FLOWS_WELCOME_ID está vacío el disparo queda DESACTIVADO y
            | todo sigue funcionando como hoy: el ticket se crea y un agente
            | atiende. Eso permite desplegar este código antes de tener el Flow
            | publicado en Meta.
            |------------------------------------------------------------------
            */
            'welcome_flow_id'     => env('FLOWS_WELCOME_ID'),
            'welcome_cta'         => env('FLOWS_WELCOME_CTA', 'Ver cursos'),
            'welcome_first_screen' => env('FLOWS_WELCOME_FIRST_SCREEN', 'BIENVENIDA'),
            'welcome_body'        => env(
                'FLOWS_WELCOME_BODY',
                '¡Hola! 🕯️ Bienvenido a Reino Aromas. Toca el botón para ver nuestros cursos y precios en tu ciudad.'
            ),
        ],
    ],

];
