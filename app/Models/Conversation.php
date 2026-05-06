<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLogs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasActivityLogs, HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'status',
        'last_message_at',
        'within_24h_window',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'within_24h_window' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    // Contacto externo al que pertenece este hilo de mensajes.
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    // Mensajes del hilo ordenados cronologicamente para renderizar el chat.
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest('created_at');
    }

    /**
     * @return HasOne<Message, $this>
     */
    // Ultimo mensaje del hilo para previsualizaciones de bandeja.
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('created_at');
    }

    /**
     * @return HasOne<Ticket, $this>
     */
    // Ticket activo/de seguimiento asociado a esta conversacion.
    public function ticket(): HasOne
    {
        return $this->hasOne(Ticket::class);
    }

    /**
     * @param Builder<Conversation> $query
     * @return Builder<Conversation>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * @param Builder<Conversation> $query
     * @return Builder<Conversation>
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CLOSED);
    }

    /**
     * @param Builder<Conversation> $query
     * @return Builder<Conversation>
     */
    public function scopeWithInboxRelations(Builder $query): Builder
    {
        return $query->with(['contact', 'ticket.assignedUser', 'latestMessage']);
    }

    /**
     * @param Builder<Conversation> $query
     * @return Builder<Conversation>
     */
    public function scopeOrderByLastMessage(Builder $query): Builder
    {
        return $query->orderByDesc('last_message_at');
    }
}
