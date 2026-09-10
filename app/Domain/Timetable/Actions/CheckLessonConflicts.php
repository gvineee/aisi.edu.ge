<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Timetable\Models\Lesson;
use Illuminate\Database\Eloquent\Collection;

/**
 * Server-side conflict check (docs/02 §5.3): the same teacher, room, or
 * class can never be double-booked at an overlapping time on the same
 * day of the week. Time boundaries are half-open [start, end) — a lesson
 * ending exactly when another starts is NOT a conflict.
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
    ): Collection {
        return Lesson::query()
            ->where('tenant_id', $tenantId)
            ->where('day_of_week', $dayOfWeek)
            ->when($excludingLessonId, fn ($query) => $query->where('id', '!=', $excludingLessonId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->where(function ($query) use ($teacherId, $roomId, $schoolClassId) {
                $query->where('teacher_id', $teacherId)
                    ->orWhere('school_class_id', $schoolClassId);

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
    ): bool {
        return $this->find($tenantId, $dayOfWeek, $startsAt, $endsAt, $teacherId, $roomId, $schoolClassId, $excludingLessonId)
            ->isNotEmpty();
    }
}
