<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Timetable\Models\LessonHandoff;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Writes the plan/materials for one covered lesson occurrence. Callers
 * authorize (absent teacher or coordinator); this only guards that the
 * assignment is still active, so a cancelled cover is not edited.
 */
class SaveLessonHandoff
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(SubstitutionAssignment $assignment, User $author, string $plan, ?string $materials, ?string $notes): LessonHandoff
    {
        if ($assignment->status !== SubstitutionAssignment::STATUS_ASSIGNED) {
            throw ValidationException::withMessages(['plan' => 'ჩანაცვლება გაუქმებულია.']);
        }

        $handoff = LessonHandoff::query()->firstOrNew(['substitution_assignment_id' => $assignment->id]);
        $handoff->tenant_id = $assignment->tenant_id;
        $handoff->author_id = $author->id;
        $handoff->plan = $plan;
        $handoff->materials = $materials;
        $handoff->notes = $notes;
        $handoff->save();

        $this->auditLogger->record($assignment->tenant_id, 'substitution.handoff_saved', $handoff, $author->id, [
            'substitution_assignment_id' => $assignment->id,
        ]);

        return $handoff;
    }
}
