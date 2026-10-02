<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Assessment\Models\AssessmentResult;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "პროგრესი" — one student's results, grouped by subject and period.
 *
 * Who sees what:
 *  - the student themself and a guardian with an active link that carries
 *    `can_view_academic`: published assessments only;
 *  - a teacher: only their own subjects for this student's class, drafts
 *    included (they are the ones grading);
 *  - academic manager / director / admin: everything, drafts labelled.
 */
class StudentProgressController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const MANAGEMENT_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function mine(Request $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        abort_unless(TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_STUDENT), 403);

        $student = Student::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->firstOrFail();

        return redirect()->route('progress.show', $student);
    }

    public function show(Request $request, CurrentTenant $currentTenant, Student $student): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);

        $user = $request->user();
        $isManagement = TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::MANAGEMENT_ROLES);
        $isOwner = $this->isOwnerStudent($tenant->id, $student, $user);
        $isGuardian = $this->isAcademicGuardian($tenant->id, $student, $user);
        $teacherAssignmentIds = $this->ownTeacherAssignmentIds($tenant->id, $student, $user);

        abort_unless($isManagement || $isOwner || $isGuardian || $teacherAssignmentIds !== [], 403);

        $seesDrafts = $isManagement || $teacherAssignmentIds !== [];

        $assessments = $student->school_class_id === null ? collect() : Assessment::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('teacherAssignment', fn ($query) => $query
                ->where('tenant_id', $tenant->id)
                ->where('school_class_id', $student->school_class_id))
            // A teacher who is not also management/family sees only their own subjects.
            ->when(! $isManagement && ! $isOwner && ! $isGuardian, fn ($query) => $query->whereIn('teacher_assignment_id', $teacherAssignmentIds))
            ->when(! $seesDrafts, fn ($query) => $query->where('status', Assessment::STATUS_PUBLISHED))
            // A teacher-and-parent of the same child still sees drafts only for their own subjects.
            ->when($seesDrafts && ! $isManagement, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('status', Assessment::STATUS_PUBLISHED)
                ->orWhereIn('teacher_assignment_id', $teacherAssignmentIds)))
            ->with('teacherAssignment', 'category', 'period')
            ->orderBy('assessed_on')
            ->get();

        $results = AssessmentResult::query()
            ->where('tenant_id', $tenant->id)
            ->where('student_id', $student->id)
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->get()
            ->keyBy('assessment_id');

        $periods = AssessmentPeriod::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $assessments->pluck('assessment_period_id')->unique())
            ->orderBy('starts_on')
            ->get();

        return Inertia::render('portal/progress/show', [
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
            ],
            'viewerSeesDrafts' => $seesDrafts,
            'periods' => $periods->map(fn (AssessmentPeriod $period) => [
                'id' => $period->id,
                'name' => $period->name,
                'startsOn' => $period->starts_on->toDateString(),
                'endsOn' => $period->ends_on->toDateString(),
                'isClosed' => $period->isClosed(),
            ])->values(),
            'subjects' => $this->groupBySubject($assessments, $results),
        ]);
    }

    /**
     * @param  Collection<int, Assessment>  $assessments
     * @param  Collection<int, AssessmentResult>  $results
     * @return array<int, array<string, mixed>>
     */
    private function groupBySubject(Collection $assessments, Collection $results): array
    {
        return $assessments
            ->groupBy(fn (Assessment $assessment) => $assessment->teacherAssignment->subject ?: 'საგანი მითითებული არ არის')
            ->map(function (Collection $group, string $subject) use ($results) {
                $rows = $group->map(function (Assessment $assessment) use ($results) {
                    /** @var AssessmentResult|null $result */
                    $result = $results->get($assessment->id);

                    return [
                        'id' => $assessment->id,
                        'periodId' => $assessment->assessment_period_id,
                        'title' => $assessment->title,
                        'assessedOn' => $assessment->assessed_on->toDateString(),
                        'categoryName' => $assessment->category?->name,
                        'status' => $assessment->status,
                        'maxScore' => $assessment->max_score,
                        'rawScore' => $result?->raw_score,
                        'normalizedPercent' => $result?->normalized_percent,
                        'finalGrade' => $result?->final_grade,
                        'comment' => $result?->comment,
                    ];
                })->values()->all();

                return [
                    'subject' => $subject,
                    'assessments' => $rows,
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    private function isOwnerStudent(int $tenantId, Student $student, User $user): bool
    {
        return $student->user_id === $user->id
            && $student->is_active
            && TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_STUDENT);
    }

    private function isAcademicGuardian(int $tenantId, Student $student, User $user): bool
    {
        return TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_GUARDIAN)
            && GuardianLink::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->where('can_view_academic', true)
                ->exists();
    }

    /**
     * @return array<int, int>
     */
    private function ownTeacherAssignmentIds(int $tenantId, Student $student, User $user): array
    {
        if ($student->school_class_id === null
            || ! TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_TEACHER)) {
            return [];
        }

        return TeacherAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('school_class_id', $student->school_class_id)
            ->pluck('id')
            ->all();
    }
}
