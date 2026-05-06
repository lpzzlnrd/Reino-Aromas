<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_ADMINISTRADOR = 'administrador';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar_url',
        'is_active',
        'last_login_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    // Tickets asignados manualmente a este usuario/agente.
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    // Tickets creados por este usuario desde el panel interno.
    public function createdTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    // Mensajes salientes enviados por este usuario.
    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_user_id');
    }

    /**
     * @return MorphMany<ActivityLog, $this>
     */
    // Eventos de auditoria donde este usuario fue quien ejecuto la accion.
    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'causer')->latest('created_at');
    }

    /**
     * @param Builder<User> $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder<User> $query
     * @return Builder<User>
     */
    public function scopeAdministrators(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_ADMINISTRADOR);
    }

    protected function isSuperadmin(): Attribute
    {
        return Attribute::get(fn (): bool => $this->role === self::ROLE_SUPERADMIN);
    }
}
