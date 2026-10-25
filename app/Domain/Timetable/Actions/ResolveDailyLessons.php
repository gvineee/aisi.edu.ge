<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\LessonException;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Domain\Timetable\Models\TimetableVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns recurring weekly lessons into the actual list for one calendar
 * date — the single source for the dashboards' "today" and the personal
 * day/week timetable. `$date` is a day in the school's own timezone
 * (Asia/Tbilisi); lesson times are wall-clock and never converted.
 *
 * For a date it applies, in order:
 * - only published lessons;
 * - the effective timetable version: among the year's non-draft versions
 *   whose validity covers the date, the one that started last. If one
 *   exists only its lessons count; if none does, only lessons without a
 *   version count (the legacy behaviour), so versions never double up;
 * - for a pupil, only whole-class lessons and lessons of groups the pupil
 *   belongs to;
 * - that date's exception (cancelled / substitution / room change) and
 *   that date's active substitute assignment.
 *
 * Each entry keeps the original keys (`lesson`, `cancelled`, `teacherName`,
 * `roomName`) and adds `status` — regular | cancelled | substituted |
 * room_changed | covering (the viewer teaches it as a substitute) |
 * handed_over (the viewer's own lesson, taught by a substitute).
 */
class ResolveDailyLessons
{
    public const STATUS_REGULAR = 'regular';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUBSTITUTED = 'substituted';

    public const STATUS_ROOM_CHANGED = 'room_changed';

    public const STATUS_COVERING = 'covering';

    public const STATUS_HANDED_OVER = 'handed_over';

    /**
     * Every lesson of the class, whatever group it is for.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forSchoolClass(int $tenantId, int $schoolClassId, Carbon $date): Collection
    {
        return $this->resolve(
            Lesson::query()->where('tenant_id', $tenantId)->where('school_class_id', $schoolClassId),
            $tenantId,
            $date,
        );
    }

    /**
     * The pupil's own lessons: their class's whole-class lessons and those
     * of the groups they belong to.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forStudent(int $tenantId, Student $student, Carbon $date): Collection
    {
        if ($student->school_class_id === null || ! $student->is_active) {
            return collect();
        }

        $groupIds = DB::table('student_group_members')
            ->where('tenant_id', $tenantId)
            ->where('student_id', $student->id)
            ->pluck('student_group_id');

        return $this->resolve(
            Lesson::query()
                ->where('tenant_id', $tenantId)
                ->where('school_class_id', $student->school_class_id)
                ->where(fn (Builder $query) => $query->whereNull('student_group_id')->orWhereIn('student_group_id', $groupIds)),
            $tenantId,
            $date,
        );
    }

    /**
     * The teacher's own lessons (with those handed to a substitute marked
     * as such) plus the lessons they cover on that date.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forTeacher(int $tenantId, int $teacherId, Carbon $date): Collection
    {
        $coveredLessonIds = SubstitutionAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('substitute_teacher_id', $teacherId)
            ->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
            ->whereDate('date', $date->toDateString())
            ->pluck('lesson_id')
            ->merge(LessonException::query()
                ->where('tenant_id', $tenantId)
                ->where('type', LessonException::TYPE_SUBSTITUTION)
                ->where('substitute_teacher_id', $teacherId)
                ->whereDate('occurs_on', $date->toDateString())
                ->pluck('lesson_id'));

        return $this->resolve(
            Lesson::query()
                ->where('tenant_id', $tenantId)
                ->where(fn (Builder $query) => $query->where('teacher_id', $teacherId)->orWhereIn('id', $coveredLessonIds)),
            $tenantId,
            $date,
            $teacherId,
        );
    }

    /**
     * @param  Builder<Lesson>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function resolve(Builder $query, int $tenantId, Carbon $date, ?int $viewerTeacherId = null): Collection
    {
        $day = $date->toDateString();

        $lessons = $query
            ->where('status', Lesson::STATUS_PUBLISHED)
            ->where('day_of_week', $date->dayOfWeekIso)
            ->with(['subject', 'teacher', 'room', 'schoolClass', 'studentGroup'])
            ->orderBy('starts_at')
            ->get();

        $lessons = $this->effectiveForDate($lessons, $tenantId, $day);

        if ($lessons->isEmpty()) {
            return collect();
        }

        $exceptions = LessonException::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->whereDate('occurs_on', $day)
            ->with(['substituteTeacher', 'substituteRoom'])
            ->get()
            ->keyBy('lesson_id');

        $covers = SubstitutionAssignment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
            ->whereDate('date', $day)
            ->with('substituteTeacher')
            ->get()
            ->keyBy('lesson_id');

        return $lessons
            ->map(fn (Lesson $lesson) => $this->formatEntry($lesson, $exceptions->get($lesson->id), $covers->get($lesson->id), $viewerTeacherId))
            ->values();
    }

    /**
     * Keep only lessons of the timetable version in effect on the date
     * (see the class docblock), per academic year.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Lesson>  $lessons
     * @return Collection<int, Lesson>
     */
    private function effectiveForDate(Collection $lessons, int $tenantId, string $day): Collection
    {
        if ($lessons->isEmpty()) {
            return $lessons;
        }

        $effective = TimetableVersion::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('academic_year_id', $lessons->pluck('academic_year_id')->unique())
            ->where('status', '!=', TimetableVersion::STATUS_DRAFT)
            ->whereDate('valid_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get()
            ->unique('academic_year_id')
            ->pluck('id', 'academic_year_id');

        return $lessons->filter(fn (Lesson $lesson) => $lesson->timetable_version_id === ($effective[$lesson->academic_year_id] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(Lesson $lesson, ?LessonException $exception, ?SubstitutionAssignment $cover, ?int $viewerTeacherId): array
    {
        $substitute = $exception?->type === LessonException::TYPE_SUBSTITUTION && $exception->substituteTeacher !== null
            ? $exception->substituteTeacher
            : $cover?->substituteTeacher;

        $roomName = $exception !== null && $exception->substituteRoom !== null
            ? $exception->substituteRoom->name
            : $lesson->room?->name;

        $cancelled = $exception !== null && $exception->type === LessonException::TYPE_CANCELLED;

        $status = match (true) {
            $cancelled => self::STATUS_CANCELLED,
            $substitute !== null && $viewerTeacherId !== null && $substitute->id === $viewerTeacherId => self::STATUS_COVERING,
            $substitute !== null && $viewerTeacherId !== null && $lesson->teacher_id === $viewerTeacherId => self::STATUS_HANDED_OVER,
            $substitute !== null => self::STATUS_SUBSTITUTED,
            $exception !== null && $exception->substituteRoom !== null => self::STATUS_ROOM_CHANGED,
            default => self::STATUS_REGULAR,
        };

        return [
            'lesson' => $lesson,
            'cancelled' => $cancelled,
            'status' => $status,
            'teacherName' => $substitute->name ?? $lesson->teacher->name,
            'regularTeacherName' => $lesson->teacher->name,
            'roomName' => $roomName,
        ];
    }
}
