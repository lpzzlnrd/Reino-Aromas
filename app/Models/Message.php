<?php

namespace App\Models;

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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
