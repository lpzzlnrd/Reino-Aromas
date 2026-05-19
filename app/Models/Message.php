<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    // Sin updated_at: los mensajes son inmutables
    public const UPDATED_AT = null;

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
