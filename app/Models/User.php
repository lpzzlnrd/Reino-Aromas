<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLE_SUPERADMIN    = 'superadmin';
    public const ROLE_ADMINISTRADOR = 'administrador';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar_url',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    /**
     * Los roles del enum de la migración, del más alto al más bajo.
     *
     * El orden importa: es el que usa el listado de administradores para
     * ordenar, así que superadmin va primero.
     *
     * @return list<string>
     */
    public static function roles(): array
    {
        return [self::ROLE_SUPERADMIN, self::ROLE_ADMINISTRADOR];
    }

    public function isSuperadmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_user_id');
    }

    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'causer');
    }
}
