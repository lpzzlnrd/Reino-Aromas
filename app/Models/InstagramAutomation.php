<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un botón automático de Instagram: Ice Breaker o entrada del Persistent Menu.
 *
 * Ver la migración para el porqué de una sola tabla para los dos tipos.
 */
class InstagramAutomation extends Model
{
    /** Pregunta frecuente que aparece ANTES de que el usuario escriba. */
    public const KIND_ICE_BREAKER = 'ice_breaker';

    /** Entrada del menú siempre visible dentro del chat. */
    public const KIND_MENU_ITEM = 'menu_item';

    public const RESPONSE_TEMPLATE = 'template';
    public const RESPONSE_TEXT     = 'text';
    public const RESPONSE_HANDOFF  = 'handoff';

    /**
     * Límites que impone Meta. Se validan en el Request, pero viven acá para
     * que la UI los muestre y no haya dos sitios inventando el mismo número.
     *
     * El de Ice Breakers es un tope duro de la API: un quinto se rechaza. El
     * del menú es la recomendación de la doc ("limit your menu to 5 items for
     * best user experience"), no un error.
     */
    public const MAX_ICE_BREAKERS = 4;
    public const MAX_MENU_ITEMS    = 5;

    protected $fillable = [
        'kind',
        'title',
        'payload',
        'response_type',
        'template_id',
        'response_text',
        'url',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
            'hits'      => 'integer',
            'position'  => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /** En el orden en que Meta los va a mostrar. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /**
     * Hay cambios locales que Meta todavía no conoce.
     *
     * Se compara contra updated_at porque cualquier edición lo mueve: así la
     * UI puede avisar sin guardar un flag aparte que habría que mantener a
     * mano en cada save.
     */
    public function necesitaSincronizar(): bool
    {
        return $this->synced_at === null
            || $this->updated_at?->greaterThan($this->synced_at) === true;
    }

    /**
     * El texto con el que responder cuando alguien toca este botón.
     *
     * Devuelve null en handoff (a propósito: no se responde nada, el caso pasa
     * a un agente) y también si la plantilla se borró o se desactivó — el botón
     * sigue existiendo en Meta y hay que sobrevivir a eso sin reventar.
     */
    public function respuesta(): ?string
    {
        return match ($this->response_type) {
            self::RESPONSE_TEXT     => $this->response_text,
            self::RESPONSE_TEMPLATE => $this->template?->is_active === true
                ? $this->template->body
                : null,
            default => null,
        };
    }
}
