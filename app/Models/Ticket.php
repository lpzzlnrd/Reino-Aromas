<?php

namespace App\Models;

use App\Events\TicketUpdated;
use App\Models\Concerns\HasActivityLogs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasActivityLogs;
    use SoftDeletes;

    // Estados y prioridades EN ESPAÑOL: así están los enum en la migración de
    // tickets y así los escribe TicketService. No confundir con los enum de
    // messages, que sí están en inglés.
    public const STATUS_NUEVO          = 'nuevo';
    public const STATUS_INTERESADO     = 'interesado';
    public const STATUS_ALTA_PRIORIDAD = 'alta_prioridad';
    public const STATUS_EN_SEGUIMIENTO = 'en_seguimiento';
    public const STATUS_RESERVADO      = 'reservado';
    public const STATUS_CERRADO        = 'cerrado';

    public const PRIORITY_BAJA     = 'baja';
    public const PRIORITY_MEDIA    = 'media';
    public const PRIORITY_ALTA     = 'alta';
    public const PRIORITY_MUY_ALTA = 'muy_alta';

    /**
     * Etiquetas que espera el enum CaseStatus.ts del frontend.
     *
     * El Vue compara por el valor literal ('Urgente', 'En seguimiento'…), así
     * que este mapa es el contrato con la UI. Ojo con 'alta_prioridad' →
     * 'Urgente': es el único par donde la etiqueta no es la traducción directa
     * del estado, y por eso vive aquí en vez de derivarse con str_replace.
     *
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_NUEVO          => 'Nuevo',
            self::STATUS_INTERESADO     => 'Interesado',
            self::STATUS_ALTA_PRIORIDAD => 'Urgente',
            self::STATUS_EN_SEGUIMIENTO => 'En seguimiento',
            self::STATUS_RESERVADO      => 'Reservado',
            self::STATUS_CERRADO        => 'Cerrado',
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return array_keys(self::statusLabels());
    }

    /**
     * @return list<string>
     */
    public static function priorities(): array
    {
        return [
            self::PRIORITY_BAJA,
            self::PRIORITY_MEDIA,
            self::PRIORITY_ALTA,
            self::PRIORITY_MUY_ALTA,
        ];
    }

    /**
     * Etiqueta de este ticket para el frontend. 'Nuevo' como respaldo si la
     * columna trae un valor inesperado.
     */
    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? 'Nuevo';
    }

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

    protected $casts = [
        'reserved_at' => 'datetime',
        'closed_at'   => 'datetime',
    ];

    /**
     * Difusión por WebSocket de los cambios que mueven el tablero.
     *
     * Solo status, priority y assigned_user_id: guardar una nota o corregir la
     * ciudad no debe hacer saltar ninguna tarjeta del Kanban.
     *
     * Se difunden los valores ANTERIORES junto al ticket para que el front sepa
     * de qué columna sacarlo — con solo el estado nuevo tendría que buscar la
     * tarjeta por todo el tablero.
     */
    protected static function booted(): void
    {
        static::updated(function (Ticket $ticket): void {
            $relevantes = ['status', 'priority', 'assigned_user_id'];

            if (! $ticket->wasChanged($relevantes)) {
                return;
            }

            // getOriginal() devuelve el valor de antes del save().
            $previos = [];
            foreach ($relevantes as $campo) {
                if ($ticket->wasChanged($campo)) {
                    $previos[$campo] = $ticket->getOriginal($campo);
                }
            }

            TicketUpdated::dispatch($ticket, $previos);
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function contact(): HasOneThrough
    {
        return $this->hasOneThrough(Contact::class, Conversation::class, 'id', 'id', 'conversation_id', 'contact_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'ticket_tag');
    }
}
