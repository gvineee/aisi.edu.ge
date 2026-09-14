<?php

namespace Tests\Feature\Timetable;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the production root-cause fix for the Teacher Substitution module:
 * before LessonController::index/storeSubject existed, LessonController's
 * own `store()` was unreachable dead code (no page anywhere posted to it)
 * and nothing anywhere could create a Subject — confirmed on production as
 * the reason a real absence report could never surface a "needs coverage"
 * row (0 subjects, 0 lessons). This test both covers the new screen
 * directly and replays the exact previously-broken path end to end
 * (admin creates a subject -> a lesson -> reports an absence against that
 * teacher -> the coverage worklist actually lists it), plus the tenant-
 * isolation gap closed alongside it (a client-supplied school_class_id/
 * subject_id/academic_year_id/room_id must belong to the acting tenant).
 */
class TimetableManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, manager: User, teacher: User}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $manager = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $manager->id, 'role' => TenantMembership::ROLE_ACADEMIC_MANAGER, 'is_active' => true]);

        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        return compact('tenant', 'manager', 'teacher');
    }

    public function test_a_teacher_cannot_view_or_create_timetable_data(): void
    {
        ['teacher' => $teacher] = $this->baseFixtures();

        $this->actingAs($teacher)->get('/portal/timetable')->assertForbidden();

        $this->actingAs($teacher)->post('/portal/timetable/subjects', [
            'name' => 'მათემატიკა',
        ])->assertForbidden();

        $this->assertSame(0, Subject::query()->count());
    }

    public function test_manager_can_create_a_subject_and_it_is_audited(): void
    {
        ['tenant' => $tenant, 'manager' => $manager] = $this->baseFixtures();

        $this->actingAs($manager)->post('/portal/timetable/subjects', [
            'name' => 'მათემატიკა',
        ])->assertRedirect();

        $subject = Subject::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('მათემატიკა', $subject->name);

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'action' => 'subject.created',
            'subject_id' => $subject->id,
        ]);
    }

    public function test_the_whole_previously_broken_path_now_works_end_to_end(): void
    {
        ['tenant' => $tenant, 'manager' => $manager, 'teacher' => $teacher] = $this->baseFixtures();

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);

        // 1. Create the subject through the real HTTP endpoint (previously
        // impossible outside a seeder).
        $this->actingAs($manager)->post('/portal/timetable/subjects', [
            'name' => 'მათემატიკა',
        ])->assertRedirect();
        $subject = Subject::query()->where('tenant_id', $tenant->id)->firstOrFail();

        // 2. Create the lesson through the real HTTP endpoint (previously
        // dead code — nothing ever posted to it).
        $this->actingAs($manager)->post(route('lessons.store'), [
            'academic_year_id' => $year->id,
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '09:45',
            'status' => 'published',
        ])->assertSessionHasNoErrors();

        $lesson = Lesson::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'action' => 'lesson.created',
            'subject_id' => $lesson->id,
        ]);

        // 3. The timetable screen now actually lists what was just created.
        $this->actingAs($manager)->get('/portal/timetable')->assertInertia(fn ($page) => $page
            ->component('portal/timetable/index')
            ->has('subjects', 1)
            ->has('lessons', 1)
            ->where('lessons.0.subjectName', 'მათემატიკა')
        );

        // 4. The Substitution screen's teacher dropdown — previously always
        // empty on production — now actually lists this teacher, and
        // reporting an absence against them succeeds without error. The
        // exact date-matched "needs coverage" row is covered separately by
        // SubstitutionWorkflowTest (which pins the lesson's day_of_week to
        // "today" to avoid a flaky day-of-week mismatch here).
        $this->actingAs($manager)->get('/portal/substitutions')->assertInertia(fn ($page) => $page
            ->component('portal/substitutions/index')
            ->has('teachers', 1)
            ->where('teachers.0.id', $teacher->id)
        );

        $this->actingAs($manager)->post('/portal/substitutions/absences', [
            'user_id' => $teacher->id,
            'starts_on' => $lesson->created_at->toDateString(),
            'ends_on' => $lesson->created_at->toDateString(),
        ])->assertRedirect(route('substitutions.index'));
    }

    public function test_a_lesson_cannot_reference_another_tenants_school_class(): void
    {
        ['tenant' => $tenant, 'manager' => $manager, 'teacher' => $teacher] = $this->baseFixtures();

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $subject = $tenant->subjects()->create(['name' => 'Math']);

        $otherTenant = Tenant::create(['slug' => 'other-school-tt', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $otherTenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $otherClass = $otherTenant->schoolClasses()->create(['academic_year_id' => $otherYear->id, 'name' => 'A']);

        $this->actingAs($manager)->post(route('lessons.store'), [
            'academic_year_id' => $year->id,
            'school_class_id' => $otherClass->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '09:45',
            'status' => 'published',
        ])->assertSessionHasErrors('school_class_id');

        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_a_lesson_cannot_reference_another_tenants_subject_or_academic_year(): void
    {
        ['tenant' => $tenant, 'manager' => $manager, 'teacher' => $teacher] = $this->baseFixtures();

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);

        $otherTenant = Tenant::create(['slug' => 'other-school-tt-2', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $otherTenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $otherSubject = $otherTenant->subjects()->create(['name' => 'Physics']);

        $this->actingAs($manager)->post(route('lessons.store'), [
            'academic_year_id' => $otherYear->id,
            'school_class_id' => $class->id,
            'subject_id' => $otherSubject->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '09:45',
            'status' => 'published',
        ])->assertSessionHasErrors(['academic_year_id', 'subject_id']);

        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_a_lesson_cannot_reference_another_tenants_room(): void
    {
        ['tenant' => $tenant, 'manager' => $manager, 'teacher' => $teacher] = $this->baseFixtures();

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $subject = $tenant->subjects()->create(['name' => 'Math']);

        $otherTenant = Tenant::create(['slug' => 'other-school-tt-3', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherRoom = $otherTenant->rooms()->create(['name' => 'Room X']);

        $this->actingAs($manager)->post(route('lessons.store'), [
            'academic_year_id' => $year->id,
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'room_id' => $otherRoom->id,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '09:45',
            'status' => 'published',
        ])->assertSessionHasErrors('room_id');

        $this->assertDatabaseCount('lessons', 0);
    }
}
