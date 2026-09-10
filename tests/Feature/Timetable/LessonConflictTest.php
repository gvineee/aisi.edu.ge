<?php

namespace Tests\Feature\Timetable;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers docs/02 critical check #4: overlapping teacher/room/class time is
 * blocked server-side; a touching boundary ([start, end)) is not a conflict.
 */
class LessonConflictTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $manager = User::factory()->create();

        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $manager->id,
            'role' => TenantMembership::ROLE_ACADEMIC_MANAGER, 'is_active' => true,
        ]);

        return $manager;
    }

    /**
     * @return array{tenant: Tenant, year: mixed, classA: mixed, classB: mixed, subject: mixed, room: mixed, teacher: User}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $classA = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'A']);
        $classB = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'B']);
        $subject = $tenant->subjects()->create(['name' => 'Math']);
        $room = $tenant->rooms()->create(['name' => 'Room 1']);
        $teacher = User::factory()->create();

        return compact('tenant', 'year', 'classA', 'classB', 'subject', 'room', 'teacher');
    }

    public function test_the_same_teacher_cannot_have_two_overlapping_lessons(): void
    {
        $f = $this->baseFixtures();
        $manager = $this->makeManager();

        $payload = fn (array $overrides) => array_merge([
            'academic_year_id' => $f['year']->id,
            'school_class_id' => $f['classA']->id,
            'subject_id' => $f['subject']->id,
            'teacher_id' => $f['teacher']->id,
            'room_id' => $f['room']->id,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '09:45',
            'status' => 'published',
        ], $overrides);

        $this->actingAs($manager)->post(route('lessons.store'), $payload([]))
            ->assertSessionHasNoErrors();

        // Overlaps 09:00-09:45, different class, same teacher.
        $this->actingAs($manager)->post(route('lessons.store'), $payload([
            'school_class_id' => $f['classB']->id,
            'starts_at' => '09:30',
            'ends_at' => '10:15',
        ]))->assertSessionHasErrors('starts_at');

        $this->assertDatabaseCount('lessons', 1);
    }

    public function test_the_same_room_cannot_be_double_booked(): void
    {
        $f = $this->baseFixtures();
        $manager = $this->makeManager();
        $otherTeacher = User::factory()->create();

        $this->actingAs($manager)->post(route('lessons.store'), [
            'academic_year_id' => $f['year']->id, 'school_class_id' => $f['classA']->id,
            'subject_id' => $f['subject']->id, 'teacher_id' => $f['teacher']->id,
            'room_id' => $f['room']->id, 'day_of_week' => 1,
            'starts_at' => '09:00', 'ends_at' => '09:45', 'status' => 'published',
        ])->assertSessionHasNoErrors();

        $this->actingAs($manager)->post(route('lessons.store'), [
            'academic_year_id' => $f['year']->id, 'school_class_id' => $f['classB']->id,
            'subject_id' => $f['subject']->id, 'teacher_id' => $otherTeacher->id,
            'room_id' => $f['room']->id, 'day_of_week' => 1,
            'starts_at' => '09:15', 'ends_at' => '10:00', 'status' => 'published',
        ])->assertSessionHasErrors('starts_at');
    }

    public function test_a_lesson_that_starts_exactly_when_another_ends_is_not_a_conflict(): void
    {
        $f = $this->baseFixtures();
        $manager = $this->makeManager();

        $this->actingAs($manager)->post(route('lessons.store'), [
            'academic_year_id' => $f['year']->id, 'school_class_id' => $f['classA']->id,
            'subject_id' => $f['subject']->id, 'teacher_id' => $f['teacher']->id,
            'room_id' => $f['room']->id, 'day_of_week' => 1,
            'starts_at' => '09:00', 'ends_at' => '09:45', 'status' => 'published',
        ])->assertSessionHasNoErrors();

        // Same teacher, same room, but starts exactly when the first ends.
        $this->actingAs($manager)->post(route('lessons.store'), [
            'academic_year_id' => $f['year']->id, 'school_class_id' => $f['classA']->id,
            'subject_id' => $f['subject']->id, 'teacher_id' => $f['teacher']->id,
            'room_id' => $f['room']->id, 'day_of_week' => 1,
            'starts_at' => '09:45', 'ends_at' => '10:30', 'status' => 'published',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('lessons', 2);
    }

    public function test_a_different_day_of_week_never_conflicts(): void
    {
        $f = $this->baseFixtures();
        $manager = $this->makeManager();

        $this->actingAs($manager)->post(route('lessons.store'), [
            'academic_year_id' => $f['year']->id, 'school_class_id' => $f['classA']->id,
            'subject_id' => $f['subject']->id, 'teacher_id' => $f['teacher']->id,
            'room_id' => $f['room']->id, 'day_of_week' => 1,
            'starts_at' => '09:00', 'ends_at' => '09:45', 'status' => 'published',
        ])->assertSessionHasNoErrors();

        $this->actingAs($manager)->post(route('lessons.store'), [
            'academic_year_id' => $f['year']->id, 'school_class_id' => $f['classA']->id,
            'subject_id' => $f['subject']->id, 'teacher_id' => $f['teacher']->id,
            'room_id' => $f['room']->id, 'day_of_week' => 2,
            'starts_at' => '09:00', 'ends_at' => '09:45', 'status' => 'published',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('lessons', 2);
    }

    public function test_only_academic_manager_or_admin_can_create_lessons(): void
    {
        $f = $this->baseFixtures();
        $randomUser = User::factory()->create();

        $this->actingAs($randomUser)->post(route('lessons.store'), [
            'academic_year_id' => $f['year']->id, 'school_class_id' => $f['classA']->id,
            'subject_id' => $f['subject']->id, 'teacher_id' => $f['teacher']->id,
            'room_id' => $f['room']->id, 'day_of_week' => 1,
            'starts_at' => '09:00', 'ends_at' => '09:45', 'status' => 'published',
        ])->assertForbidden();
    }
}
