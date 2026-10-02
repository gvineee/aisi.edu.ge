<?php

namespace Tests\Feature\Assessment;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Assessment\Models\CompetencyLevel;
use App\Domain\Assessment\Models\StudentCompetencyObservation;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Competencies: management-defined framework, teacher observations only for
 * their own class, families see only shared observations.
 */
class CompetencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_framework_observations_and_family_visibility(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VII']);

        $u = [];
        foreach (['manager' => TenantMembership::ROLE_ACADEMIC_MANAGER, 'teacher' => TenantMembership::ROLE_TEACHER, 'other' => TenantMembership::ROLE_TEACHER, 'guardian' => TenantMembership::ROLE_GUARDIAN] as $k => $role) {
            $u[$k] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u[$k]->id, 'role' => $role, 'is_active' => true]);
        }
        $ta = $tenant->teacherAssignments()->create(['user_id' => $u['teacher']->id, 'school_class_id' => $class->id, 'subject' => 'მათემატიკა']);
        $otherTa = $tenant->teacherAssignments()->create(['user_id' => $u['other']->id, 'school_class_id' => $otherClass->id, 'subject' => 'ისტორია']);
        $student = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);
        $link = new GuardianLink(['user_id' => $u['guardian']->id, 'student_id' => $student->id, 'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();

        // Only management defines the framework; at least two levels.
        $this->actingAs($u['teacher'])->post('/portal/competencies', ['code' => 'C1', 'name' => 'კრიტიკული აზროვნება', 'levels' => [['label' => 'a'], ['label' => 'b']]])->assertForbidden();
        $this->actingAs($u['manager'])->post('/portal/competencies', ['code' => 'C1', 'name' => 'x', 'levels' => [['label' => 'ერთი']]])->assertSessionHasErrors('levels');
        $this->actingAs($u['manager'])->post('/portal/competencies', ['code' => 'C1', 'name' => 'კრიტიკული აზროვნება', 'levels' => [['label' => 'საწყისი'], ['label' => 'განვითარებადი'], ['label' => 'დამოუკიდებელი']]])->assertSessionHasNoErrors();
        $level = CompetencyLevel::query()->where('level', 2)->firstOrFail();

        // Teacher records for own class only.
        $this->actingAs($u['other'])->post('/portal/competencies/observations', ['teacher_assignment_id' => $ta->id, 'student_id' => $student->id, 'competency_level_id' => $level->id, 'observed_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($u['other'])->post('/portal/competencies/observations', ['teacher_assignment_id' => $otherTa->id, 'student_id' => $student->id, 'competency_level_id' => $level->id, 'observed_on' => now()->toDateString()])->assertSessionHasErrors('student_id');

        $this->actingAs($u['teacher'])->post('/portal/competencies/observations', ['teacher_assignment_id' => $ta->id, 'student_id' => $student->id, 'competency_level_id' => $level->id, 'observed_on' => now()->toDateString(), 'note' => 'არგუმენტირებს კარგად'])->assertSessionHasNoErrors();
        $this->assertSame(1, StudentCompetencyObservation::query()->count());
        $this->assertTrue(AuditEvent::query()->where('action', 'competency.observed')->exists());

        // Not shared yet: family sees nothing, the teacher does.
        $this->actingAs($u['guardian'])->get("/portal/students/{$student->id}/progress")->assertInertia(fn (Assert $page) => $page->where('competencies', []));
        $this->actingAs($u['teacher'])->get("/portal/students/{$student->id}/progress")->assertInertia(fn (Assert $page) => $page->where('competencies.0.label', 'განვითარებადი'));

        $this->actingAs($u['teacher'])->post('/portal/competencies/observations', ['teacher_assignment_id' => $ta->id, 'student_id' => $student->id, 'competency_level_id' => $level->id, 'observed_on' => now()->toDateString(), 'shared_with_family' => true]);
        $this->actingAs($u['guardian'])->get("/portal/students/{$student->id}/progress")->assertInertia(fn (Assert $page) => $page->where('competencies.0.shared', true));
    }
}
