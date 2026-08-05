<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    | Nota Reino Aromas: la SPA de Vue se sirve desde el MISMO origen que la API
    | (Laravel entrega app.blade.php en /app y la API vive en /api del mismo
    | dominio). Por eso incluimos currentRequestHost(): así el host real del
    | deploy siempre es stateful sin tener que enumerarlo en el .env.
    |
    | Sin esto, en producción /api/* devolvía 401 aun con sesión válida: la SPA
    | cargaba vacía (sin usuario, sin listas) y el interceptor de axios entraba
    | en bucle de redirección al login.
    */

    // El host de la request y el APP_URL se anexan SIEMPRE, incluso cuando
    // SANCTUM_STATEFUL_DOMAINS viene definido en el .env. Es seguro porque la
    // SPA es del mismo origen que la API, y evita el 401 silencioso cuando el
    // dominio del deploy no está enumerado en el .env.
    // (los helpers de Sanctum ya devuelven la coma inicial)
    'stateful' => explode(',', sprintf(
        '%s%s%s',
        env(
            'SANCTUM_STATEFUL_DOMAINS',
            'localhost,localhost:3000,localhost:5173,localhost:8000,127.0.0.1,127.0.0.1:8000,::1'
        ),
        Sanctum::currentApplicationUrlWithPort(),
        Sanctum::currentRequestHost(),
    )),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
