<?php

namespace App\Services;

use App\Models\AuditLog;

/**
 * Registo na auditoria (audit_logs), usado pelas regras dos níveis 7 a 11.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function log(?string $actorId, string $action, string $entityType, ?string $entityId, array $metadata = []): void
    {
        AuditLog::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata,
        ]);
    }
}
