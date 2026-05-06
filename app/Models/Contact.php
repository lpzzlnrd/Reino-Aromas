<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLogs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasActivityLogs, HasFactory, SoftDeletes;

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_INSTAGRAM = 'instagram';

    public const CHANNEL_FACEBOOK = 'facebook';

    public const CITY_CARACAS = 'caracas';

    public const CITY_VALENCIA = 'valencia';

    public const CITY_BARQUISIMETO = 'barquisimeto';

    public const CITY_MARACAY = 'maracay';

    public const CITY_MARGARITA = 'margarita';

    /**
     * @var list<string>
     */
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    // Conversaciones historicas asociadas a este lead/contacto.
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasOne<Conversation, $this>
     */
    // Conversacion abierta mas reciente para continuar la atencion del contacto.
    public function activeConversation(): HasOne
    {
        return $this->hasOne(Conversation::class)
            ->where('status', Conversation::STATUS_OPEN)
            ->latestOfMany();
    }

    /**
     * @param Builder<Contact> $query
     * @return Builder<Contact>
     */
    public function scopeFromChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * @param Builder<Contact> $query
     * @return Builder<Contact>
     */
    public function scopeFromCity(Builder $query, string $city): Builder
    {
        return $query->where('city', $city);
    }

    /**
     * @param Builder<Contact> $query
     * @return Builder<Contact>
     */
    public function scopeIdentifiedByChannel(Builder $query, string $channel, string $channelId): Builder
    {
        return $query->where('channel', $channel)->where('channel_id', $channelId);
    }
}
