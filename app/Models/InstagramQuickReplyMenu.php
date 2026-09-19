<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Un menú de opciones de Instagram: el mensaje y las burbujas que lo acompañan.
 *
 * Es lo más parecido que tiene Instagram a un WhatsApp Flow. Al tocar una
 * burbuja, Meta dispara el webhook `messaging_postbacks` que ya atiende
 * InstagramService::handlePostback(), que resuelve el payload contra
 * `instagram_automations` y responde. Este modelo solo agrupa.
 */
class InstagramQuickReplyMenu extends Model
{
    /**
     * Tope de burbujas por mensaje que impone Instagram.
     *
     * Es un límite duro de la API: la número 14 hace que Meta rechace el envío
     * entero, no que recorte. Se valida en el Request y se muestra en la UI
     * desde acá para que no haya dos sitios inventando el mismo número.
     */
    public const MAX_OPCIONES = 13;

    /**
     * Tope de caracteres del título de una burbuja.
     *
     * Meta lo recorta con puntos suspensivos en el cliente móvil en vez de
     * fallar, que es peor: el admin no se entera de que su opción se ve a
     * medias.
     */
    public const MAX_TITULO_OPCION = 20;

    protected $fillable = [
        'name',
        'body',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sends'     => 'integer',
        ];
    }

    /**
     * Las opciones de este menú, en el orden en que Meta las va a mostrar.
     */
    public function opciones(): HasMany
    {
        return $this->hasMany(InstagramAutomation::class, 'menu_group_id')
            ->where('kind', InstagramAutomation::KIND_QUICK_REPLY)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Las opciones listas para enviar a Meta.
     *
     * Solo las activas: una opción desactivada sigue existiendo en el CRM —con
     * su payload y sus estadísticas— pero no debe aparecer en el mensaje.
     *
     * El formato es el que pide la Messaging API: `content_type` siempre
     * 'text', el título que ve el usuario y el payload que vuelve en el
     * webhook.
     *
     * @return list<array{content_type: string, title: string, payload: string}>
     */
    public function opcionesParaMeta(): array
    {
        return $this->opciones
            ->where('is_active', true)
            ->take(self::MAX_OPCIONES)
            ->map(fn (InstagramAutomation $opcion): array => [
                'content_type' => 'text',
                'title'        => $opcion->title,
                'payload'      => $opcion->payload,
            ])
            ->values()
            ->all();
    }

    /**
     * Si este menú puede enviarse tal cual está.
     *
     * Un menú sin opciones activas se enviaría como un mensaje de texto suelto:
     * la persona vería el cuerpo y ninguna burbuja, sin forma de responder más
     * que escribiendo. Es el equivalente al "botón sin respuesta" que ya avisa
     * la vista de automatizaciones.
     */
    public function estaCompleto(): bool
    {
        return $this->is_active && $this->opciones->where('is_active', true)->isNotEmpty();
    }

    /**
     * Las opciones activas que no responden nada.
     *
     * Apuntan a una plantilla borrada o desactivada: la burbuja aparece, la
     * persona la toca y no recibe nada. La UI lo avisa en rojo porque el caso
     * queda esperando a un agente que no sabe que existe.
     *
     * @return Collection<int, InstagramAutomation>
     */
    public function opcionesRotas(): Collection
    {
        return $this->opciones
            ->where('is_active', true)
            ->filter(fn (InstagramAutomation $opcion): bool => $opcion->estaRota());
    }
}
