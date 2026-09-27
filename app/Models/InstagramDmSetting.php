<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ComparaPalabrasClave;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración de la respuesta automática a quien escribe un DM.
 *
 * Hermano de {@see InstagramCommentSetting}: aquélla atiende a quien comenta un
 * post, ésta a quien escribe al buzón. Ver la migración para el porqué de la
 * tabla aparte. Se accede siempre por actual().
 */
class InstagramDmSetting extends Model
{
    use ComparaPalabrasClave;

    public const RESPONSE_TEMPLATE = 'template';
    public const RESPONSE_TEXT     = 'text';
    public const RESPONSE_MENU     = 'menu';

    /** Solo el primer mensaje de una conversación nueva. */
    public const TRIGGER_FIRST = 'first';

    /** Todo mensaje entrante que pase los demás cortes. */
    public const TRIGGER_ALWAYS = 'always';

    protected $fillable = [
        'is_active',
        'response_type',
        'template_id',
        'quick_reply_menu_id',
        'response_text',
        'keywords',
        'trigger_mode',
        'skip_if_assigned',
        'cooldown_minutes',
        'daily_limit',
    ];

    protected function casts(): array
    {
        return [
            'is_active'        => 'boolean',
            'skip_if_assigned' => 'boolean',
            'keywords'         => 'array',
            'cooldown_minutes' => 'integer',
            'daily_limit'      => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function quickReplyMenu(): BelongsTo
    {
        return $this->belongsTo(InstagramQuickReplyMenu::class, 'quick_reply_menu_id');
    }

    /**
     * La única fila de configuración.
     *
     * La crea si no existe, por el mismo motivo que en la de comentarios: un
     * `migrate:fresh` o una base restaurada a medias dejarían la tabla vacía y
     * el null se propagaría hasta el webhook, donde el fallo se vería como "no
     * respondió" sin ninguna pista.
     */
    public static function actual(): self
    {
        return static::query()->firstOrCreate([], [
            'is_active'        => false,
            'response_type'    => self::RESPONSE_MENU,
            'trigger_mode'     => self::TRIGGER_FIRST,
            'skip_if_assigned' => true,
            'cooldown_minutes' => 60,
            'daily_limit'      => 200,
        ]);
    }

    /**
     * El texto a enviar, o null si no hay nada que mandar.
     *
     * Null cuando la plantilla o el menú elegidos se borraron o se
     * desactivaron: la configuración sigue apuntando ahí y hay que sobrevivir
     * a eso sin enviar un DM vacío.
     */
    public function respuesta(): ?string
    {
        $texto = match ($this->response_type) {
            self::RESPONSE_TEXT     => $this->response_text,
            self::RESPONSE_TEMPLATE => $this->template?->is_active === true
                ? $this->template->body
                : null,
            self::RESPONSE_MENU     => $this->quickReplyMenu?->estaCompleto() === true
                ? $this->quickReplyMenu->body
                : null,
            default => null,
        };

        return $texto !== null && trim($texto) !== '' ? $texto : null;
    }

    /**
     * Las burbujas a enviar, o lista vacía si esta respuesta no es un menú.
     *
     * @return list<array{content_type: string, title: string, payload: string}>
     */
    public function opcionesDeMenu(): array
    {
        if ($this->response_type !== self::RESPONSE_MENU) {
            return [];
        }

        return $this->quickReplyMenu?->estaCompleto() === true
            ? $this->quickReplyMenu->opcionesParaMeta()
            : [];
    }

    /** ¿Responde a todo mensaje, o solo al primero de una conversación? */
    public function respondeSiempre(): bool
    {
        return $this->trigger_mode === self::TRIGGER_ALWAYS;
    }
}
