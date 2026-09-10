<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\LessonException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a school_class's recurring weekly lessons into the actual list for
 * one calendar date, applying that date's exceptions (cancelled /
 * substitution / room_change). `$date` is interpreted in the school's own
 * timezone — "today" for a school is Asia/Tbilisi's today, not UTC's.
 */
class ResolveDailyLessons
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forSchoolClass(int $tenantId, int $schoolClassId, Carbon $date): Collection
    {
        return $this->resolve(
            Lesson::query()
                ->where('tenant_id', $tenantId)
                ->where('school_class_id', $schoolClassId),
            $tenantId,
            $date,
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forTeacher(int $tenantId, int $teacherId, Carbon $date): Collection
    {
        return $this->resolve(
            Lesson::query()
                ->where('tenant_id', $tenantId)
                ->where('teacher_id', $teacherId),
            $tenantId,
            $date,
        );
    }

    /**
     * @param  Builder<Lesson>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function resolve($query, int $tenantId, Carbon $date): Collection
    {
        $dayOfWeek = $date->dayOfWeekIso;

        $lessons = $query
            ->where('status', Lesson::STATUS_PUBLISHED)
            ->where('day_of_week', $dayOfWeek)
            ->with(['subject', 'teacher', 'room'])
            ->orderBy('starts_at')
            ->get();

        if ($lessons->isEmpty()) {
            return collect();
        }

        $exceptions = LessonException::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->whereDate('occurs_on', $date->toDateString())
            ->with(['substituteTeacher', 'substituteRoom'])
            ->get()
            ->keyBy('lesson_id');

        return $lessons->map(fn (Lesson $lesson) => $this->formatEntry($lesson, $exceptions->get($lesson->id)));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(Lesson $lesson, ?LessonException $exception): array
    {
        $teacherName = $exception !== null && $exception->substituteTeacher !== null
            ? $exception->substituteTeacher->name
            : $lesson->teacher->name;

        $roomName = $exception !== null && $exception->substituteRoom !== null
            ? $exception->substituteRoom->name
            : $lesson->room?->name;

        return [
            'lesson' => $lesson,
            'cancelled' => $exception !== null && $exception->type === LessonException::TYPE_CANCELLED,
            'teacherName' => $teacherName,
            'roomName' => $roomName,
        ];
    }
}
