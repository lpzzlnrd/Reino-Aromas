<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una cuenta de Meta vinculada al CRM.
 *
 * Antes el estado de vinculación vivía solo en el .env, así que la UI no tenía
 * de dónde leerlo y mostraba "No vinculado" escrito a mano. Ahora el flujo de
 * Embedded Signup guarda aquí lo que Meta devuelve.
 *
 * El .env sigue valiendo: un canal sin fila aquí pero con sus META_* puestas
 * cuenta como configurado a mano. Esta tabla lo complementa, no lo reemplaza —
 * ver MetaAccountStatusService.
 */
class MetaAccount extends Model
{
    // Canales, en inglés igual que el enum de messages y contacts.
    public const CHANNEL_WHATSAPP  = 'whatsapp';
    public const CHANNEL_INSTAGRAM = 'instagram';
    public const CHANNEL_FACEBOOK  = 'facebook';

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

    /**
     * El token va cifrado en reposo.
     *
     * Un token de página con permisos de mensajería permite escribir a los
     * clientes en nombre del negocio. En claro, cualquiera con lectura a la base
     * podría suplantar al CRM.
     *
     * Ojo: 'encrypted' usa la APP_KEY. Rotarla deja los tokens ilegibles y hay
     * que volver a vincular las cuentas.
     */
    protected $casts = [
        'access_token'     => 'encrypted',
        'meta_payload'     => 'array',
        'token_expires_at' => 'datetime',
        'verified_at'      => 'datetime',
    ];

    /**
     * El token nunca sale en un array() ni en un json_encode().
     *
     * Es la segunda barrera: aunque el serializador de un controlador se olvide
     * de excluirlo, no puede filtrarse por accidente en una respuesta de la API.
     */
    protected $hidden = [
        'access_token',
    ];

    /** @return list<string> */
    public static function channels(): array
    {
        return [
            self::CHANNEL_WHATSAPP,
            self::CHANNEL_INSTAGRAM,
            self::CHANNEL_FACEBOOK,
        ];
    }

    /**
     * Etiquetas para la UI. El Vue las pinta tal cual.
     *
     * @return array<string, string>
     */
    public static function channelLabels(): array
    {
        return [
            self::CHANNEL_WHATSAPP  => 'WhatsApp',
            self::CHANNEL_INSTAGRAM => 'Instagram',
            self::CHANNEL_FACEBOOK  => 'Facebook',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    /**
     * ¿Está operativa?
     *
     * Un token caducado NO cuenta como conectada aunque el status diga
     * 'connected': Meta va a rechazar cada envío, así que la UI debe avisar
     * antes de que el agente escriba un mensaje que no va a salir.
     */
    public function estaOperativa(): bool
    {
        if ($this->status !== self::STATUS_CONNECTED) {
            return false;
        }

        // null = sin caducidad conocida (tokens de sistema), no "ya caducó".
        return $this->token_expires_at === null || $this->token_expires_at->isFuture();
    }

    /** El token caduca dentro de los próximos 7 días. */
    public function caducaPronto(): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->isFuture()
            && $this->token_expires_at->diffInDays(now()) <= 7;
    }
}
