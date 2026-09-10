<?php

namespace Tests\Feature\Portal;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Documents\Actions\CreateDraftDocument;
use App\Domain\Documents\Actions\SubmitForReview;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the student/director/admin dashboard branches and the multi-role
 * switch mechanism added alongside them. ParentDashboardTest and the
 * Timetable/Documents suites cover the guardian/teacher branches and the
 * underlying business logic — this file only covers what changed here.
 */
class DashboardRolesTest extends TestCase
{
    use RefreshDatabase;

    private function localTenant(): Tenant
    {
        return TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
    }

    /**
     * @return array{class: SchoolClass, subject: Subject}
     */
    private function makeClassWithLesson(Tenant $tenant, string $subjectName, int $dayOfWeek): array
    {
        $year = $tenant->academicYears()->firstOrCreate(
            ['name' => '2026-2027'],
            ['starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true],
        );
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => uniqid('class-', true)]);
        $subject = $tenant->subjects()->create(['name' => $subjectName]);
        $teacher = User::factory()->create();

        $lesson = new Lesson([
            'academic_year_id' => $year->id, 'school_class_id' => $class->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'day_of_week' => $dayOfWeek, 'starts_at' => '09:00:00', 'ends_at' => '09:45:00',
            'status' => Lesson::STATUS_PUBLISHED,
        ]);
        $lesson->tenant_id = $tenant->id;
        $lesson->save();

        return compact('class', 'subject');
    }

    public function test_a_student_sees_only_their_own_class_schedule(): void
    {
        $tenant = $this->localTenant();
        $dayOfWeek = now($tenant->timezone)->dayOfWeekIso;

        ['class' => $myClass] = $this->makeClassWithLesson($tenant, 'Chemistry', $dayOfWeek);
        $this->makeClassWithLesson($tenant, 'Physics', $dayOfWeek);

        $studentUser = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $studentUser->id,
            'role' => TenantMembership::ROLE_STUDENT, 'is_active' => true,
        ]);
        $tenant->students()->create([
            'school_class_id' => $myClass->id, 'user_id' => $studentUser->id,
            'first_name' => 'Test', 'last_name' => 'Student', 'is_active' => true,
        ]);

        $response = $this->actingAs($studentUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/student-dashboard')
            ->where('linked', true)
            ->has('todaySchedule', 1)
            ->where('todaySchedule.0.subject', 'Chemistry')
        );
        $response->assertDontSee('Physics');
    }

    public function test_a_student_role_without_a_linked_student_record_gets_an_honest_state(): void
    {
        $tenant = $this->localTenant();
        $studentUser = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $studentUser->id,
            'role' => TenantMembership::ROLE_STUDENT, 'is_active' => true,
        ]);

        $response = $this->actingAs($studentUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/student-dashboard')
            ->where('linked', false)
            ->has('todaySchedule', 0)
        );
    }

    public function test_a_students_schedule_never_leaks_a_different_tenants_lesson(): void
    {
        $tenant = $this->localTenant();
        $dayOfWeek = now($tenant->timezone)->dayOfWeekIso;
        ['class' => $myClass] = $this->makeClassWithLesson($tenant, 'Georgian', $dayOfWeek);

        $otherTenant = Tenant::create([
            'slug' => 'other-school', 'name' => 'Other School', 'locale' => 'en', 'timezone' => 'UTC', 'is_active' => true,
        ]);
        $this->makeClassWithLesson($otherTenant, 'SecretOtherTenantSubject', $dayOfWeek);

        $studentUser = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $studentUser->id,
            'role' => TenantMembership::ROLE_STUDENT, 'is_active' => true,
        ]);
        $tenant->students()->create([
            'school_class_id' => $myClass->id, 'user_id' => $studentUser->id,
            'first_name' => 'Test', 'last_name' => 'Student', 'is_active' => true,
        ]);

        $response = $this->actingAs($studentUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('SecretOtherTenantSubject');
    }

    public function test_director_dashboard_shows_the_real_pending_approval_count(): void
    {
        Storage::fake('local');
        $tenant = $this->localTenant();

        $director = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $director->id,
            'role' => TenantMembership::ROLE_DIRECTOR, 'is_active' => true,
        ]);

        $teacher = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $teacher->id,
            'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true,
        ]);

        $workspace = $tenant->documentWorkspaces()->create([
            'title' => 'Test workspace', 'classification' => DocumentWorkspace::CLASSIFICATION_CURRICULUM,
        ]);

        $document = app(CreateDraftDocument::class)->handle(
            tenantId: $tenant->id,
            workspace: $workspace,
            owner: $teacher,
            type: 'lesson_plan',
            title: 'Pending doc',
            file: UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf'),
        );
        app(SubmitForReview::class)->handle($document, $teacher);

        $response = $this->actingAs($director)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/director-dashboard')
            ->where('pendingCount', 1)
            ->has('preview', 1)
            ->where('preview.0.documentTitle', 'Pending doc')
        );
    }

    public function test_admin_dashboard_shows_a_real_member_count(): void
    {
        $tenant = $this->localTenant();

        $admin = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $admin->id,
            'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true,
        ]);
        $teacher = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $teacher->id,
            'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/admin-dashboard')
            ->where('memberCount', 2)
        );
    }

    public function test_a_multi_role_user_can_switch_their_active_dashboard_role(): void
    {
        $tenant = $this->localTenant();

        $user = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true,
        ]);
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true,
        ]);

        // Admin outranks teacher in the default priority order, so it's the
        // default landing view for a brand-new session.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->component('portal/admin-dashboard'));

        $this->actingAs($user)
            ->post(route('portal.active-role.update'), ['role' => TenantMembership::ROLE_TEACHER])
            ->assertRedirect(route('dashboard'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->component('portal/teacher-dashboard'));
    }

    public function test_switching_to_a_role_the_user_does_not_actually_hold_is_rejected(): void
    {
        $tenant = $this->localTenant();

        $user = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('portal.active-role.update'), ['role' => TenantMembership::ROLE_DIRECTOR])
            ->assertForbidden();
    }

    public function test_a_user_with_no_active_role_still_gets_an_honest_empty_state(): void
    {
        $user = User::factory()->create(['name' => 'Still No Role']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('portal/no-role'));
    }
}
