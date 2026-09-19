<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración del DM automático a quien comenta un post de Instagram.
 *
 * Es una tabla de UNA fila; ver la migración para el porqué de no reutilizar
 * `instagram_automations`. Se accede siempre por actual().
 */
class InstagramCommentSetting extends Model
{
    public const RESPONSE_TEMPLATE = 'template';
    public const RESPONSE_TEXT     = 'text';

    /**
     * El DM es un menú de opciones en vez de un mensaje suelto.
     *
     * Es el modo que convierte el comentario en una conversación guiada: la
     * persona toca una burbuja y recibe la respuesta de esa opción, sin que
     * intervenga un agente.
     */
    public const RESPONSE_MENU = 'menu';

    protected $fillable = [
        'is_active',
        'public_reply_active',
        'public_reply_text',
        'response_type',
        'template_id',
        'quick_reply_menu_id',
        'response_text',
        'keywords',
        'daily_limit',
    ];

    protected function casts(): array
    {
        return [
            'is_active'           => 'boolean',
            'public_reply_active' => 'boolean',
            'keywords'            => 'array',
            'daily_limit'         => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * La única fila de configuración.
     *
     * La crea si no existe en vez de devolver null: la migración ya la inserta,
     * pero un `migrate:fresh` en un test o una base restaurada a medias dejarían
     * la tabla vacía, y un null acá se propagaría hasta el webhook — donde el
     * fallo aparecería como "no se envió el DM" sin ninguna pista del porqué.
     */
    public static function actual(): self
    {
        return static::query()->firstOrCreate([], [
            'is_active'     => false,
            'response_type' => self::RESPONSE_TEXT,
            'daily_limit'   => 100,
        ]);
    }

    /**
     * El texto a enviar, o null si no hay nada que mandar.
     *
     * Devuelve null cuando la plantilla elegida se borró o se desactivó: la
     * configuración sigue apuntando a ella y hay que sobrevivir a eso sin
     * enviar un DM vacío.
     */
    public function respuesta(): ?string
    {
        $texto = match ($this->response_type) {
            self::RESPONSE_TEXT     => $this->response_text,
            self::RESPONSE_TEMPLATE => $this->template?->is_active === true
                ? $this->template->body
                : null,
            // El cuerpo del menú es el texto que acompaña a las burbujas. Se
            // devuelve por acá para que el servicio de comentarios no tenga que
            // preguntar de qué modo es antes de saber si hay algo que mandar.
            self::RESPONSE_MENU     => $this->quickReplyMenu?->estaCompleto() === true
                ? $this->quickReplyMenu->body
                : null,
            default => null,
        };

        return $texto !== null && trim($texto) !== '' ? $texto : null;
    }

    /** El menú a enviar si response_type = 'menu'. */
    public function quickReplyMenu(): BelongsTo
    {
        return $this->belongsTo(InstagramQuickReplyMenu::class, 'quick_reply_menu_id');
    }

    /**
     * Las burbujas a enviar, o lista vacía si este DM no es un menú.
     *
     * Vacío también cuando el menú se borró, se desactivó o se quedó sin
     * opciones activas: el servicio lo trata como "no hay nada que mandar" y
     * registra el motivo, en vez de enviar un texto sin las opciones que lo
     * hacían útil.
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

    /**
     * El aviso público a publicar debajo del comentario, o null.
     *
     * Devuelve null si está apagado o si no hay texto: publicar un comentario
     * vacío en nombre del negocio sería peor que no publicar nada.
     */
    public function avisoPublico(): ?string
    {
        if (! $this->public_reply_active) {
            return null;
        }

        $texto = $this->public_reply_text;

        return $texto !== null && trim($texto) !== '' ? trim($texto) : null;
    }

    /**
     * ¿El texto del comentario dispara el DM?
     *
     * Sin palabras clave configuradas responde a todos. La comparación es
     * insensible a mayúsculas y a acentos porque la gente comenta "precio",
     * "PRECIO" y "Precío" indistintamente, y un filtro que falla por un acento
     * se lee como que la automatización está rota.
     */
    public function coincide(?string $comentario): bool
    {
        $claves = $this->keywords ?? [];

        if ($claves === []) {
            return true;
        }

        if ($comentario === null || trim($comentario) === '') {
            return false;
        }

        $normalizado = $this->normalizar($comentario);

        foreach ($claves as $clave) {
            if (! is_string($clave) || trim($clave) === '') {
                continue;
            }

            if (str_contains($normalizado, $this->normalizar($clave))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minúsculas y sin acentos, para comparar.
     *
     * Se usa una tabla explícita en vez de iconv//TRANSLIT: en Windows y según
     * la locale, iconv devuelve "?" o falla, y esto corre igual en el Laragon
     * del dev que en el VPS.
     */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');

        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
    }
}
