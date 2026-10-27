<?php

namespace App\Domain\Analytics;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Learning\Actions\CurriculumProgress;
use App\Domain\Learning\Models\CurriculumPlan;
use App\Domain\Timetable\Models\Subject;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The academic overview for management: per class — and, for one class,
 * per subject and per pupil — attendance, published assessment results and
 * curriculum progress over a period. Every figure is a plain aggregate of
 * recorded data with its definition on the page; nothing is extrapolated,
 * and averages are unweighted means of published results, never a grade
 * (weighting is school policy). With no data a figure is null, not zero.
 */
class AcademicOverview
{
    private const ATTENDANCE_COLUMNS = "count(*) as marked, sum(case when attendance_records.status in ('present', 'late') then 1 else 0 end) as attended, sum(case when attendance_records.status = 'absent' then 1 else 0 end) as absent";

    private const RESULT_COLUMNS = 'count(distinct assessments.id) as assessments, count(*) as results, avg(assessment_results.normalized_percent) as average';

    public function __construct(private readonly CurriculumProgress $progress) {}

    /**
     * @param  Collection<int, SchoolClass>  $classes
     * @param  list<int>  $periodIds  assessment periods whose published results count
     * @return list<array{classId: int, className: string, students: int, attendance: array{marked: int, attended: int, absent: int, rate: float|null}, results: array{assessments: int, results: int, average: float|null}, curriculum: array{plans: int, topics: int, covered: int, overdue: int}}>
     */
    public function classes(int $tenantId, Collection $classes, array $periodIds, Carbon $from, Carbon $to, Carbon $today): array
    {
        $classIds = array_values(array_map('intval', $classes->pluck('id')->all()));
        $students = Student::query()->where('tenant_id', $tenantId)->whereIn('school_class_id', $classIds)->where('is_active', true)
            ->groupBy('school_class_id')->selectRaw('school_class_id, count(*) as total')->pluck('total', 'school_class_id');
        $attendance = $this->attendance($tenantId, $from, $to)->whereIn('students.school_class_id', $classIds)
            ->groupBy('students.school_class_id')->selectRaw('students.school_class_id as id, '.self::ATTENDANCE_COLUMNS)->get()->keyBy('id');
        $results = $this->results($tenantId, $periodIds)->whereIn('teacher_assignments.school_class_id', $classIds)
            ->groupBy('teacher_assignments.school_class_id')->selectRaw('teacher_assignments.school_class_id as id, '.self::RESULT_COLUMNS)->get()->keyBy('id');
        $curriculum = $this->curriculum($tenantId, $classIds, $today);

        $rows = [];

        foreach ($classes->sortBy('name', SORT_NATURAL) as $class) {
            $rows[] = [
                'classId' => $class->id,
                'className' => $class->name,
                'students' => (int) ($students[$class->id] ?? 0),
                'attendance' => $this->attendanceFigures($attendance->get($class->id)),
                'results' => $this->resultFigures($results->get($class->id)),
                'curriculum' => $this->curriculumFigures($curriculum->filter(fn (array $plan) => $plan['classId'] === $class->id)),
            ];
        }

        return $rows;
    }

    /**
     * One class: per subject, and the pupils with their attendance rate and
     * mean result, lowest attendance first.
     *
     * @param  list<int>  $periodIds
     * @return array{subjects: list<array{subjectId: int, subjectName: string, teachers: list<string>, attendance: array{marked: int, attended: int, absent: int, rate: float|null}, results: array{assessments: int, results: int, average: float|null}, curriculum: array{plans: int, topics: int, covered: int, overdue: int}}>, students: list<array{id: int, name: string, attendance: array{marked: int, attended: int, absent: int, rate: float|null}, average: float|null, results: int}>}
     */
    public function classDetail(int $tenantId, SchoolClass $class, array $periodIds, Carbon $from, Carbon $to, Carbon $today): array
    {
        $assignments = TeacherAssignment::query()->where('tenant_id', $tenantId)->where('school_class_id', $class->id)->whereNotNull('subject_id')
            ->with('teacher:id,name', 'staffMember:id,full_name')->get();
        $subjectIds = $assignments->pluck('subject_id')
            ->merge(DB::table('lessons')->where('tenant_id', $tenantId)->where('school_class_id', $class->id)->distinct()->pluck('subject_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        $subjects = Subject::query()->where('tenant_id', $tenantId)->whereIn('id', $subjectIds)->pluck('name', 'id');

        $attendance = $this->attendance($tenantId, $from, $to)->where('students.school_class_id', $class->id)
            ->join('lessons', 'lessons.id', '=', 'attendance_records.lesson_id')
            ->groupBy('lessons.subject_id')->selectRaw('lessons.subject_id as id, '.self::ATTENDANCE_COLUMNS)->get()->keyBy('id');
        $results = $this->results($tenantId, $periodIds)->where('teacher_assignments.school_class_id', $class->id)
            ->groupBy('teacher_assignments.subject_id')->selectRaw('teacher_assignments.subject_id as id, '.self::RESULT_COLUMNS)->get()->keyBy('id');
        $curriculum = $this->curriculum($tenantId, [$class->id], $today);

        $subjectRows = [];

        foreach ($subjects->sort() as $subjectId => $name) {
            $subjectRows[] = [
                'subjectId' => (int) $subjectId,
                'subjectName' => (string) $name,
                'teachers' => array_values(array_unique(array_filter($assignments->where('subject_id', $subjectId)
                    ->map(fn (TeacherAssignment $ta) => $ta->teacher->name ?? $ta->staffMember->full_name ?? null)->all()))),
                'attendance' => $this->attendanceFigures($attendance->get($subjectId)),
                'results' => $this->resultFigures($results->get($subjectId)),
                'curriculum' => $this->curriculumFigures($curriculum->filter(fn (array $plan) => $plan['subjectId'] === (int) $subjectId)),
            ];
        }

        $pupils = Student::query()->where('tenant_id', $tenantId)->where('school_class_id', $class->id)->where('is_active', true)
            ->orderBy('last_name')->orderBy('first_name')->get();
        $pupilAttendance = $this->attendance($tenantId, $from, $to)->where('students.school_class_id', $class->id)
            ->groupBy('attendance_records.student_id')->selectRaw('attendance_records.student_id as id, '.self::ATTENDANCE_COLUMNS)->get()->keyBy('id');
        $pupilResults = $this->results($tenantId, $periodIds)->where('teacher_assignments.school_class_id', $class->id)
            ->groupBy('assessment_results.student_id')->selectRaw('assessment_results.student_id as id, '.self::RESULT_COLUMNS)->get()->keyBy('id');

        $studentRows = array_values($pupils->map(function (Student $student) use ($pupilAttendance, $pupilResults) {
            $results = $this->resultFigures($pupilResults->get($student->id));

            return [
                'id' => $student->id,
                'name' => $student->fullName(),
                'attendance' => $this->attendanceFigures($pupilAttendance->get($student->id)),
                'average' => $results['average'],
                'results' => $results['results'],
            ];
        })->sortBy(fn (array $row) => $row['attendance']['rate'] ?? 101)->all());

        return ['subjects' => $subjectRows, 'students' => $studentRows];
    }

    private function attendance(int $tenantId, Carbon $from, Carbon $to): Builder
    {
        return DB::table('attendance_records')
            ->join('students', 'students.id', '=', 'attendance_records.student_id')
            ->where('attendance_records.tenant_id', $tenantId)
            ->where('students.tenant_id', $tenantId)
            ->where('students.is_active', true)
            ->whereDate('attendance_records.occurred_on', '>=', $from->toDateString())
            ->whereDate('attendance_records.occurred_on', '<=', $to->toDateString());
    }

    /**
     * @param  list<int>  $periodIds
     */
    private function results(int $tenantId, array $periodIds): Builder
    {
        return DB::table('assessment_results')
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->join('teacher_assignments', 'teacher_assignments.id', '=', 'assessments.teacher_assignment_id')
            ->where('assessment_results.tenant_id', $tenantId)
            ->where('assessments.tenant_id', $tenantId)
            ->where('assessments.status', Assessment::STATUS_PUBLISHED)
            ->whereIn('assessments.assessment_period_id', $periodIds === [] ? [0] : $periodIds);
    }

    /**
     * @return array{marked: int, attended: int, absent: int, rate: float|null}
     */
    private function attendanceFigures(?object $row): array
    {
        $marked = (int) ($row->marked ?? 0);
        $attended = (int) ($row->attended ?? 0);

        return [
            'marked' => $marked,
            'attended' => $attended,
            'absent' => (int) ($row->absent ?? 0),
            'rate' => $marked === 0 ? null : round($attended / $marked * 100, 1),
        ];
    }

    /**
     * @return array{assessments: int, results: int, average: float|null}
     */
    private function resultFigures(?object $row): array
    {
        $results = (int) ($row->results ?? 0);

        return [
            'assessments' => (int) ($row->assessments ?? 0),
            'results' => $results,
            'average' => $results === 0 ? null : round((float) ($row->average ?? 0), 1),
        ];
    }

    /**
     * @param  list<int>  $classIds
     * @return Collection<int, array{classId: int, subjectId: int, topics: int, covered: int, overdue: int}>
     */
    private function curriculum(int $tenantId, array $classIds, Carbon $today): Collection
    {
        $plans = CurriculumPlan::query()->where('tenant_id', $tenantId)->whereIn('school_class_id', $classIds)->with('topics')->get();
        $stats = $this->progress->handle($tenantId, $plans, $today);

        return $plans->map(fn (CurriculumPlan $plan) => [
            'classId' => $plan->school_class_id,
            'subjectId' => $plan->subject_id,
            'topics' => $stats[$plan->id]['topics'],
            'covered' => $stats[$plan->id]['covered'],
            'overdue' => $stats[$plan->id]['overdue'],
        ]);
    }

    /**
     * @param  Collection<int, array{classId: int, subjectId: int, topics: int, covered: int, overdue: int}>  $plans
     * @return array{plans: int, topics: int, covered: int, overdue: int}
     */
    private function curriculumFigures(Collection $plans): array
    {
        return [
            'plans' => $plans->count(),
            'topics' => (int) $plans->sum('topics'),
            'covered' => (int) $plans->sum('covered'),
            'overdue' => (int) $plans->sum('overdue'),
        ];
    }
}
