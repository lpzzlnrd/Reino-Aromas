<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\Template;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lógica de negocio de las plantillas de respuesta.
 *
 * El punto central es render(): las plantillas se guardan con marcadores
 * {{nombre}}, {{curso}}, {{ciudad}} y aquí se sustituyen por los datos reales
 * del contacto antes de que el agente envíe el mensaje.
 */
class TemplateService
{
    /**
     * Variables que el editor ofrece y que render() sabe resolver.
     *
     * Es la fuente de verdad: la vista lee esta lista desde la API en vez de
     * tener su propia copia hardcodeada, así agregar una variable nueva es
     * tocar un solo sitio.
     *
     * @var array<string, string>
     */
    public const VARIABLES = [
        'nombre' => 'Nombre del contacto',
        'ciudad' => 'Ciudad del contacto',
        'curso'  => 'Curso de interés registrado en el ticket',
        'agente' => 'Nombre del agente que responde',
    ];

    public function __construct(private readonly ActivityLogService $activityLog) {}

    /**
     * Sustituye los marcadores {{variable}} por sus valores reales.
     *
     * Tolera espacios dentro de las llaves ({{ nombre }}) porque al escribir a
     * mano se cuelan, y un marcador sin sustituir llegaría al cliente.
     *
     * Los marcadores que no tienen valor se reemplazan por $fallback en vez de
     * quedar crudos: es preferible que el cliente lea "¡Hola!" a que lea
     * "¡Hola {{nombre}}!".
     *
     * @param array<string, string|null> $valores
     */
    public function render(string $body, array $valores, string $fallback = ''): string
    {
        $resultado = preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            function (array $match) use ($valores, $fallback): string {
                $clave = strtolower($match[1]);
                $valor = $valores[$clave] ?? null;

                return ($valor === null || $valor === '') ? $fallback : $valor;
            },
            $body
        ) ?? $body;

        // Un marcador vacío deja basura alrededor: "¡Hola {{nombre}}!" sin
        // nombre daría "¡Hola !" y "en {{ciudad}}." daría "en .".
        // Se limpia el espacio sobrante antes de puntuación y los espacios
        // dobles, respetando los saltos de línea del texto original.
        $resultado = preg_replace('/[ \t]+([,.!?;:])/u', '$1', $resultado) ?? $resultado;
        $resultado = preg_replace('/[ \t]{2,}/u', ' ', $resultado) ?? $resultado;

        return $resultado;
    }

    /**
     * Arma los valores de las variables a partir de los modelos del CRM.
     *
     * @return array<string, string|null>
     */
    public function valoresPara(?Contact $contact, ?Ticket $ticket = null, ?string $agente = null): array
    {
        return [
            // Solo el primer nombre: "¡Hola María!" suena natural,
            // "¡Hola María Fernanda González!" suena a carta del banco.
            'nombre' => $this->primerNombre($contact?->display_name),
            'ciudad' => $this->nombreCiudad($ticket?->city ?? $contact?->city),
            'curso'  => $ticket?->course_interest,
            'agente' => $agente,
        ];
    }

    /**
     * Devuelve los marcadores presentes en un texto.
     *
     * La vista lo usa para avisar cuando una plantilla trae una variable que el
     * sistema no sabe resolver (un typo como {{nombr}}).
     *
     * @return list<string>
     */
    public function variablesUsadas(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $body, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * Marcadores que no corresponden a ninguna variable conocida.
     *
     * @return list<string>
     */
    public function variablesDesconocidas(string $body): array
    {
        return array_values(array_diff(
            $this->variablesUsadas($body),
            array_keys(self::VARIABLES)
        ));
    }

    /**
     * Plantillas disponibles para una conversación concreta, ya renderizadas.
     *
     * Es lo que consume el selector del chat: el agente ve el texto final, no
     * el que tiene marcadores.
     *
     * @return Collection<int, Template>
     */
    public function disponiblesPara(?Contact $contact, ?Ticket $ticket = null, ?string $agente = null): Collection
    {
        $ciudad = $ticket?->city ?? $contact?->city;
        $canal  = $contact?->channel;

        $templates = Template::query()
            ->active()
            ->forCity($ciudad)
            // Igual que con la ciudad: NULL significa "sirve para cualquier canal".
            ->where(function ($query) use ($canal): void {
                $query->whereNull('channel');

                if ($canal !== null) {
                    $query->orWhere('channel', $canal);
                }
            })
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->get();

        $valores = $this->valoresPara($contact, $ticket, $agente);

        // rendered_body es un atributo temporal para la respuesta JSON;
        // no existe como columna.
        return $templates->each(function (Template $template) use ($valores): void {
            $template->setAttribute('rendered_body', $this->render($template->body, $valores));
        });
    }

    /**
     * Registra que una plantilla se usó.
     *
     * El contador alimenta el orden del selector: lo más usado primero.
     */
    public function registrarUso(Template $template, ?int $userId = null): void
    {
        $template->increment('usage_count');
        $template->forceFill(['last_used_at' => now()])->save();

        $this->activityLog->log(
            causerType: $userId !== null ? \App\Models\User::class : null,
            causerId: $userId,
            targetType: Template::class,
            targetId: $template->id,
            action: 'template.used',
            metadata: ['name' => $template->name],
        );
    }

    private function primerNombre(?string $nombreCompleto): ?string
    {
        if ($nombreCompleto === null || trim($nombreCompleto) === '') {
            return null;
        }

        return explode(' ', trim($nombreCompleto))[0];
    }

    /**
     * Las ciudades se guardan en minúscula como enum; en el mensaje deben
     * aparecer capitalizadas.
     */
    private function nombreCiudad(?string $ciudad): ?string
    {
        return $ciudad === null ? null : ucfirst($ciudad);
    }
}
