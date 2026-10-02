<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Assessment\Actions\ManageCompetencies;
use App\Domain\Assessment\Models\Competency;
use App\Domain\Assessment\Models\CompetencyLevel;
use App\Domain\Assessment\Models\StudentCompetencyObservation;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "კომპეტენციები" (CLAUDE-PLATFORM-MODULES §6). Management defines the
 * school's framework; teachers record observations only for students of
 * their own class/subject.
 */
class CompetencyController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $isManagement = TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, AssessmentSettingsController::ACCESS_ROLES);
        $isTeacher = TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER);
        abort_unless($isManagement || $isTeacher, 403);

        $competencies = Competency::query()->where('tenant_id', $tenant->id)->where('is_active', true)->with('levels')->orderBy('code')->get();
        $teacherAssignments = $isTeacher
            ? TeacherAssignment::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->with('schoolClass')->get()
            : collect();

        return Inertia::render('portal/competencies/index', [
            'isManagement' => $isManagement,
            'competencies' => $competencies->map(fn (Competency $c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'description' => $c->description,
                'levels' => $c->levels->map(fn (CompetencyLevel $l) => ['id' => $l->id, 'level' => $l->level, 'label' => $l->label, 'descriptor' => $l->descriptor])->values()->all(),
            ])->values(),
            'teacherAssignments' => $teacherAssignments->map(fn (TeacherAssignment $ta) => [
                'id' => $ta->id,
                'label' => trim(($ta->schoolClass->name ?? '').($ta->subject ? " · {$ta->subject}" : '')),
                'students' => Student::query()->where('tenant_id', $tenant->id)->where('school_class_id', $ta->school_class_id)->where('is_active', true)
                    ->orderBy('last_name')->get()->map(fn (Student $s) => ['id' => $s->id, 'name' => $s->fullName()])->values()->all(),
            ])->values(),
            'recent' => StudentCompetencyObservation::query()->where('tenant_id', $tenant->id)->where('recorded_by', $user->id)
                ->with('student', 'competency', 'level')->latest('id')->limit(20)->get()
                ->map(fn (StudentCompetencyObservation $o) => [
                    'id' => $o->id, 'studentName' => $o->student->fullName(), 'competency' => $o->competency->name,
                    'level' => $o->level->label, 'observedOn' => $o->observed_on->toDateString(), 'shared' => $o->shared_with_family,
                ])->values(),
        ]);
    }

    public function store(Request $request, CurrentTenant $currentTenant, ManageCompetencies $competencies): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, AssessmentSettingsController::ACCESS_ROLES), 403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'levels' => ['required', 'array', 'min:2', 'max:10'],
            'levels.*.label' => ['required', 'string', 'max:60'],
            'levels.*.descriptor' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var array<int, array{label: string, descriptor?: string|null}> $levels */
        $levels = $data['levels'];
        $competencies->create($tenant->id, $request->user(), $data['code'], $data['name'], $data['description'] ?? null, $levels);

        return back();
    }

    public function observe(Request $request, CurrentTenant $currentTenant, ManageCompetencies $competencies): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless(TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER), 403);

        $data = $request->validate([
            'teacher_assignment_id' => ['required', 'integer'],
            'student_id' => ['required', 'integer'],
            'competency_level_id' => ['required', 'integer'],
            'observed_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'shared_with_family' => ['boolean'],
        ]);

        $ta = TeacherAssignment::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['teacher_assignment_id'])->first();
        abort_unless($ta !== null && $ta->user_id === $user->id, 403);

        $student = Student::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['student_id'])->firstOrFail();
        $level = CompetencyLevel::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['competency_level_id'])->firstOrFail();

        $competencies->observe($ta, $student, $level, $user, Carbon::parse($data['observed_on'], $tenant->timezone), $data['note'] ?? null, $request->boolean('shared_with_family'));

        return back();
    }
}
