<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'causer_id',
        'causer_type',
        'target_id',
        'target_type',
        'action',
        'metadata',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    // Modelo que ejecuto la accion auditada, normalmente un User.
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    // Modelo afectado por la accion auditada.
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param Builder<ActivityLog> $query
     * @return Builder<ActivityLog>
     */
    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * @param Builder<ActivityLog> $query
     * @return Builder<ActivityLog>
     */
    public function scopeWithAuditRelations(Builder $query): Builder
    {
        return $query->with(['causer', 'target']);
    }
}
