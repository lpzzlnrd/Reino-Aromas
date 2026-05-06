<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLogs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasActivityLogs, HasFactory, SoftDeletes;

    public const STATUS_NUEVO = 'nuevo';

    public const STATUS_INTERESADO = 'interesado';

    public const STATUS_ALTA_PRIORIDAD = 'alta_prioridad';

    public const STATUS_EN_SEGUIMIENTO = 'en_seguimiento';

    public const STATUS_RESERVADO = 'reservado';

    public const STATUS_CERRADO = 'cerrado';

    public const PRIORITY_BAJA = 'baja';

    public const PRIORITY_MEDIA = 'media';

    public const PRIORITY_ALTA = 'alta';

    public const PRIORITY_MUY_ALTA = 'muy_alta';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'assigned_user_id',
        'created_by_user_id',
        'status',
        'priority',
        'city',
        'course_interest',
        'notes',
        'reserved_at',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'closed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    // Conversacion que origina y contiene el hilo de este ticket.
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    // Usuario/agente responsable de atender este ticket.
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    // Usuario que creo manualmente el ticket, si aplica.
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    // Etiquetas libres asociadas al ticket mediante la tabla pivot ticket_tag.
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return HasOneThrough<Contact, Conversation, $this>
     */
    // Contacto alcanzado a traves de la conversacion del ticket.
    public function contact(): HasOneThrough
    {
        return $this->hasOneThrough(
            Contact::class,
            Conversation::class,
            'id',
            'id',
            'conversation_id',
            'contact_id'
        );
    }

    /**
     * @param Builder<Ticket> $query
     * @return Builder<Ticket>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_NUEVO,
            self::STATUS_INTERESADO,
            self::STATUS_ALTA_PRIORIDAD,
            self::STATUS_EN_SEGUIMIENTO,
        ]);
    }

    /**
     * @param Builder<Ticket> $query
     * @return Builder<Ticket>
     */
    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_user_id', $userId);
    }

    /**
     * @param Builder<Ticket> $query
     * @return Builder<Ticket>
     */
    public function scopeWithInboxRelations(Builder $query): Builder
    {
        return $query->with(['conversation.contact', 'assignedUser', 'tags']);
    }

    /**
     * @param Builder<Ticket> $query
     * @return Builder<Ticket>
     */
    public function scopeWithKanbanRelations(Builder $query): Builder
    {
        return $query->with(['conversation.contact', 'assignedUser', 'tags']);
    }

    /**
     * @param Builder<Ticket> $query
     * @return Builder<Ticket>
     */
    public function scopeOrderByLastMessage(Builder $query): Builder
    {
        return $query->orderByDesc(
            Conversation::query()
                ->select('last_message_at')
                ->whereColumn('conversations.id', 'tickets.conversation_id')
                ->limit(1)
        );
    }

    /**
     * @param Builder<Ticket> $query
     * @return Builder<Ticket>
     */
    public function scopeOrderByPriority(Builder $query): Builder
    {
        return $query->orderByRaw("FIELD(priority, 'muy_alta', 'alta', 'media', 'baja')");
    }
}
