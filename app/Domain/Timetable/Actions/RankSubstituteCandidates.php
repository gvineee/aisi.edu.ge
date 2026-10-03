<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\StaffAbsence;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use Illuminate\Support\Carbon;

/**
 * Computed (not stored) substitute candidates for open lesson occurrences
 * (CLAUDE-PLATFORM-MODULES §9). A candidate is available when they are not
 * absent that day, have no own published lesson overlapping the slot and
 * no other active cover overlapping it. Available candidates are ranked
 * by qualification (already teaches this subject), familiarity (already
 * teaches this class) and that day's workload. This is advice for the
 * coordinator; AssignSubstitute re-checks conflicts in its transaction.
 */
class RankSubstituteCandidates
{
    private const LIMIT = 5;

    /**
     * @param  array<int, array{id: int, name: string}>  $teachers
     * @param  array<int, array{lessonId: int, date: string}>  $occurrences
     * @return array<string, array<int, array{id: int, name: string, qualified: bool, knowsClass: bool, load: int}>> keyed "lessonId:date"
     */
    public function handle(int $tenantId, array $teachers, array $occurrences): array
    {
        if ($teachers === [] || $occurrences === []) {
            return [];
        }

        $teacherIds = array_column($teachers, 'id');
        $dates = array_values(array_unique(array_column($occurrences, 'date')));
        sort($dates);
        $first = $dates[0];
        $last = $dates[count($dates) - 1];

        $lessons = Lesson::query()->where('tenant_id', $tenantId)->whereIn('id', array_unique(array_column($occurrences, 'lessonId')))->with('subject')->get()->keyBy('id');
        $schedules = Lesson::query()->where('tenant_id', $tenantId)->where('status', Lesson::STATUS_PUBLISHED)->whereIn('teacher_id', $teacherIds)->get();
        $covers = SubstitutionAssignment::query()->where('tenant_id', $tenantId)->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
            ->whereIn('substitute_teacher_id', $teacherIds)->whereDate('date', '>=', $first)->whereDate('date', '<=', $last)
            ->with('lesson')->get();
        $absences = StaffAbsence::query()->where('tenant_id', $tenantId)->whereIn('user_id', $teacherIds)
            ->whereDate('starts_on', '<=', $last)->whereDate('ends_on', '>=', $first)->get();
        $teaching = TeacherAssignment::query()->where('tenant_id', $tenantId)->whereIn('user_id', $teacherIds)->get();

        $result = [];

        foreach ($occurrences as $occurrence) {
            $lesson = $lessons->get($occurrence['lessonId']);
            if ($lesson === null) {
                continue;
            }
            $date = $occurrence['date'];
            $dayOfWeek = Carbon::parse($date)->dayOfWeekIso;
            $subjectName = mb_strtolower((string) ($lesson->subject->name ?? ''));
            $candidates = [];

            foreach ($teachers as $teacher) {
                $id = $teacher['id'];
                if ($id === $lesson->teacher_id) {
                    continue;
                }

                $absent = $absences->contains(fn (StaffAbsence $a) => $a->user_id === $id && $a->starts_on->toDateString() <= $date && $a->ends_on->toDateString() >= $date);
                $ownLessonsThatDay = $schedules->filter(fn (Lesson $l) => (int) $l->teacher_id === $id && (int) $l->day_of_week === $dayOfWeek);
                $ownClash = $ownLessonsThatDay->contains(fn (Lesson $l) => $l->starts_at < $lesson->ends_at && $l->ends_at > $lesson->starts_at);
                $coversThatDay = $covers->filter(fn (SubstitutionAssignment $c) => $c->substitute_teacher_id === $id && $c->date->toDateString() === $date);
                $coverClash = $coversThatDay->contains(fn (SubstitutionAssignment $c) => $c->lesson_id !== $lesson->id
                    && $c->lesson->starts_at < $lesson->ends_at && $c->lesson->ends_at > $lesson->starts_at);

                if ($absent || $ownClash || $coverClash) {
                    continue;
                }

                $mine = $teaching->filter(fn (TeacherAssignment $t) => $t->user_id === $id);
                $candidates[] = [
                    'id' => $id,
                    'name' => $teacher['name'],
                    'qualified' => $subjectName !== '' && $mine->contains(fn (TeacherAssignment $t) => mb_strtolower((string) $t->subject) === $subjectName),
                    'knowsClass' => $mine->contains(fn (TeacherAssignment $t) => $t->school_class_id === $lesson->school_class_id),
                    'load' => $ownLessonsThatDay->count() + $coversThatDay->count(),
                ];
            }

            usort($candidates, fn (array $a, array $b) => [$b['qualified'], $b['knowsClass'], $a['load'], $a['name']] <=> [$a['qualified'], $a['knowsClass'], $b['load'], $b['name']]);
            $result[$lesson->id.':'.$date] = array_slice($candidates, 0, self::LIMIT);
        }

        return $result;
    }
}
