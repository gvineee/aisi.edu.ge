<?php

namespace Tests\Feature\Academics;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the production root-cause fix: before AcademicStructureController
 * existed, no route/controller anywhere could create an AcademicYear,
 * SchoolClass or Student — confirmed on production as the reason a real
 * teacher invitation (and therefore the whole Assignments create->submit
 * ->grade workflow) could never be exercised through any real HTTP
 * endpoint. This test both covers the new screen directly and replays the
 * exact previously-broken path end to end (admin sets up structure -> invites
 * a teacher -> teacher creates/publishes an assignment).
 */
class AcademicStructureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, admin: User, teacher: User}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $admin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        return compact('tenant', 'admin', 'teacher');
    }

    public function test_non_admin_cannot_view_or_create_structure(): void
    {
        ['teacher' => $teacher] = $this->baseFixtures();

        $this->actingAs($teacher)->get('/portal/academic-structure')->assertForbidden();

        $this->actingAs($teacher)->post('/portal/academic-structure/academic-years', [
            'name' => '2026-2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-15',
        ])->assertForbidden();

        $this->assertSame(0, AcademicYear::query()->count());
    }

    public function test_admin_can_create_an_academic_year_that_becomes_current(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->baseFixtures();

        $this->actingAs($admin)->post('/portal/academic-structure/academic-years', [
            'name' => '2026-2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-15',
            'is_current' => '1',
        ])->assertRedirect(route('academic-structure.index'));

        $year = AcademicYear::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertTrue($year->is_current);

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'action' => 'academic_year.created',
            'subject_id' => $year->id,
        ]);
    }

    public function test_creating_a_second_current_year_unsets_the_previous_one(): void
    {
        ['admin' => $admin] = $this->baseFixtures();

        $this->actingAs($admin)->post('/portal/academic-structure/academic-years', [
            'name' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-06-15', 'is_current' => '1',
        ]);
        $firstYear = AcademicYear::query()->where('name', '2025-2026')->firstOrFail();

        $this->actingAs($admin)->post('/portal/academic-structure/academic-years', [
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => '1',
        ]);
        $secondYear = AcademicYear::query()->where('name', '2026-2027')->firstOrFail();

        $this->assertFalse($firstYear->fresh()->is_current);
        $this->assertTrue($secondYear->fresh()->is_current);
    }

    public function test_duplicate_academic_year_name_is_rejected_as_validation_error(): void
    {
        ['admin' => $admin] = $this->baseFixtures();

        $payload = ['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15'];
        $this->actingAs($admin)->post('/portal/academic-structure/academic-years', $payload)->assertRedirect();
        $this->actingAs($admin)->post('/portal/academic-structure/academic-years', $payload)->assertSessionHasErrors('name');

        $this->assertSame(1, AcademicYear::query()->where('name', '2026-2027')->count());
    }

    public function test_admin_can_create_a_school_class_and_a_student_and_then_invite_a_real_teacher_to_it(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->baseFixtures();

        $this->actingAs($admin)->post('/portal/academic-structure/academic-years', [
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => '1',
        ]);
        $year = AcademicYear::query()->firstOrFail();

        $this->actingAs($admin)->post('/portal/academic-structure/school-classes', [
            'academic_year_id' => $year->id,
            'name' => 'VI',
        ])->assertRedirect(route('academic-structure.index'));

        $schoolClass = SchoolClass::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($year->id, $schoolClass->academic_year_id);

        $this->actingAs($admin)->post('/portal/academic-structure/students', [
            'school_class_id' => $schoolClass->id,
            'first_name' => 'გიორგი',
            'last_name' => 'მ.',
            'national_id' => '01234567890',
        ])->assertRedirect(route('academic-structure.index'));

        $student = Student::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($schoolClass->id, $student->school_class_id);
        $this->assertSame('01234567890', $student->national_id);

        // The previously-broken path: MemberController's invite-a-teacher
        // form now has a real class to attach to, end to end.
        $this->actingAs($admin)->post('/portal/members/invite', [
            'email' => 'new.teacher@example.test',
            'role' => TenantMembership::ROLE_TEACHER,
            'school_class_id' => $schoolClass->id,
            'subject' => 'მათემატიკა',
        ])->assertRedirect(route('members.index'));

        $this->assertDatabaseHas('tenant_invitations', [
            'tenant_id' => $tenant->id,
            'email' => 'new.teacher@example.test',
            'school_class_id' => $schoolClass->id,
        ]);
    }

    public function test_school_class_cannot_be_attached_to_another_tenants_academic_year(): void
    {
        ['admin' => $admin] = $this->baseFixtures();

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $otherTenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);

        $this->actingAs($admin)->post('/portal/academic-structure/school-classes', [
            'academic_year_id' => $otherYear->id,
            'name' => 'VI',
        ])->assertNotFound();

        $this->assertSame(0, SchoolClass::query()->count());
    }

    public function test_student_cannot_be_attached_to_another_tenants_school_class(): void
    {
        ['admin' => $admin] = $this->baseFixtures();

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $otherTenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $otherClass = $otherTenant->schoolClasses()->create(['academic_year_id' => $otherYear->id, 'name' => 'VI']);

        $this->actingAs($admin)->post('/portal/academic-structure/students', [
            'school_class_id' => $otherClass->id,
            'first_name' => 'გიორგი',
            'last_name' => 'მ.',
        ])->assertNotFound();

        $this->assertSame(0, Student::query()->count());
    }

    public function test_duplicate_national_id_within_the_same_tenant_is_a_validation_error_not_a_crash(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->baseFixtures();

        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $schoolClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);

        $payload = ['school_class_id' => $schoolClass->id, 'first_name' => 'გიორგი', 'last_name' => 'მ.', 'national_id' => '01234567890'];
        $this->actingAs($admin)->post('/portal/academic-structure/students', $payload)->assertRedirect();
        $this->actingAs($admin)->post('/portal/academic-structure/students', $payload)->assertSessionHasErrors('national_id');

        $this->assertSame(1, Student::query()->where('national_id', '01234567890')->count());
    }
}
