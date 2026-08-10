<?php

declare(strict_types=1);

namespace App\Services\Flows;

use Illuminate\Support\Facades\Log;

/**
 * Decide qué responder a cada request del canal de datos de un Flow.
 *
 * Meta manda tres tipos de request al endpoint, todos por el mismo POST:
 *
 *   - action=ping          → health check periódico
 *   - data.error presente  → el cliente reporta un error
 *   - action=INIT          → el usuario abrió el Flow
 *   - action=data_exchange → el usuario envió una pantalla
 *   - action=BACK          → el usuario volvió atrás
 *
 * Las respuestas de INIT/data_exchange devuelven el nombre de la siguiente
 * pantalla y los datos con que renderizarla.
 *
 * PENDIENTE: las pantallas concretas del Flow de inscripción se definen
 * cuando exista el número de WhatsApp y se arme el Flow en el Builder de
 * WhatsApp Manager. Hoy este router responde correctamente a health checks y
 * errores, y deja el data_exchange preparado con un ejemplo de inscripción a
 * cursos que hay que ajustar a los nombres reales de las pantallas.
 */
class FlowRouterService
{
    /**
     * Pantalla especial de Meta que cierra el Flow.
     * No se define en el Flow JSON: es un identificador reservado.
     */
    private const SCREEN_SUCCESS = 'SUCCESS';

    /**
     * Punto de entrada. Recibe el cuerpo ya descifrado.
     *
     * @param  array<string, mixed> $body
     * @return array<string, mixed>  Respuesta a cifrar y devolver.
     */
    public function handle(array $body): array
    {
        $action  = (string) ($body['action'] ?? '');
        $version = (string) ($body['version'] ?? '3.0');

        // El health check va primero: es el más frecuente y el más barato.
        // Si no respondemos esto, Meta marca el Flow como no saludable y deja
        // de entregarlo a los usuarios.
        if ($action === 'ping') {
            return [
                'version' => $version,
                'data'    => ['status' => 'active'],
            ];
        }

        // Errores reportados por el cliente de WhatsApp. Solo hay que
        // acusar recibo, pero conviene loguearlos: aquí aparecen problemas
        // como 'public-key-missing' que exigen resubir la clave.
        if (isset($body['data']['error'])) {
            Log::warning('[Flows] Error reportado por el cliente', [
                'error'       => $body['data']['error'] ?? null,
                'error_message' => $body['data']['error_message'] ?? null,
                'screen'      => $body['screen'] ?? null,
                'flow_token'  => $body['flow_token'] ?? null,
            ]);

            return [
                'version' => $version,
                'data'    => ['acknowledged' => true],
            ];
        }

        return match ($action) {
            'INIT'          => $this->pantallaInicial($version),
            'BACK'          => $this->pantallaInicial($version),
            'data_exchange' => $this->procesarPantalla($body, $version),
            default         => $this->respuestaError($version, "Acción no soportada: {$action}"),
        };
    }

    /**
     * Primera pantalla al abrir el Flow.
     *
     * @return array<string, mixed>
     */
    private function pantallaInicial(string $version): array
    {
        return [
            'version' => $version,
            'screen'  => 'SELECCION_CIUDAD',
            'data'    => [
                'ciudades' => $this->opcionesCiudad(),
            ],
        ];
    }

    /**
     * El usuario envió una pantalla: decidir la siguiente.
     *
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function procesarPantalla(array $body, string $version): array
    {
        $screen = (string) ($body['screen'] ?? '');
        $data   = is_array($body['data'] ?? null) ? $body['data'] : [];

        return match ($screen) {
            'SELECCION_CIUDAD' => [
                'version' => $version,
                'screen'  => 'SELECCION_CURSO',
                'data'    => [
                    'cursos' => $this->opcionesCurso((string) ($data['ciudad'] ?? '')),
                    // Se arrastra la ciudad para tenerla disponible al cerrar
                    // el Flow sin volver a preguntarla.
                    'ciudad' => $data['ciudad'] ?? null,
                ],
            ],

            'SELECCION_CURSO' => [
                'version' => $version,
                'screen'  => 'DATOS_CONTACTO',
                'data'    => [
                    'ciudad' => $data['ciudad'] ?? null,
                    'curso'  => $data['curso'] ?? null,
                ],
            ],

            // Última pantalla: cerrar el Flow.
            //
            // El contenido de 'params' es lo que llega después por webhook
            // como nfm_reply, y es donde se crea el Contact + Ticket.
            // Esa parte vive en el webhook, no aquí, porque Meta considera
            // el Flow terminado en cuanto respondemos SUCCESS.
            'DATOS_CONTACTO' => [
                'version' => $version,
                'screen'  => self::SCREEN_SUCCESS,
                'data'    => [
                    'extension_message_response' => [
                        'params' => [
                            'flow_token' => $body['flow_token'] ?? null,
                            'ciudad'     => $data['ciudad'] ?? null,
                            'curso'      => $data['curso'] ?? null,
                            'nombre'     => $data['nombre'] ?? null,
                            'telefono'   => $data['telefono'] ?? null,
                        ],
                    ],
                ],
            ],

            default => $this->respuestaError($version, "Pantalla desconocida: {$screen}"),
        };
    }

    /**
     * Ciudades donde Reino Aromas dicta cursos.
     *
     * Formato que exige Flow JSON para un Dropdown: id + title.
     *
     * @return list<array{id: string, title: string}>
     */
    private function opcionesCiudad(): array
    {
        return [
            ['id' => 'caracas',      'title' => 'Caracas'],
            ['id' => 'valencia',     'title' => 'Valencia'],
            ['id' => 'barquisimeto', 'title' => 'Barquisimeto'],
            ['id' => 'maracay',      'title' => 'Maracay'],
            ['id' => 'margarita',    'title' => 'Margarita'],
        ];
    }

    /**
     * Cursos disponibles en una ciudad.
     *
     * PENDIENTE: hoy devuelve un catálogo fijo. Cuando exista una tabla de
     * cursos con fechas y cupos, esto debe consultarla — es justamente la
     * razón de tener un endpoint en vez de un Flow estático.
     *
     * Los precios reales por ciudad están en los seeders de plantillas.
     *
     * @return list<array{id: string, title: string}>
     */
    private function opcionesCurso(string $ciudad): array
    {
        $precios = [
            'caracas'      => 130,
            'valencia'     => 110,
            'barquisimeto' => 110,
            'maracay'      => 110,
            'margarita'    => 250,
        ];

        $precio = $precios[$ciudad] ?? 130;

        return [
            ['id' => 'velas',     'title' => "Velas Artesanales — \${$precio}"],
            ['id' => 'jabones',   'title' => "Jabones Artesanales — \${$precio}"],
            ['id' => 'difusores', 'title' => "Difusores — \${$precio}"],
            ['id' => 'sales',     'title' => "Sales Aromáticas — \${$precio}"],
        ];
    }

    /**
     * Respuesta ante una pantalla o acción que no reconocemos.
     *
     * Se cierra el Flow en vez de dejarlo colgado: para el usuario es mejor
     * que se cierre y le escriba un agente, a que la pantalla quede girando.
     *
     * @return array<string, mixed>
     */
    private function respuestaError(string $version, string $motivo): array
    {
        Log::error('[Flows] Request no manejada', ['motivo' => $motivo]);

        return [
            'version' => $version,
            'screen'  => self::SCREEN_SUCCESS,
            'data'    => [
                'extension_message_response' => [
                    'params' => ['error' => $motivo],
                ],
            ],
        ];
    }
}
