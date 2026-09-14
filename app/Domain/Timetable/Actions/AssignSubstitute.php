<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\StaffAbsence;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns one substitute teacher to cover one lesson on one calendar date.
 * The one required safety check per this module's brief: the candidate
 * substitute must have no other lesson already scheduled at that exact
 * lesson's date+time — verified against the real lessons table (plus this
 * module's own substitution_assignments) via CheckSubstituteAvailability,
 * never trusted from the client. Rejects with a clear validation error on
 * conflict instead of silently double-booking a teacher.
 */
class AssignSubstitute
{
    public function __construct(
        private readonly CheckSubstituteAvailability $checkSubstituteAvailability,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(
        int $tenantId,
        User $actor,
        Lesson $lesson,
        User $substituteTeacher,
        Carbon $date,
        ?StaffAbsence $absence,
    ): SubstitutionAssignment {
        return DB::transaction(function () use ($tenantId, $actor, $lesson, $substituteTeacher, $date, $absence) {
            if ($substituteTeacher->id === $lesson->teacher_id) {
                throw ValidationException::withMessages([
                    'substitute_teacher_id' => 'შემცვლელი ვერ იქნება იგივე მასწავლებელი, ვინც არ ესწრება.',
                ]);
            }

            $alreadyCovered = SubstitutionAssignment::query()
                ->where('tenant_id', $tenantId)
                ->where('lesson_id', $lesson->id)
                ->whereDate('date', $date->toDateString())
                ->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
                ->exists();

            if ($alreadyCovered) {
                throw ValidationException::withMessages([
                    'lesson_id' => 'ამ გაკვეთილზე ამ თარიღით უკვე დანიშნულია შემცვლელი.',
                ]);
            }

            if ($this->checkSubstituteAvailability->hasConflict($tenantId, $substituteTeacher->id, $date, $lesson)) {
                throw ValidationException::withMessages([
                    'substitute_teacher_id' => 'ამ მასწავლებელს ამ დროს უკვე აქვს სხვა გაკვეთილი ან ჩანაცვლება.',
                ]);
            }

            $assignment = new SubstitutionAssignment([
                'lesson_id' => $lesson->id,
                'absence_id' => $absence?->id,
                'absent_teacher_id' => $lesson->teacher_id,
                'substitute_teacher_id' => $substituteTeacher->id,
                'date' => $date->toDateString(),
                'status' => SubstitutionAssignment::STATUS_ASSIGNED,
                'created_by' => $actor->id,
            ]);
            $assignment->tenant_id = $tenantId;
            $assignment->save();

            if ($absence !== null && $absence->status !== StaffAbsence::STATUS_COVERED) {
                $absence->status = StaffAbsence::STATUS_COVERED;
                $absence->save();
            }

            $this->auditLogger->record($tenantId, 'substitution.assigned', $assignment, $actor->id, [
                'lesson_id' => $lesson->id,
                'absent_teacher_id' => $lesson->teacher_id,
                'substitute_teacher_id' => $substituteTeacher->id,
                'date' => $date->toDateString(),
                'absence_id' => $absence?->id,
            ]);

            return $assignment;
        });
    }
}
