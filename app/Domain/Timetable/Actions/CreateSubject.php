<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Timetable\Models\Subject;
use App\Models\User;

/**
 * Creates a `subjects` row. Before this action (and TimetableController)
 * existed there was no route/controller anywhere that could create a
 * Subject — only TenantSeeder/ProductionSeeder wrote one — which was the
 * confirmed production root blocker for the Teacher Substitution module:
 * LessonController::store already existed and worked, but with zero
 * subjects its only Inertia form had nothing to put in the subject
 * dropdown, so no lesson (and therefore no absence/coverage worklist) could
 * ever be created through a real HTTP endpoint on production.
 */
class CreateSubject
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(int $tenantId, User $actor, string $name): Subject
    {
        $subject = new Subject(['name' => $name]);
        $subject->tenant_id = $tenantId;
        $subject->save();

        $this->auditLogger->record($tenantId, 'subject.created', $subject, $actor->id, [
            'name' => $subject->name,
        ]);

        return $subject;
    }
}
