<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\PortalContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * All pupils in one list for the school's management: by year and class,
 * searchable, with their guardians and a way into each pupil's portfolio,
 * progress and report cards. Adding and editing stays in the academic
 * structure, where classes live.
 */
class StudentDirectoryController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, PortalContext::STUDENT_DIRECTORY_ACCESS_ROLES), 403);

        $years = AcademicYear::query()->where('tenant_id', $tenantId)->orderByDesc('starts_on')->get();
        $year = $years->firstWhere('id', $request->integer('year')) ?? $years->firstWhere('is_current', true) ?? $years->first();
        $classes = $year === null ? collect() : SchoolClass::query()->where('tenant_id', $tenantId)->where('academic_year_id', $year->id)->get()->sortBy('name', SORT_NATURAL)->values();
        $classId = $classes->firstWhere('id', $request->integer('class'))?->id;
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        $showInactive = $request->boolean('inactive');

        $students = Student::query()->where('tenant_id', $tenantId)
            ->whereIn('school_class_id', $classId !== null ? [$classId] : $classes->pluck('id'))
            ->when(! $showInactive, fn ($q) => $q->where('is_active', true))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
            ->with(['schoolClass:id,name', 'guardianLinks' => fn ($q) => $q->where('is_active', true)->with('guardian:id,name')])
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(500)->get();

        $groups = DB::table('student_group_members')->join('student_groups', 'student_groups.id', '=', 'student_group_members.student_group_id')
            ->where('student_group_members.tenant_id', $tenantId)->whereIn('student_group_members.student_id', $students->pluck('id'))
            ->get(['student_group_members.student_id', 'student_groups.name'])->groupBy('student_id');

        return Inertia::render('portal/students/index', [
            'years' => $years->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name])->values(),
            'yearId' => $year?->id,
            'classes' => $classes->map(fn (SchoolClass $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'filters' => ['class' => $classId, 'q' => $search, 'inactive' => $showInactive],
            'total' => $year === null ? 0 : Student::query()->where('tenant_id', $tenantId)->whereIn('school_class_id', $classes->pluck('id'))->where('is_active', true)->count(),
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->fullName(),
                'className' => $s->schoolClass->name ?? null,
                'isActive' => $s->is_active,
                'hasAccount' => $s->user_id !== null,
                'groups' => $groups->get($s->id, collect())->pluck('name')->values(),
                'guardians' => $s->guardianLinks->map(fn (GuardianLink $l) => $l->guardian->name ?? '')->filter()->values(),
            ])->values(),
        ]);
    }
}
