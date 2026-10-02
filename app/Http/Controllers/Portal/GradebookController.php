<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Assessment\Actions\CreateAssessment;
use App\Domain\Assessment\Actions\PublishAssessment;
use App\Domain\Assessment\Actions\RecordAssessmentResults;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentCategory;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Assessment\Models\AssessmentResult;
use App\Domain\Assessment\Models\AssessmentResultRevision;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\RecordAssessmentResultsRequest;
use App\Http\Requests\Portal\StoreAssessmentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "შეფასებები" teacher gradebook. A teacher only ever creates, scores or
 * publishes assessments on their own `teacher_assignment` rows (docs/02
 * critical check #3) — the same ownership pattern AssignmentController uses.
 */
class GradebookController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $this->authorizeTeacher($tenant->id, $user->id);

        $teacherAssignments = TeacherAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->with('schoolClass')
            ->orderBy('id')
            ->get();

        $selected = $teacherAssignments->firstWhere('id', $request->integer('ta')) ?? $teacherAssignments->first();

        $periods = $selected?->schoolClass === null
            ? collect()
            : AssessmentPeriod::query()
                ->where('tenant_id', $tenant->id)
                ->where('academic_year_id', $selected->schoolClass->academic_year_id)
                ->orderBy('starts_on')
                ->get();

        $selectedPeriod = $periods->firstWhere('id', $request->integer('period'))
            ?? $periods->first(fn (AssessmentPeriod $period) => ! $period->isClosed())
            ?? $periods->last();

        $assessments = $selected === null || $selectedPeriod === null
            ? collect()
            : Assessment::query()
                ->where('tenant_id', $tenant->id)
                ->where('teacher_assignment_id', $selected->id)
                ->where('assessment_period_id', $selectedPeriod->id)
                ->with('category')
                ->withCount('results')
                ->orderByDesc('assessed_on')
                ->get();

        $rosterCount = $selected === null ? 0 : Student::query()
            ->where('tenant_id', $tenant->id)
            ->where('school_class_id', $selected->school_class_id)
            ->where('is_active', true)
            ->count();

        $categories = AssessmentCategory::query()->where('tenant_id', $tenant->id)->orderBy('name')->get();

        return Inertia::render('portal/gradebook/index', [
            'teacherAssignments' => $teacherAssignments->map(fn (TeacherAssignment $teacherAssignment) => [
                'id' => $teacherAssignment->id,
                'label' => $this->teacherAssignmentLabel($teacherAssignment),
            ])->values(),
            'selectedTeacherAssignmentId' => $selected?->id,
            'periods' => $periods->map(fn (AssessmentPeriod $period) => [
                'id' => $period->id,
                'name' => $period->name,
                'startsOn' => $period->starts_on->toDateString(),
                'endsOn' => $period->ends_on->toDateString(),
                'isClosed' => $period->isClosed(),
            ])->values(),
            'selectedPeriodId' => $selectedPeriod?->id,
            'categories' => $categories->map(fn (AssessmentCategory $category) => ['id' => $category->id, 'name' => $category->name])->values(),
            'rosterCount' => $rosterCount,
            'assessments' => $assessments->map(fn (Assessment $assessment) => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'assessedOn' => $assessment->assessed_on->toDateString(),
                'categoryName' => $assessment->category?->name,
                'maxScore' => $assessment->max_score,
                'status' => $assessment->status,
                'resultsCount' => $assessment->results_count,
            ])->values(),
        ]);
    }

    public function store(StoreAssessmentRequest $request, CurrentTenant $currentTenant, CreateAssessment $createAssessment): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $this->authorizeTeacher($tenant->id, $user->id);

        $teacherAssignment = TeacherAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($request->integer('teacher_assignment_id'))
            ->first();
        abort_unless($teacherAssignment !== null && $teacherAssignment->user_id === $user->id, 403);

        $period = AssessmentPeriod::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($request->integer('assessment_period_id'))
            ->firstOrFail();

        $category = $request->filled('assessment_category_id')
            ? AssessmentCategory::query()->where('tenant_id', $tenant->id)->whereKey($request->integer('assessment_category_id'))->firstOrFail()
            : null;

        $assessment = $createAssessment->handle(
            teacherAssignment: $teacherAssignment,
            period: $period,
            category: $category,
            creator: $user,
            title: $request->string('title')->toString(),
            assessedOn: Carbon::parse((string) $request->string('assessed_on')),
            maxScore: (string) $request->input('max_score'),
        );

        return redirect()->route('gradebook.show', $assessment);
    }

    public function show(Request $request, CurrentTenant $currentTenant, Assessment $assessment): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($assessment->tenant_id === $tenant->id, 404);
        $this->authorizeOwner($request, $assessment);

        $assessment->load('teacherAssignment.schoolClass', 'period', 'category', 'gradeScale.bands');

        $students = Student::query()
            ->where('tenant_id', $tenant->id)
            ->where('school_class_id', $assessment->teacherAssignment->school_class_id)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $results = AssessmentResult::query()
            ->where('tenant_id', $tenant->id)
            ->where('assessment_id', $assessment->id)
            ->with(['revisions' => fn ($query) => $query->limit(50)])
            ->get()
            ->keyBy('student_id');

        return Inertia::render('portal/gradebook/show', [
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'teacherAssignmentId' => $assessment->teacher_assignment_id,
                'classLabel' => $this->teacherAssignmentLabel($assessment->teacherAssignment),
                'periodId' => $assessment->assessment_period_id,
                'periodName' => $assessment->period->name,
                'periodClosed' => $assessment->period->isClosed(),
                'categoryName' => $assessment->category?->name,
                'assessedOn' => $assessment->assessed_on->toDateString(),
                'maxScore' => $assessment->max_score,
                'status' => $assessment->status,
                'publishedAt' => $assessment->published_at?->toIso8601String(),
                'gradeScale' => $assessment->gradeScale === null ? null : [
                    'name' => $assessment->gradeScale->name,
                    'version' => $assessment->gradeScale->version,
                ],
            ],
            'roster' => $students->map(function (Student $student) use ($results) {
                /** @var AssessmentResult|null $result */
                $result = $results->get($student->id);

                return [
                    'studentId' => $student->id,
                    'studentName' => $student->fullName(),
                    'rawScore' => $result?->raw_score,
                    'normalizedPercent' => $result?->normalized_percent,
                    'finalGrade' => $result?->final_grade,
                    'comment' => $result?->comment,
                    'version' => $result?->version,
                    'revisions' => $result === null ? [] : $result->revisions->map(fn (AssessmentResultRevision $revision) => [
                        'version' => $revision->version,
                        'rawScore' => $revision->raw_score,
                        'reason' => $revision->reason,
                        'createdAt' => $revision->created_at?->toIso8601String(),
                    ])->values()->all(),
                ];
            })->values(),
        ]);
    }

    public function recordResults(RecordAssessmentResultsRequest $request, CurrentTenant $currentTenant, Assessment $assessment, RecordAssessmentResults $recordResults): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($assessment->tenant_id === $tenant->id, 404);
        $this->authorizeOwner($request, $assessment);

        /** @var array<int, array{student_id: int, raw_score: string, comment?: string|null, expected_version?: int|null}> $entries */
        $entries = array_values($request->array('results'));

        $changed = $recordResults->handle($assessment, $request->user(), $entries, $request->string('reason')->toString() ?: null);

        return back()->with('savedCount', $changed);
    }

    public function publish(Request $request, CurrentTenant $currentTenant, Assessment $assessment, PublishAssessment $publishAssessment): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($assessment->tenant_id === $tenant->id, 404);
        $this->authorizeOwner($request, $assessment);

        $publishAssessment->handle($assessment, $request->user());

        return back();
    }

    private function authorizeTeacher(int $tenantId, int $userId): void
    {
        abort_unless(TenantMembership::userHasActiveRole($tenantId, $userId, TenantMembership::ROLE_TEACHER), 403);
    }

    private function authorizeOwner(Request $request, Assessment $assessment): void
    {
        $this->authorizeTeacher($assessment->tenant_id, $request->user()->id);
        $assessment->loadMissing('teacherAssignment');
        abort_unless($assessment->teacherAssignment->user_id === $request->user()->id, 403);
    }

    private function teacherAssignmentLabel(TeacherAssignment $teacherAssignment): string
    {
        return trim(($teacherAssignment->schoolClass->name ?? '').($teacherAssignment->subject ? " · {$teacherAssignment->subject}" : ''));
    }
}
