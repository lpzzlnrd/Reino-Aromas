<?php

namespace App\Models;

use App\Events\MessageCreated;
use App\Events\MessageStatusChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    // Sin updated_at: los mensajes son inmutables
    public const UPDATED_AT = null;

    // Todos los valores de abajo coinciden EXACTAMENTE con los enum de la
    // migración de messages. Están en inglés porque así se creó la tabla —
    // ojo que los de tickets sí están en español, no son intercambiables.
    public const DIRECTION_INBOUND  = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const TYPE_TEXT     = 'text';
    public const TYPE_IMAGE    = 'image';
    public const TYPE_AUDIO    = 'audio';
    public const TYPE_VIDEO    = 'video';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_TEMPLATE = 'template';
    public const TYPE_SYSTEM   = 'system';

    // pending = creado en el CRM pero todavía no confirmado por Meta. El Job
    // de envío lo mueve a sent (o failed) cuando la API responde.
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ      = 'read';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'conversation_id',
        'sender_user_id',
        'direction',
        'channel',
        'external_id',
        'type',
        'body',
        'media_url',
        'media_path',
        'meta_payload',
        'status',
        'failed_reason',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'meta_payload' => 'array',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
        'created_at'   => 'datetime',
    ];

    /**
     * Difusión por WebSocket de lo que le pasa a un mensaje.
     *
     * Va en el modelo y no en los servicios porque los mensajes nacen en tres
     * sitios distintos (OutboundMessageService, WhatsAppService,
     * InstagramService) y el estado lo mueven tres Jobs más. Engancharlo acá
     * cubre todos los caminos, incluidos los que se añadan después.
     *
     * `$dispatchesEvents` no sirve para este caso: dispara en `created` y
     * `updated` sin condiciones, y el cambio de estado necesita comprobar QUÉ
     * cambió antes de difundir.
     */
    protected static function booted(): void
    {
        static::created(function (Message $message): void {
            MessageCreated::dispatch($message);
        });

        static::updated(function (Message $message): void {
            // Solo si cambió el estado o su motivo de fallo. Los Jobs también
            // escriben external_id y meta_payload en el mismo save(), y eso al
            // front no le dice nada.
            if ($message->wasChanged(['status', 'failed_reason'])) {
                MessageStatusChanged::dispatch($message);
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
