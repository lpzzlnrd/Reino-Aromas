<?php

declare(strict_types=1);

namespace App\Services\Meta;

use RuntimeException;

/**
 * Puerta de entrada a las credenciales de Meta.
 *
 * El problema que resuelve: `config('services.meta.access_token')` devuelve null
 * cuando la variable no está en el .env, y el código seguía adelante igual. Un
 * envío sin PHONE_NUMBER_ID construía la URL
 * `graph.facebook.com/v21.0//messages` — con la barra doble — y Meta contestaba
 * un 404 que en el log parecía un problema de la API. Con tres reintentos, el
 * mismo error confuso tres veces y ni una pista de que faltaba el .env.
 *
 * Aquí las credenciales se piden por nombre y **falla temprano y claro** si no
 * están. Un `RuntimeException` que dice qué variable falta se diagnostica en
 * segundos; un 404 de Meta, no.
 *
 * Por qué un servicio y no un helper: los tres canales (WhatsApp, Instagram,
 * Facebook) necesitan lo mismo, y el mensaje de error tiene que ser idéntico en
 * todos. Además así se puede sustituir en los tests.
 */
class MetaCredentials
{
    /**
     * Nombre de la variable de entorno detrás de cada clave de config.
     *
     * Se mantiene explícito en vez de derivarlo con str_replace: lo que el dev
     * necesita leer en el error es el nombre exacto que va en el .env, y
     * `instagram_account_id` no se convierte solo en
     * `META_INSTAGRAM_ACCOUNT_ID` de forma fiable.
     *
     * @var array<string, string>
     */
    private const ENV_POR_CLAVE = [
        'app_id'                   => 'META_APP_ID',
        'app_secret'               => 'META_APP_SECRET',
        'access_token'             => 'META_ACCESS_TOKEN',
        'webhook_verify_token'     => 'META_WEBHOOK_VERIFY_TOKEN',
        'instagram_account_id'     => 'META_INSTAGRAM_ACCOUNT_ID',
        'instagram_app_secret'     => 'META_INSTAGRAM_APP_SECRET',
        'instagram_access_token'   => 'META_INSTAGRAM_ACCESS_TOKEN',
        'whatsapp_phone_number_id' => 'META_WHATSAPP_PHONE_NUMBER_ID',
        'facebook.page_id'           => 'META_FACEBOOK_PAGE_ID',
        'facebook.page_access_token' => 'META_FACEBOOK_PAGE_ACCESS_TOKEN',
        'signup.facebook_config_id'  => 'META_SIGNUP_FACEBOOK_CONFIG_ID',
        'signup.instagram_config_id' => 'META_SIGNUP_INSTAGRAM_CONFIG_ID',
        'signup.whatsapp_config_id'  => 'META_SIGNUP_WHATSAPP_CONFIG_ID',
    ];

    /**
     * Devuelve una credencial o revienta si falta.
     *
     * @param  string $clave Ruta dentro de config('services.meta'), p.ej.
     *                       'access_token' o 'facebook.page_id'.
     * @throws RuntimeException Si la credencial está vacía o ausente.
     */
    public function obtener(string $clave): string
    {
        $valor = config("services.meta.{$clave}");

        // Se comprueba el string vacío además de null: una variable declarada
        // pero sin valor en el .env (META_ACCESS_TOKEN=) llega como '' y es
        // igual de inservible que si no estuviera.
        if (! is_string($valor) || trim($valor) === '') {
            $env = self::ENV_POR_CLAVE[$clave] ?? strtoupper("META_{$clave}");

            throw new RuntimeException(
                "Falta la credencial de Meta {$env}. Añádela al .env y ejecuta "
                . 'php artisan config:clear (o config:cache en producción).'
            );
        }

        return $valor;
    }

    /**
     * ¿Está configurada esta credencial? No lanza.
     *
     * Para los caminos que deben degradar en vez de fallar — un webhook que
     * responde 403 en lugar de un 500, por ejemplo.
     */
    public function tiene(string $clave): bool
    {
        $valor = config("services.meta.{$clave}");

        return is_string($valor) && trim($valor) !== '';
    }

    /**
     * Versión de la Graph API.
     *
     * No pasa por obtener() porque tiene un default razonable en config y su
     * ausencia no es un error: v21.0 sigue vigente hasta enero de 2027.
     */
    public function versionApi(): string
    {
        $version = config('services.meta.graph_api_version');

        return is_string($version) && $version !== '' ? $version : 'v21.0';
    }

    /**
     * Base de la Graph API, ya con la versión.
     *
     * Centralizada para que subir de versión sea cambiar el .env y no cazar
     * URLs hardcodeadas por los servicios.
     */
    public function urlGraph(string $ruta = ''): string
    {
        $base = 'https://graph.facebook.com/' . $this->versionApi();

        return $ruta === '' ? $base : $base . '/' . ltrim($ruta, '/');
    }

    /**
     * URL para el producto "Instagram API con login de Instagram".
     *
     * Ese producto NO se sirve desde graph.facebook.com: la doc de Meta dice
     * "All endpoints can be accessed via the graph.instagram.com host". Usar el
     * host de Facebook devuelve
     *
     *   (#3) Application does not have the capability to make this API call
     *
     * que suena a permiso faltante y es en realidad el host equivocado: la app
     * principal no tiene la capacidad de mensajeria de Instagram, la app de
     * Instagram si.
     *
     * Se mantiene aparte de urlGraph() porque el resto de la integracion
     * (WhatsApp, Messenger, la Graph API general) sigue en graph.facebook.com
     * y cambiar el host global romperia todo lo que hoy funciona.
     */
    public function urlGraphInstagram(string $ruta = ''): string
    {
        $base = 'https://graph.instagram.com/' . $this->versionApi();

        return $ruta === '' ? $base : $base . '/' . ltrim($ruta, '/');
    }

    /**
     * Credenciales que faltan, de las que hacen falta para operar.
     *
     * Lo usa el comando de diagnóstico para decir de un golpe qué queda por
     * configurar, en vez de descubrirlo un error a la vez.
     *
     * @param  list<string> $claves
     * @return list<string> Nombres de variables de entorno ausentes.
     */
    public function faltantes(array $claves): array
    {
        $faltan = [];

        foreach ($claves as $clave) {
            if (! $this->tiene($clave)) {
                $faltan[] = self::ENV_POR_CLAVE[$clave] ?? $clave;
            }
        }

        return $faltan;
    }
}
