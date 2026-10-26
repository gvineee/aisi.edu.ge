<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Academics\Models\StaffMember;
use App\Domain\Timetable\Models\Lesson;
use Illuminate\Database\Eloquent\Collection;

/**
 * Server-side conflict check (docs/02 §5.3): the same teacher, room, or
 * class can never be double-booked at an overlapping time on the same
 * day of the week. Time boundaries are half-open [start, end) — a lesson
 * ending exactly when another starts is NOT a conflict.
 *
 * Two different teaching groups of the same class may meet at the same time
 * (a split English lesson); a whole-class lesson still clashes with any
 * lesson of that class. Lessons are only compared within one timetable
 * version — versions have their own validity windows — and legacy lessons
 * without a version are compared with each other as before.
 */
class CheckLessonConflicts
{
    /**
     * @return Collection<int, Lesson>
     */
    public function find(
        int $tenantId,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        int $teacherId,
        ?int $roomId,
        int $schoolClassId,
        ?int $excludingLessonId = null,
        ?int $studentGroupId = null,
        ?int $timetableVersionId = null,
    ): Collection {
        return Lesson::query()
            ->where('tenant_id', $tenantId)
            ->where('day_of_week', $dayOfWeek)
            ->when($excludingLessonId, fn ($query) => $query->where('id', '!=', $excludingLessonId))
            ->when(
                $timetableVersionId === null,
                fn ($query) => $query->whereNull('timetable_version_id'),
                fn ($query) => $query->where('timetable_version_id', $timetableVersionId),
            )
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->where(function ($query) use ($tenantId, $teacherId, $roomId, $schoolClassId, $studentGroupId) {
                // The teacher's lessons imported before they had a login
                // carry only their staff-directory entry.
                $query->where('teacher_id', $teacherId)
                    ->orWhereIn('staff_member_id', StaffMember::query()->where('tenant_id', $tenantId)->where('user_id', $teacherId)->select('id'))
                    ->orWhere(function ($query) use ($schoolClassId, $studentGroupId) {
                        $query->where('school_class_id', $schoolClassId);

                        if ($studentGroupId !== null) {
                            $query->where(fn ($query) => $query
                                ->whereNull('student_group_id')
                                ->orWhere('student_group_id', $studentGroupId));
                        }
                    });

                if ($roomId !== null) {
                    $query->orWhere('room_id', $roomId);
                }
            })
            ->get();
    }

    public function hasConflict(
        int $tenantId,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        int $teacherId,
        ?int $roomId,
        int $schoolClassId,
        ?int $excludingLessonId = null,
        ?int $studentGroupId = null,
        ?int $timetableVersionId = null,
    ): bool {
        return $this->find($tenantId, $dayOfWeek, $startsAt, $endsAt, $teacherId, $roomId, $schoolClassId, $excludingLessonId, $studentGroupId, $timetableVersionId)
            ->isNotEmpty();
    }
}
