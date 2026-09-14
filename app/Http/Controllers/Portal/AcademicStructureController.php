<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Actions\CreateAcademicYear;
use App\Domain\Academics\Actions\CreateSchoolClass;
use App\Domain\Academics\Actions\CreateStudent;
use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreAcademicYearRequest;
use App\Http\Requests\Portal\StoreSchoolClassRequest;
use App\Http\Requests\Portal\StoreStudentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin/director/academic-manager screen to set up the school's own
 * structure: academic years, classes, and the student roster. Before this
 * controller existed there was NO route or controller anywhere that could
 * create an AcademicYear or SchoolClass — confirmed as the root blocker in
 * production verification of the Assignments module: with zero classes,
 * MemberController's invite-a-teacher form had an empty dropdown, so a
 * TeacherAssignment (which every Assignment depends on) could never be
 * created through any real HTTP endpoint, only by hand in tinker/seeders.
 * This screen is the one place that gap is closed.
 */
class AcademicStructureController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request->user()->id);

        $academicYears = AcademicYear::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('schoolClasses')
            ->orderByDesc('starts_on')
            ->get();

        $schoolClasses = SchoolClass::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('students')
            ->with('academicYear')
            ->orderBy('name')
            ->get();

        $students = Student::query()
            ->where('tenant_id', $tenant->id)
            ->with('schoolClass')
            ->orderBy('first_name')
            ->get();

        return Inertia::render('portal/academic-structure/index', [
            'academicYears' => $academicYears->map(fn (AcademicYear $year) => [
                'id' => $year->id,
                'name' => $year->name,
                'startsOn' => $year->starts_on->toDateString(),
                'endsOn' => $year->ends_on->toDateString(),
                'isCurrent' => $year->is_current,
                'schoolClassesCount' => $year->school_classes_count,
            ])->values(),
            'schoolClasses' => $schoolClasses->map(fn (SchoolClass $schoolClass) => [
                'id' => $schoolClass->id,
                'name' => $schoolClass->name,
                'academicYearId' => $schoolClass->academic_year_id,
                'academicYearName' => $schoolClass->academicYear->name,
                'studentsCount' => $schoolClass->students_count,
            ])->values(),
            'students' => $students->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'nationalId' => $student->national_id,
                'schoolClassId' => $student->school_class_id,
                'schoolClassName' => $student->schoolClass?->name,
                'isActive' => $student->is_active,
            ])->values(),
        ]);
    }

    public function storeAcademicYear(StoreAcademicYearRequest $request, CurrentTenant $currentTenant, CreateAcademicYear $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);

        $action->handle(
            tenantId: $tenant->id,
            actor: $actor,
            name: $request->string('name')->toString(),
            startsOn: Carbon::parse((string) $request->string('starts_on')),
            endsOn: Carbon::parse((string) $request->string('ends_on')),
            isCurrent: $request->boolean('is_current'),
        );

        return redirect()->route('academic-structure.index')->with('toast', [
            'type' => 'success', 'message' => 'სასწავლო წელი დაემატა.',
        ]);
    }

    public function storeSchoolClass(StoreSchoolClassRequest $request, CurrentTenant $currentTenant, CreateSchoolClass $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);

        $academicYear = AcademicYear::query()
            ->where('tenant_id', $tenant->id)
            ->find($request->integer('academic_year_id'));

        abort_if($academicYear === null, 404);

        $action->handle($tenant->id, $actor, $academicYear, $request->string('name')->toString());

        return redirect()->route('academic-structure.index')->with('toast', [
            'type' => 'success', 'message' => 'კლასი დაემატა.',
        ]);
    }

    public function storeStudent(StoreStudentRequest $request, CurrentTenant $currentTenant, CreateStudent $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);

        $schoolClass = SchoolClass::query()
            ->where('tenant_id', $tenant->id)
            ->find($request->integer('school_class_id'));

        abort_if($schoolClass === null, 404);

        $action->handle(
            tenantId: $tenant->id,
            actor: $actor,
            schoolClass: $schoolClass,
            firstName: $request->string('first_name')->toString(),
            lastName: $request->string('last_name')->toString(),
            nationalId: $request->string('national_id')->toString() ?: null,
        );

        return redirect()->route('academic-structure.index')->with('toast', [
            'type' => 'success', 'message' => 'მოსწავლე დაემატა.',
        ]);
    }

    private function authorizeAccess(int $tenantId, int $userId): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::ACCESS_ROLES), 403);
    }
}
