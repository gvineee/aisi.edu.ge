<?php

namespace App\Domain\Governance;

use App\Domain\Governance\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Single place every "important change" write path calls to satisfy
 * CLAUDE.md invariant #7 — role/guardian link, grades, attendance,
 * finance, exports, support access. Never pass a password, token, or full
 * sensitive payload as $meta; only small, reviewed field diffs.
 *
 * Takes the tenant id explicitly rather than reading it from
 * CurrentTenant, so this works the same from an HTTP request, a queue
 * job, or a console command — none of which are guaranteed to have a
 * resolved request-scoped tenant.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function record(int $tenantId, string $action, Model $subject, ?int $actorId, ?array $meta = null): AuditEvent
    {
        $event = new AuditEvent;
        $event->tenant_id = $tenantId;
        $event->actor_id = $actorId;
        $event->action = $action;
        $event->subject_type = $subject::class;
        $event->subject_id = $subject->getKey();
        $event->meta = $meta;
        $event->save();

        return $event;
    }
}
