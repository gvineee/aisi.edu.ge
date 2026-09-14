<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use Illuminate\Support\Carbon;

/**
 * Server-side conflict check for substitute assignment, mirroring
 * CheckLessonConflicts' half-open [start, end) overlap rule. A candidate
 * substitute is unavailable for $lesson on $date when either:
 *  - they have their own regularly-scheduled published lesson that day of
 *    week overlapping the same time, or
 *  - they are already assigned (status=assigned) to cover a different
 *    lesson on that same date with an overlapping time.
 * Never trust a client-supplied "is available" claim — AssignSubstitute
 * calls this itself before writing.
 */
class CheckSubstituteAvailability
{
    public function hasConflict(int $tenantId, int $substituteTeacherId, Carbon $date, Lesson $lesson): bool
    {
        $ownScheduleConflict = Lesson::query()
            ->where('tenant_id', $tenantId)
            ->where('teacher_id', $substituteTeacherId)
            ->where('id', '!=', $lesson->id)
            ->where('status', Lesson::STATUS_PUBLISHED)
            ->where('day_of_week', $date->dayOfWeekIso)
            ->where('starts_at', '<', $lesson->ends_at)
            ->where('ends_at', '>', $lesson->starts_at)
            ->exists();

        if ($ownScheduleConflict) {
            return true;
        }

        return SubstitutionAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('substitute_teacher_id', $substituteTeacherId)
            ->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
            ->where('lesson_id', '!=', $lesson->id)
            ->whereDate('date', $date->toDateString())
            ->whereHas('lesson', function ($query) use ($lesson) {
                $query->where('starts_at', '<', $lesson->ends_at)
                    ->where('ends_at', '>', $lesson->starts_at);
            })
            ->exists();
    }
}
