<?php

namespace Tests\Feature\Portal;

use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers docs/02 critical check #3 at the data-access-pattern level: a
 * teacher's own assignment query must never surface another teacher's
 * class, even within the same tenant. (The gradebook/attendance UI that
 * will consume this scoping doesn't exist yet — this locks in the query
 * pattern those features must use once built.)
 */
class TeacherAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teachers_assignment_query_never_returns_another_teachers_class(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);

        $classA = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'A']);
        $classB = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'B']);

        $teacherA = User::factory()->create();
        $teacherB = User::factory()->create();

        $tenant->teacherAssignments()->create([
            'user_id' => $teacherA->id, 'school_class_id' => $classA->id, 'subject' => 'Math',
        ]);
        $tenant->teacherAssignments()->create([
            'user_id' => $teacherB->id, 'school_class_id' => $classB->id, 'subject' => 'Math',
        ]);

        $teacherAsClasses = TeacherAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $teacherA->id)
            ->pluck('school_class_id');

        $this->assertEquals([$classA->id], $teacherAsClasses->all());
        $this->assertNotContains($classB->id, $teacherAsClasses->all());
    }
}
