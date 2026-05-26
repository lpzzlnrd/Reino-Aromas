<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasActivityLogs
{
    /**
     * @return MorphMany<ActivityLog, $this>
     */
    // Eventos de auditoria donde este modelo fue el objetivo afectado.
    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'target')->latest('created_at');
    }
}
