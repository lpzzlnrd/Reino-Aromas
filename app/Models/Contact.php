<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use SoftDeletes;

    // Canales soportados. Los valores coinciden EXACTAMENTE con el enum de la
    // columna `channel` en la migración de contacts; cambiar uno aquí sin
    // migrar la tabla provoca un error de truncado al guardar.
    public const CHANNEL_WHATSAPP  = 'whatsapp';
    public const CHANNEL_INSTAGRAM = 'instagram';
    public const CHANNEL_FACEBOOK  = 'facebook';

    /**
     * @return list<string>
     */
    public static function channels(): array
    {
        return [
            self::CHANNEL_WHATSAPP,
            self::CHANNEL_INSTAGRAM,
            self::CHANNEL_FACEBOOK,
        ];
    }

    /**
     * División político-territorial de Venezuela: los 23 estados más el
     * Distrito Capital, con su etiqueta legible.
     *
     * Fuente de verdad única para el `state` de contactos y tickets. La columna
     * es VARCHAR y no ENUM (ver la migración), así que este mapa es lo que
     * realmente restringe los valores: lo consumen la validación de
     * ContactController y TicketController, el catálogo que sirve
     * GET /api/states y la agrupación de ReportController.
     *
     * Las Dependencias Federales quedan fuera a propósito: no tienen población
     * estable que pueda ser cliente y ensuciarían el desplegable.
     *
     * @return array<string, string>
     */
    public static function stateLabels(): array
    {
        return [
            'amazonas'         => 'Amazonas',
            'anzoategui'       => 'Anzoátegui',
            'apure'            => 'Apure',
            'aragua'           => 'Aragua',
            'barinas'          => 'Barinas',
            'bolivar'          => 'Bolívar',
            'carabobo'         => 'Carabobo',
            'cojedes'          => 'Cojedes',
            'delta_amacuro'    => 'Delta Amacuro',
            'distrito_capital' => 'Distrito Capital',
            'falcon'           => 'Falcón',
            'guarico'          => 'Guárico',
            'la_guaira'        => 'La Guaira',
            'lara'             => 'Lara',
            'merida'           => 'Mérida',
            'miranda'          => 'Miranda',
            'monagas'          => 'Monagas',
            'nueva_esparta'    => 'Nueva Esparta',
            'portuguesa'       => 'Portuguesa',
            'sucre'            => 'Sucre',
            'tachira'          => 'Táchira',
            'trujillo'         => 'Trujillo',
            'yaracuy'          => 'Yaracuy',
            'zulia'            => 'Zulia',
        ];
    }

    /**
     * Slugs válidos de estado, para las reglas de validación.
     *
     * @return list<string>
     */
    public static function states(): array
    {
        return array_keys(self::stateLabels());
    }

    /**
     * Etiqueta legible de un slug. Devuelve null si no es un estado conocido,
     * para que la vista pinte "Sin estado" en vez del slug crudo de un dato
     * viejo o corrupto.
     */
    public static function stateLabel(?string $state): ?string
    {
        return $state === null ? null : (self::stateLabels()[$state] ?? null);
    }

    protected $fillable = [
        'channel',
        'channel_id',
        'display_name',
        'profile_picture_url',
        'city',
        'state',
        'phone',
        'instagram_handle',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
    ];

    /**
     * Un contacto se identifica por el par (canal, id del canal), que es la
     * clave unica real: el mismo PSID puede repetirse entre canales distintos.
     *
     * FacebookSyncService lo llamaba desde el primer dia sin que existiera, asi
     * que el comando programado moria cada 5 minutos con "undefined method".
     */
    public function scopeIdentifiedByChannel(Builder $query, string $channel, string $channelId): Builder
    {
        return $query->where('channel', $channel)->where('channel_id', $channelId);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function activeConversation(): HasOne
    {
        return $this->hasOne(Conversation::class)->where('status', 'open')->latest();
    }
}
