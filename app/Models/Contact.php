<?php

namespace App\Models;

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

    protected $fillable = [
        'channel',
        'channel_id',
        'display_name',
        'profile_picture_url',
        'city',
        'phone',
        'instagram_handle',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function activeConversation(): HasOne
    {
        return $this->hasOne(Conversation::class)->where('status', 'open')->latest();
    }
}
