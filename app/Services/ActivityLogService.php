<?php

namespace App\Services;

use App\Models\ActivityLog;

class ActivityLogService
{
    /**
     * Único punto de entrada para registrar cualquier acción auditable del sistema.
     */
    public function log(
        ?string $causerType,
        ?int    $causerId,
        ?string $targetType,
        ?int    $targetId,
        string  $action,
        array   $metadata = [],
    ): ActivityLog {
        return ActivityLog::create([
            'causer_type' => $causerType,
            'causer_id'   => $causerId,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'action'      => $action,
            'metadata'    => $metadata ?: null,
        ]);
    }
}
