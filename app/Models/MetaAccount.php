<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuenta de negocio vinculada por canal de Meta (una fila por canal).
 *
 * Esta tabla es UNA de las dos fuentes de verdad sobre el estado de
 * vinculación — la otra es el .env, que el dev puede haber configurado a
 * mano en el servidor. MetaAccountStatusService une ambas; no leer esta
 * tabla sola para decidir si un canal está conectado.
 */
class MetaAccount extends Model
{
    public const CHANNEL_FACEBOOK  = 'facebook';
    public const CHANNEL_INSTAGRAM = 'instagram';
    public const CHANNEL_WHATSAPP  = 'whatsapp';

    public const STATUS_CONNECTED    = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_ERROR        = 'error';

    protected $fillable = [
        'channel',
        'display_name',
        'external_id',
        'waba_id',
        'access_token',
        'token_expires_at',
        'status',
        'error_message',
        'connected_by_user_id',
        'verified_at',
        'meta_payload',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected $casts = [
        'access_token'     => 'encrypted',
        'token_expires_at' => 'datetime',
        'verified_at'      => 'datetime',
        'meta_payload'     => 'array',
    ];

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && ! $this->isTokenExpired();
    }
}
