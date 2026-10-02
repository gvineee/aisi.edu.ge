<?php

namespace App\Domain\Platform;

use App\Domain\Platform\Models\PlatformAuditEvent;
use App\Models\User;

/**
 * The single write path for platform operator audit events. Like
 * AuditLogger, never pass secrets or full sensitive payloads as meta.
 */
class PlatformAudit
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function record(?User $operator, string $action, ?int $tenantId, ?array $meta = null): void
    {
        PlatformAuditEvent::query()->create([
            'operator_id' => $operator?->id,
            'tenant_id' => $tenantId,
            'action' => $action,
            'meta' => $meta,
        ]);
    }
}
