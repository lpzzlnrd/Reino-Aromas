<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de respuesta automática a un DM entrante.
 *
 * Sostiene el cooldown y el tope diario, y deja auditar por qué un mensaje no
 * recibió respuesta. Ver la migración para por qué acá no hay clave única de
 * idempotencia como sí la tiene {@see InstagramCommentReply}.
 */
class InstagramDmReply extends Model
{
    public const STATUS_SENT    = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'sender_igsid',
        'sender_username',
        'incoming_text',
        'status',
        'skip_reason',
        'message_id',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Cuándo se le envió la última respuesta automática a este contacto.
     *
     * Solo cuenta los ENVIADOS: si un intento se omitió por cooldown, tomarlo
     * como referencia extendería el silencio indefinidamente cada vez que la
     * persona escribe de nuevo.
     */
    public static function ultimoEnvioA(string $igsid): ?self
    {
        return static::query()
            ->where('sender_igsid', $igsid)
            ->where('status', self::STATUS_SENT)
            ->latest()
            ->first();
    }

    /** Cuántas respuestas automáticas salieron hoy. */
    public static function enviadosHoy(): int
    {
        return static::query()
            ->where('status', self::STATUS_SENT)
            ->whereDate('created_at', today())
            ->count();
    }
}
