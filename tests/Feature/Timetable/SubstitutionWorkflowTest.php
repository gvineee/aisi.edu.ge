<?php

namespace Tests\Feature\Timetable;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\StaffAbsence;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the Teacher Substitution module — the one piece of the "teacher
 * workspace" the last audit found missing (lessons/attendance/
 * teacher_assignments were already real and tested). AssignSubstitute's
 * conflict check runs against the real lessons table (CLAUDE.md invariant
 * about server-verified, never client-trusted, state); tenant isolation
 * (invariants #1-2) and role gating (invariant #3-4) are covered directly.
 */
class SubstitutionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, admin: User, absentTeacher: User, substituteTeacher: User, lesson: Lesson, dayOfWeek: int, today: Carbon}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $today = Carbon::now($tenant->timezone);
        $dayOfWeek = $today->dayOfWeekIso;

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'A']);
        $subject = $tenant->subjects()->create(['name' => 'Math']);

        $admin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $absentTeacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $absentTeacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        $substituteTeacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $substituteTeacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        $lesson = new Lesson([
            'academic_year_id' => $year->id, 'school_class_id' => $class->id,
            'subject_id' => $subject->id, 'teacher_id' => $absentTeacher->id,
            'day_of_week' => $dayOfWeek, 'starts_at' => '09:00:00', 'ends_at' => '09:45:00',
            'status' => Lesson::STATUS_PUBLISHED,
        ]);
        $lesson->tenant_id = $tenant->id;
        $lesson->save();

        return compact('tenant', 'admin', 'absentTeacher', 'substituteTeacher', 'lesson', 'dayOfWeek', 'today');
    }

    public function test_admin_can_report_an_absence_and_assign_a_substitute_who_then_sees_it_on_their_dashboard(): void
    {
        $f = $this->baseFixtures();

        $this->actingAs($f['admin'])->post('/portal/substitutions/absences', [
            'user_id' => $f['absentTeacher']->id,
            'starts_on' => $f['today']->toDateString(),
            'ends_on' => $f['today']->toDateString(),
            'reason' => 'ავადმყოფობა',
        ])->assertRedirect(route('substitutions.index'));

        $absence = StaffAbsence::query()->where('tenant_id', $f['tenant']->id)->firstOrFail();
        $this->assertSame(StaffAbsence::STATUS_REPORTED, $absence->status);

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $f['tenant']->id,
            'action' => 'staff_absence.reported',
            'subject_id' => $absence->id,
        ]);

        // The admin's worklist should now list this lesson as needing coverage.
        $this->actingAs($f['admin'])->get('/portal/substitutions')->assertInertia(fn ($page) => $page
            ->component('portal/substitutions/index')
            ->where('coverageNeeded.0.lessonId', $f['lesson']->id)
        );

        $this->actingAs($f['admin'])->post('/portal/substitutions/assign', [
            'lesson_id' => $f['lesson']->id,
            'substitute_teacher_id' => $f['substituteTeacher']->id,
            'date' => $f['today']->toDateString(),
            'absence_id' => $absence->id,
        ])->assertRedirect(route('substitutions.index'));

        $assignment = SubstitutionAssignment::query()->where('tenant_id', $f['tenant']->id)->firstOrFail();
        $this->assertSame(SubstitutionAssignment::STATUS_ASSIGNED, $assignment->status);
        $this->assertSame($f['substituteTeacher']->id, $assignment->substitute_teacher_id);
        $this->assertSame($f['absentTeacher']->id, $assignment->absent_teacher_id);

        $this->assertSame(StaffAbsence::STATUS_COVERED, $absence->fresh()->status);

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $f['tenant']->id,
            'action' => 'substitution.assigned',
            'subject_id' => $assignment->id,
        ]);

        // The substitute teacher's own "today" dashboard now shows the coverage.
        $this->actingAs($f['substituteTeacher'])->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->component('portal/teacher-dashboard')
            ->has('substitutions', 1)
            ->where('substitutions.0.lessonId', $f['lesson']->id)
            ->where('substitutions.0.subject', 'Math')
            ->where('substitutions.0.absentTeacherName', $f['absentTeacher']->name)
        );

        // The absent teacher's own dashboard is unaffected.
        $this->actingAs($f['absentTeacher'])->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->component('portal/teacher-dashboard')
            ->has('substitutions', 0)
        );
    }

    public function test_assigning_a_teacher_who_already_has_a_lesson_at_that_exact_time_is_rejected(): void
    {
        $f = $this->baseFixtures();

        // The candidate substitute already has their own published lesson
        // at the exact same day-of-week + time slot, in a different class.
        $otherClass = $f['tenant']->schoolClasses()->create(['academic_year_id' => $f['lesson']->academic_year_id, 'name' => 'B']);
        $busyLesson = new Lesson([
            'academic_year_id' => $f['lesson']->academic_year_id, 'school_class_id' => $otherClass->id,
            'subject_id' => $f['lesson']->subject_id, 'teacher_id' => $f['substituteTeacher']->id,
            'day_of_week' => $f['dayOfWeek'], 'starts_at' => '09:00:00', 'ends_at' => '09:45:00',
            'status' => Lesson::STATUS_PUBLISHED,
        ]);
        $busyLesson->tenant_id = $f['tenant']->id;
        $busyLesson->save();

        $response = $this->actingAs($f['admin'])->post('/portal/substitutions/assign', [
            'lesson_id' => $f['lesson']->id,
            'substitute_teacher_id' => $f['substituteTeacher']->id,
            'date' => $f['today']->toDateString(),
        ]);

        $response->assertSessionHasErrors('substitute_teacher_id');
        $this->assertDatabaseCount('substitution_assignments', 0);
    }

    public function test_a_teacher_cannot_report_absences_or_assign_substitutes(): void
    {
        $f = $this->baseFixtures();

        $this->actingAs($f['absentTeacher'])->get('/portal/substitutions')->assertForbidden();

        $this->actingAs($f['absentTeacher'])->post('/portal/substitutions/absences', [
            'user_id' => $f['absentTeacher']->id,
            'starts_on' => $f['today']->toDateString(),
            'ends_on' => $f['today']->toDateString(),
        ])->assertForbidden();

        $this->actingAs($f['absentTeacher'])->post('/portal/substitutions/assign', [
            'lesson_id' => $f['lesson']->id,
            'substitute_teacher_id' => $f['substituteTeacher']->id,
            'date' => $f['today']->toDateString(),
        ])->assertForbidden();

        $this->assertDatabaseCount('staff_absences', 0);
        $this->assertDatabaseCount('substitution_assignments', 0);
    }

    public function test_a_tenants_admin_cannot_assign_a_substitute_against_another_tenants_lesson(): void
    {
        $f = $this->baseFixtures();

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $otherTenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $otherClass = $otherTenant->schoolClasses()->create(['academic_year_id' => $otherYear->id, 'name' => 'A']);
        $otherSubject = $otherTenant->subjects()->create(['name' => 'Physics']);
        $otherTeacher = User::factory()->create();

        $otherLesson = new Lesson([
            'academic_year_id' => $otherYear->id, 'school_class_id' => $otherClass->id,
            'subject_id' => $otherSubject->id, 'teacher_id' => $otherTeacher->id,
            'day_of_week' => $f['dayOfWeek'], 'starts_at' => '09:00:00', 'ends_at' => '09:45:00',
            'status' => Lesson::STATUS_PUBLISHED,
        ]);
        $otherLesson->tenant_id = $otherTenant->id;
        $otherLesson->save();

        $this->actingAs($f['admin'])->post('/portal/substitutions/assign', [
            'lesson_id' => $otherLesson->id,
            'substitute_teacher_id' => $f['substituteTeacher']->id,
            'date' => $f['today']->toDateString(),
        ])->assertNotFound();

        $this->assertDatabaseCount('substitution_assignments', 0);
    }

    public function test_a_tenants_admin_cannot_cancel_another_tenants_substitution_assignment(): void
    {
        $f = $this->baseFixtures();

        $otherTenant = Tenant::create(['slug' => 'other-school-2', 'name' => 'Other 2', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $otherTenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $otherClass = $otherTenant->schoolClasses()->create(['academic_year_id' => $otherYear->id, 'name' => 'A']);
        $otherSubject = $otherTenant->subjects()->create(['name' => 'Physics']);
        $otherAbsentTeacher = User::factory()->create();
        $otherSubstituteTeacher = User::factory()->create();

        $otherLesson = new Lesson([
            'academic_year_id' => $otherYear->id, 'school_class_id' => $otherClass->id,
            'subject_id' => $otherSubject->id, 'teacher_id' => $otherAbsentTeacher->id,
            'day_of_week' => $f['dayOfWeek'], 'starts_at' => '09:00:00', 'ends_at' => '09:45:00',
            'status' => Lesson::STATUS_PUBLISHED,
        ]);
        $otherLesson->tenant_id = $otherTenant->id;
        $otherLesson->save();

        $otherAssignment = new SubstitutionAssignment([
            'lesson_id' => $otherLesson->id,
            'absent_teacher_id' => $otherAbsentTeacher->id,
            'substitute_teacher_id' => $otherSubstituteTeacher->id,
            'date' => $f['today']->toDateString(),
            'status' => SubstitutionAssignment::STATUS_ASSIGNED,
        ]);
        $otherAssignment->tenant_id = $otherTenant->id;
        $otherAssignment->save();

        // The request resolves to $f['tenant'] (the "localhost" domain);
        // $f['admin'] holds admin there, but the assignment id belongs to a
        // different tenant entirely — the tenant-scoped global query on
        // route-model binding must not find it.
        $this->actingAs($f['admin'])->post("/portal/substitutions/{$otherAssignment->id}/cancel")->assertNotFound();

        $this->assertDatabaseHas('substitution_assignments', [
            'id' => $otherAssignment->id,
            'status' => SubstitutionAssignment::STATUS_ASSIGNED,
        ]);
    }
}
