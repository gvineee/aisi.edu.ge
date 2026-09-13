<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Learning\Models\Assignment;

class UnpublishAssignment
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Assignment $assignment, int $actorId): void
    {
        if (! $assignment->isPublished()) {
            return;
        }

        $assignment->status = Assignment::STATUS_DRAFT;
        $assignment->save();

        $this->auditLogger->record($assignment->tenant_id, 'assignment.unpublished', $assignment, $actorId);
    }
}
